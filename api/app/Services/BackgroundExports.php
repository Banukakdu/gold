<?php

namespace App\Services;

use App\Events\ExportFinished;
use App\Exceptions\ExportFailure;
use App\Exports\XlsxStreamWriter;
use App\Jobs\RunExport;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Export;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\ReadSnapshot;
use App\Support\ReportParams;
use App\Support\Settings;
use App\Support\TransactionFilters;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * AG-087 background exports (docs/specs/background-exports.md): the request (R1, R7), the job (R2-R5, R8, R9), the
 * download (R6), prune (R10) and the audit rows (R11). Read-only towards the books: the file is built by the same
 * builders as the synchronous export (ReportExporter, TransactionExporter), inside one consistent snapshot.
 */
class BackgroundExports
{
    /** Section 2: the permissions each export needs, the same as its endpoint. */
    public const PERMISSIONS = [
        Export::KIND_REPORT => ['report.view', 'report.export'],
        Export::KIND_TRANSACTIONS => ['transaction.view', 'report.export'],
    ];

    /** Report filters kept for the job (validated again there by ReportService::params). `page` never matters. */
    public const REPORT_KEYS = ['from', 'to', 'branches', 'type', 'types', 'receipt', 'accounts', 'view', 'closing_entries'];

    public const NOT_READY = 'This export is not ready yet.';

    public const FAILED_NO_FILE = 'This export failed and has no file.';

    public const MISSING = 'This export file is missing. Run it again.';

    public const DAMAGED = 'This export file is damaged. Run it again.';

    public const NO_ACCESS = 'You no longer have access to this export.';

    public const STALE = 'The export did not run. Try again later.';

    public const TIMEOUT = 'The export took too long. Narrow the filters.';

    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private ReportService $reports,
        private ReportExporter $reportExporter,
        private TransactionSearch $search,
        private TransactionExporter $transactionExporter,
    ) {}

    public static function syncRows(): int
    {
        return (int) config('exports.sync_rows', 5000);
    }

    public static function maxRows(): int
    {
        return (int) config('exports.max_rows', 100000);
    }

    // ------------------------------------------------------------------ request (R1, R7)

    public function queueReport(Request $request, string $name, ReportParams $p, int $rows): JsonResponse
    {
        $params = Arr::only($request->except('format'), self::REPORT_KEYS);

        return $this->queue($request, Export::KIND_REPORT, $name, $params, $p->branchIds(), $rows);
    }

    public function queueTransactions(Request $request, TransactionFilters $p, int $rows): JsonResponse
    {
        return $this->queue($request, Export::KIND_TRANSACTIONS, 'transactions', $p->filters + ['sort' => $p->sort, 'dir' => $p->dir], $p->scopeIds(), $rows);
    }

    private function queue(Request $request, string $kind, string $name, array $params, array $branchIds, int $rows): JsonResponse
    {
        $user = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        $params = self::canonical($params);
        sort($branchIds);

        $export = DB::transaction(function () use ($user, $kind, $name, $params, $branchIds, $branchId, $rows, $request) {
            // One user's requests one at a time, so two at once cannot pass the limit together.
            User::whereKey($user->id)->lockForUpdate()->first();
            $active = Export::where('user_id', $user->id)->whereIn('status', Export::ACTIVE)->get();
            // Finding 9: only a queued twin is reused; a running one opened its snapshot before this request.
            $twin = $active->first(fn (Export $e) => $e->status === Export::QUEUED && $e->kind === $kind && $e->name === $name
                && $e->format === 'xlsx' && $e->branch_id === $branchId && self::canonical((array) $e->params) === $params);
            if ($twin) {
                return $twin;
            }
            $limit = (int) config('exports.per_user_active', 2);
            if ($active->count() >= $limit) {
                abort(response()->json(['message' => "You already have {$limit} exports being prepared. Wait for one to finish."], 429));
            }
            $export = Export::create([
                'user_id' => $user->id, 'branch_id' => $branchId, 'kind' => $kind, 'name' => $name, 'format' => 'xlsx',
                'params' => $params, 'branch_ids' => array_values(array_map('intval', $branchIds)), 'rows' => $rows,
                'status' => Export::QUEUED, 'requested_at' => now(), 'attempts' => 0,
                'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
            AuditLog::record('export.requested', null, null, $this->auditValues($export, ['branch_ids' => $export->branch_ids]), $export->auditBranchId());
            // The `exports` connection dispatches after commit, so the worker never sees an uncommitted row.
            RunExport::dispatch($export->id);

            return $export;
        });

        return response()->json([
            'message' => 'This export has '.number_format($export->rows).' rows, so it is being prepared in the background. We will email you when it is ready.',
            'export' => $this->present($export),
        ], 202);
    }

    // ------------------------------------------------------------------ job (R2-R5, R8, R9)

    public function run(RunExport $job): void
    {
        // R9: idempotent. A finished export (ready, failed, expired) is never built again. The move to running is
        // conditional (reviewer M2): prune may have failed the row as stale since the worker picked the job up.
        // started_at is this attempt's start, which prune uses to judge a running row (see staleRunningMinutes).
        $claimed = Export::whereKey($job->exportId)->whereIn('status', Export::ACTIVE)
            ->update(['status' => Export::RUNNING, 'started_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        $export = $claimed ? Export::find($job->exportId) : null;
        if (! $export) {
            return;
        }
        $disk = Storage::disk(Export::DISK);
        $ctx = app(BranchContext::class);
        $previous = [$ctx->branchId, $ctx->allBranches, getPermissionsTeamId()];
        $writer = null;

        try {
            $user = User::find($export->user_id);
            if ($this->accessProblem($export, $user) !== null) {
                throw ExportFailure::accessRevoked();
            }
            // R2: the stored branch context, never a current header.
            $ctx->set($export->branch_id, $export->branch_id === null);
            setPermissionsTeamId($export->branch_id);
            self::raiseMemoryLimit();

            $disk->makeDirectory((string) $export->user_id);
            $part = $export->partPath();
            $writer = new XlsxStreamWriter($disk->path($part));
            [$counts, $at] = ReadSnapshot::run(function (CarbonImmutable $at) use ($export, $user, $writer) {
                // Settings used in the header come from the snapshot too (finding 2).
                app(Settings::class)->flush();
                $extracted = self::extractedLine($at, $export->requested_at);

                return [$export->kind === Export::KIND_REPORT
                    ? $this->reportExporter->buildFile($export->name, $this->reports->params($export->name, (array) $export->params), $user, $writer, $extracted)
                    : $this->transactionExporter->buildFile($this->search->params((array) $export->params, export: true), $user, $writer, $extracted),
                    $at];
            });
            $writer->close();

            // Finding 5: never a ready file that is not complete.
            if ($problems = self::incompleteness($counts)) {
                Log::error("Export {$export->id} is incomplete; no file kept.", ['problems' => $problems]);
                throw ExportFailure::incomplete();
            }
            // R8
            clearstatcache();
            $size = (int) filesize($disk->path($part));
            if ($size > (int) config('exports.max_bytes')) {
                throw ExportFailure::tooLarge();
            }
            @chmod($disk->path($part), 0600);
            $final = $export->finalPath();
            $disk->move($part, $final);
            @chmod($disk->path($final), 0600);
            $sha = hash_file('sha256', $disk->path($final));

            $ready = now();
            // Reviewer M2: only a row this job still owns becomes ready. If prune (or a timeout) failed it meanwhile,
            // that outcome stands: the file goes, and no export.ready, event or email follows the failure.
            // Accountant C2: the move to ready and export.ready commit together.
            $owned = DB::transaction(function () use ($export, $counts, $at, $ready, $final, $size, $sha) {
                if (! Export::whereKey($export->id)->where('status', Export::RUNNING)->update([
                    'status' => Export::READY, 'rows' => $counts['rows'], 'data_as_at' => $at, 'ready_at' => $ready,
                    'expires_at' => $ready->copy()->addHours((int) config('exports.ttl_hours', 24)), 'file_path' => $final,
                    'size_bytes' => $size, 'sha256' => $sha, 'error' => null, 'failure_code' => null, 'updated_at' => $ready,
                ])) {
                    return false;
                }
                $export->refresh();
                $this->systemAudit('export.ready', $export, $this->auditValues($export, ['size_bytes' => $size, 'sha256' => $sha, 'data_as_at' => self::iso($export->data_as_at)]));

                return true;
            });
            if (! $owned) {
                $disk->delete($final);
                Log::warning("Export {$export->id} finished after it was marked {$export->fresh()?->status}; the file was discarded.");

                return;
            }
            ExportFinished::dispatch($export);
        } catch (ExportFailure $f) {
            $this->fail($export, $f->failureCode, $f->getMessage());
            $job->fail($f);
        } catch (ValidationException $v) {
            $this->fail($export, 'invalid', ExportFailure::invalid()->getMessage());
            $job->fail($v);
        } catch (HttpExceptionInterface $h) {
            // ReportService / TransactionSearch refuse a branch outside the stored context (403): access changed.
            $this->fail($export, 'access_revoked', ExportFailure::accessRevoked()->getMessage());
            $job->fail($h);
        } catch (Throwable $t) {
            // Unexpected (deadlock, lost connection): retried once by the worker; failed() records the end. Back to
            // queued while the retry waits, so prune judges it by requested_at and not as a running attempt.
            $this->deletePart($export);
            Export::whereKey($export->id)->where('status', Export::RUNNING)->update(['status' => Export::QUEUED, 'updated_at' => now()]);
            throw $t;
        } finally {
            $writer?->cleanup();
            $ctx->set($previous[0], $previous[1]);
            setPermissionsTeamId($previous[2]);
        }
    }

    /** RunExport::failed(): the last attempt failed, or the job timed out. */
    public function jobFailed(string $exportId, ?Throwable $e): void
    {
        // A timeout fires while the build may still hold the snapshot open: end it before writing.
        ReadSnapshot::abandon();
        $export = Export::find($exportId);
        if (! $export) {
            return;
        }
        if ($e instanceof ExportFailure) {
            $this->fail($export, $e->failureCode, $e->getMessage());
        } elseif ($e instanceof TimeoutExceededException) {
            $this->fail($export, 'timeout', self::TIMEOUT);
        } else {
            // Users never see the raw exception text (R9).
            $this->fail($export, 'error', ExportFailure::GENERIC);
        }
    }

    /**
     * Mark failed (once), delete any .part file, write export.failed and fire ExportFinished. $bySystem = false for a
     * failure found at download (damaged / missing): that event is the user's request, and no "could not be
     * prepared" email follows it.
     */
    public function fail(Export $export, string $code, string $message, bool $bySystem = true, ?array $old = null, array $extra = []): bool
    {
        // Reviewer M2: one conditional UPDATE decides. A system failure ends a queued or running export; a failure
        // found at download ends a ready one. Whoever loses the race (prune, the job, failed(), a second download)
        // writes nothing: no second audit row, event or email.
        // Accountant C2: the status change and its audit row commit together, so no status lacks its explanation.
        $from = $bySystem ? Export::ACTIVE : [Export::READY];
        $won = DB::transaction(function () use ($export, $from, $code, $message, $bySystem, $old, $extra) {
            if (! Export::whereKey($export->id)->whereIn('status', $from)
                ->update(['status' => Export::FAILED, 'failure_code' => $code, 'error' => $message, 'updated_at' => now()])) {
                return false;
            }
            $export->refresh();
            $values = $this->auditValues($export, ['failure_code' => $code, 'attempts' => $export->attempts] + $extra);
            $bySystem
                ? $this->systemAudit('export.failed', $export, $values, $old)
                : AuditLog::record('export.failed', null, $old, $values, $export->auditBranchId());

            return true;
        });
        $export->refresh();
        // A losing failure (say `incomplete` after prune failed the row as stale) still removes its .part file.
        $this->deletePart($export);
        if ($won && $bySystem) {
            ExportFinished::dispatch($export);
        }

        return $won;
    }

    /**
     * R2 / R6: the four checks against the stored branch context (never the current X-Branch-Id). Null = access
     * holds, else the reason (export.download_denied, finding 8).
     */
    public function accessProblem(Export $export, ?User $user): ?string
    {
        if (! $user || $user->status !== 'active') {
            return 'inactive';
        }
        if ($export->branch_id === null) {
            return $user->is_super_admin ? null : 'not_super_admin';
        }
        if (! $user->belongsToBranch($export->branch_id)) {
            return 'not_member';
        }
        if ($user->is_super_admin) {
            return null;
        }
        $keys = $user->permissionKeysIn($export->branch_id);
        foreach (self::PERMISSIONS[$export->kind] as $perm) {
            if (! in_array($perm, $keys, true)) {
                return 'missing_permission';
            }
        }

        return null;
    }

    /** @return string[] what does not agree (finding 5) */
    public static function incompleteness(array $counts): array
    {
        $problems = [];
        if (isset($counts['sheets'])) {
            if ($counts['built'] !== $counts['rows']) {
                $problems[] = "detail rows built {$counts['built']}, counted {$counts['rows']}";
            }
            foreach ($counts['sheets'] as $i => $s) {
                if ($s['written'] !== $s['built']) {
                    $problems[] = "sheet {$i}: rows written {$s['written']}, built {$s['built']}";
                }
            }
            foreach ($counts['unfooted'] as $t) {
                $problems[] = "total does not foot: {$t}";
            }
        } else {
            if ($counts['written'] !== $counts['rows']) {
                $problems[] = "rows written {$counts['written']}, counted {$counts['rows']}";
            }
            if ($counts['printed'] !== $counts['written']) {
                $problems[] = "header rows {$counts['printed']}, written {$counts['written']}";
            }
        }

        return $problems;
    }

    // ------------------------------------------------------------------ download (R6)

    public function download(Request $request, Export $export): Response
    {
        if ($refused = $this->notDownloadable($export)) {
            return $refused;
        }
        if ($reason = $this->accessProblem($export, $request->user())) {
            // Finding 8: the one event that shows a removed user trying to get data out.
            AuditLog::record('export.download_denied', null, null, $this->auditValues($export, ['reason' => $reason]), $export->auditBranchId());

            return response()->json(['message' => self::NO_ACCESS], 403);
        }

        // Reviewer m5: the file is opened once and streamed from that handle, so prune deleting it after this point
        // cannot break the download; deleted before, the row says why (expired: 410, as above).
        $disk = Storage::disk(Export::DISK);
        $path = (string) $export->file_path;
        $handle = $path !== '' && is_file($disk->path($path)) ? @fopen($disk->path($path), 'rb') : false;
        if ($handle === false) {
            if ($refused = $this->notDownloadable($export->refresh())) {
                return $refused;
            }
            Log::error("Export {$export->id}: file missing from storage.", ['path' => $path]);
            $this->fail($export, 'missing', self::MISSING, bySystem: false);

            return response()->json(['message' => self::MISSING], 410);
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $handle);
        $actual = hash_final($hash);
        if (! hash_equals((string) $export->sha256, $actual)) {
            fclose($handle);
            report(new RuntimeException("Export {$export->id}: sha256 mismatch."));
            $this->fail($export, 'damaged', self::DAMAGED, bySystem: false, old: ['sha256' => $export->sha256], extra: ['actual_sha256' => $actual]);

            return response()->json(['message' => self::DAMAGED], 500);
        }

        // Reviewer m3 / m5: one atomic increment, and only while the row is still ready and unexpired.
        $counted = Export::whereKey($export->id)->where('status', Export::READY)->where('expires_at', '>', now())
            ->update(['download_count' => DB::raw('download_count + 1'), 'downloaded_at' => now(), 'updated_at' => now()]);
        $export->refresh();
        if (! $counted) {
            fclose($handle);

            return $this->notDownloadable($export) ?? response()->json(['message' => self::NOT_READY], 409);
        }
        // Finding 3: the download's own IP and user agent (the current request).
        AuditLog::record('export.downloaded', null, null, $this->auditValues($export, [
            'download_count' => $export->download_count, 'sha256' => $export->sha256, 'data_as_at' => self::iso($export->data_as_at),
        ]), $export->auditBranchId());

        $file = $this->fileName($export);
        rewind($handle);

        return response()->stream(function () use ($handle) {
            fpassthru($handle);
            fclose($handle);
        }, 200, [
            'Content-Type' => self::XLSX,
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Content-Length' => (string) $export->size_bytes,
            // Finding 4: no browser or proxy keeps a copy outside the 24 h control.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The 409 / 410 answers for a row that has no file to give (queued, running, failed, expired). */
    private function notDownloadable(Export $export): ?JsonResponse
    {
        if (in_array($export->status, Export::ACTIVE, true)) {
            return response()->json(['message' => self::NOT_READY], 409);
        }
        if ($export->status === Export::FAILED) {
            return response()->json(['message' => self::FAILED_NO_FILE], 410);
        }
        if (! $export->isDownloadable()) {
            $at = $export->expires_at?->copy()->setTimezone('Asia/Colombo');
            $when = $at ? app(Settings::class)->formatDate($at->format('Y-m-d')).' '.$at->format('H:i') : '';

            return response()->json(['message' => "This export expired on {$when}. Run it again."], 410);
        }

        return null;
    }

    public function fileName(Export $export): string
    {
        $p = (array) $export->params;

        return $export->kind === Export::KIND_TRANSACTIONS
            ? 'transactions_'.($p['from'] ?? 'all').'_'.($p['to'] ?? 'all').'.xlsx'
            : "{$export->name}_".($p['from'] ?? '').'_'.($p['to'] ?? '').'.xlsx';
    }

    // ------------------------------------------------------------------ prune (R10)

    /** @return array{expired: int, stale: int, orphans: int, scheduled: int} */
    public function prune(): array
    {
        $disk = Storage::disk(Export::DISK);
        $out = ['expired' => 0, 'stale' => 0, 'orphans' => 0, 'scheduled' => 0];

        // 1. Ready past expiry: the file goes, the row stays.
        Export::where('status', Export::READY)->where('expires_at', '<=', now())->orderBy('requested_at')->get()
            ->each(function (Export $e) use ($disk, &$out) {
                // Conditional, like every status change (reviewer M2): a row a download just failed is left alone.
                // Accountant C2: the status and export.expired commit together; the file goes after the commit.
                $path = $e->file_path;
                $won = DB::transaction(function () use ($e) {
                    if (! Export::whereKey($e->id)->where('status', Export::READY)->update(['status' => Export::EXPIRED, 'file_path' => null, 'updated_at' => now()])) {
                        return false;
                    }
                    $e->refresh();
                    AuditLog::record('export.expired', null, null,
                        $this->auditValues($e, ['by_system' => true, 'requested_by' => $e->user_id]), $e->auditBranchId(),
                        ['user_id' => null, 'ip' => null, 'user_agent' => 'console']);

                    return true;
                });
                if (! $won) {
                    return;
                }
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }
                $out['expired']++;
            });

        // 2. A stopped worker. Queued: judged by requested_at (stale_minutes). Running: judged by the start of the
        // current attempt (reviewer M2), because a live attempt ends within the job timeout and a killed one is picked
        // up again within retry_after; a long wait in the queue never fails an attempt that is still building.
        Export::where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', Export::QUEUED)->where('requested_at', '<=', now()->subMinutes((int) config('exports.stale_minutes', 120))))
            ->orWhere(fn ($q) => $q->where('status', Export::RUNNING)->where('started_at', '<=', now()->subMinutes(self::staleRunningMinutes()))))
            ->orderBy('requested_at')->get()
            ->each(function (Export $e) use (&$out) {
                if ($this->fail($e, 'stale', self::STALE)) {
                    $out['stale']++;
                }
            });

        // AG-088 R12: scheduled report PDFs go keep_file_days after their run finished (one audit row per pass).
        $out['scheduled'] = app(ScheduledReportRuns::class)->pruneFiles();

        // 3. Files without a ready row, older than 25 h (a killed worker's .part or temporary files). No audit; logged.
        // A scheduled report PDF that is still kept (R12) is not an orphan.
        $keep = Export::where('status', Export::READY)->whereNotNull('file_path')->pluck('file_path')
            ->merge(ScheduledReportRuns::keptFiles())->flip();
        $cutoff = now()->subHours(25)->getTimestamp();
        foreach ($disk->allFiles() as $f) {
            if (! isset($keep[$f]) && $disk->lastModified($f) < $cutoff) {
                $disk->delete($f);
                Log::info("exports:prune deleted an orphan file: {$f}");
                $out['orphans']++;
            }
        }

        return $out;
    }

    /** A running attempt older than this has no live worker: the job timeout plus the queue's retry_after (25 min). */
    public static function staleRunningMinutes(): int
    {
        return (int) ceil(((new RunExport(''))->timeout + (int) config('queue.connections.exports.retry_after', 900)) / 60);
    }

    // ------------------------------------------------------------------ presentation

    /** Section 5 "Export resource": no file path, user id or params internals. */
    /** @param  array<int, Branch>|null  $branches  preloaded by id (the list loads them once per page, reviewer m8) */
    public function present(Export $e, ?array $branches = null): array
    {
        return [
            'id' => $e->id,
            'kind' => $e->kind,
            'name' => $e->name,
            'title' => self::title($e),
            'format' => $e->format,
            'branches_label' => self::branchesLabel($e, $branches),
            'period_label' => self::periodLabel($e),
            'rows' => $e->rows,
            'status' => $e->displayStatus(),
            'error' => $e->status === Export::FAILED ? $e->error : null,
            'requested_at' => self::iso($e->requested_at),
            'data_as_at' => self::iso($e->data_as_at),
            'ready_at' => self::iso($e->ready_at),
            'expires_at' => self::iso($e->expires_at),
            'size_bytes' => $e->size_bytes,
            'download_count' => $e->download_count,
            'rerun_url' => self::rerunUrl($e),
        ];
    }

    public static function title(Export $e): string
    {
        return $e->kind === Export::KIND_TRANSACTIONS ? 'Transactions' : (ReportExporter::TITLES[$e->name] ?? $e->name);
    }

    /** "All branches" (All mode without a branch filter), else the codes in scope. */
    public static function branchesLabel(Export $e, ?array $branches = null): string
    {
        if ($e->branch_id === null && empty(((array) $e->params)['branches'] ?? null)) {
            return 'All branches';
        }
        $ids = array_map('intval', (array) $e->branch_ids);
        $found = $branches === null ? Branch::whereIn('id', $ids)->get() : collect($ids)->map(fn ($id) => $branches[$id] ?? null)->filter();

        return $found->sortBy(fn (Branch $b) => [$b->is_main ? 0 : 1, $b->code])->pluck('code')->implode(', ');
    }

    /** @return array<int, Branch> the branches of these exports, by id (one query) */
    public static function branchesOf(iterable $exports): array
    {
        $ids = collect($exports)->flatMap(fn (Export $e) => (array) $e->branch_ids)->map(fn ($id) => (int) $id)->unique()->values();

        return Branch::whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    public static function periodLabel(Export $e): string
    {
        $s = app(Settings::class);
        $from = ((array) $e->params)['from'] ?? null;
        $to = ((array) $e->params)['to'] ?? null;

        return match (true) {
            $from && $to => $s->formatDate($from).' to '.$s->formatDate($to),
            (bool) $from => 'From '.$s->formatDate($from),
            (bool) $to => 'Up to '.$s->formatDate($to),
            default => 'All dates',
        };
    }

    /** Email line: "<Title> (Excel), <branches>, <period>, <rows> rows." (no figures). */
    public static function summaryLine(Export $e): string
    {
        return self::title($e).' (Excel), '.self::branchesLabel($e).', '.self::periodLabel($e).', '.number_format($e->rows).' rows.';
    }

    /** The Exports page with this export highlighted (the email link). Never the file. */
    public static function pageUrl(Export $e): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/exports?id='.$e->id;
    }

    /** F6: the original screen with the stored filters, as the web app reads them (lists as repeated keys). */
    public static function rerunUrl(Export $e): string
    {
        $path = $e->kind === Export::KIND_TRANSACTIONS ? '/transactions' : "/reports/{$e->name}";
        $parts = [];
        foreach ((array) $e->params as $k => $v) {
            foreach (is_array($v) ? $v : [$v] as $x) {
                if ($x !== null && $x !== '') {
                    $parts[] = rawurlencode((string) $k).'='.rawurlencode(is_bool($x) ? ($x ? '1' : '0') : (string) $x);
                }
            }
        }

        return $path.($parts ? '?'.implode('&', $parts) : '');
    }

    /** R3 header line (finding 1): both times with the date; the extraction time with seconds. */
    public static function extractedLine(CarbonInterface $at, CarbonInterface $requested): string
    {
        $tz = 'Asia/Colombo';

        return 'Data extracted '.$at->copy()->setTimezone($tz)->format('Y-m-d H:i:s').' (Asia/Colombo). '
            .'Includes every entry, void and reversal recorded before this time. '
            .'Requested '.$requested->copy()->setTimezone($tz)->format('Y-m-d H:i').'.';
    }

    // ------------------------------------------------------------------ helpers

    /** R11 new_values: export_id, name, format, params, rows, plus the event's own keys. */
    private function auditValues(Export $e, array $extra = []): array
    {
        return ['export_id' => $e->id, 'name' => $e->name, 'format' => $e->format, 'params' => $e->params, 'rows' => $e->rows] + $extra;
    }

    /** Finding 7: a system event, kept on the requester (user filter) with the request's client (R11). */
    private function systemAudit(string $event, Export $e, array $values, ?array $old = null): void
    {
        AuditLog::record($event, null, $old, $values + ['by_system' => true], $e->auditBranchId(),
            ['user_id' => $e->user_id, 'ip' => $e->ip, 'user_agent' => $e->user_agent]);
    }

    private function deletePart(Export $e): void
    {
        try {
            $disk = Storage::disk(Export::DISK);
            if ($disk->exists($e->partPath())) {
                $disk->delete($e->partPath());
            }
        } catch (Throwable $t) {
            report($t);
        }
    }

    /** Stored params in one canonical form, so an identical request is found (R7). */
    public static function canonical(array $v): array
    {
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(fn ($x) => is_array($x) ? self::canonical($x) : (is_string($x) && ctype_digit($x) && array_is_list($v) ? (int) $x : $x), $v);
    }

    private static function iso(?CarbonInterface $t): ?string
    {
        return $t?->toIso8601String();
    }

    /** R5: 1024M for the worker, like ReportExporter::raisePdfLimits (never lowers a higher limit). */
    public static function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current === '-1') {
            return;
        }
        $n = (int) $current;
        $bytes = match (strtolower(substr(trim($current), -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
        if ($bytes < 1024 * 1024 * 1024) {
            @ini_set('memory_limit', '1024M');
        }
    }
}
