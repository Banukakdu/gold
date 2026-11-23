<?php

namespace App\Services;

use App\Jobs\RunScheduledReport;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Export;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Models\User;
use App\Notifications\InAppNotification as N;
use App\Notifications\ReportScheduleProblemMail;
use App\Notifications\ScheduledReportMail;
use App\Support\BranchContext;
use App\Support\ReadSnapshot;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * AG-088 scheduled email reports: the dispatcher (R4), the build (R1, R6), the send checks (R5, R7, R8), failures and
 * the automatic pause (R9, R10), file removal (R12), the system audit rows (R13) and the owner alerts (S5).
 *
 * Read-only towards the books: the PDF is built by ReportExporter from ReportService inside one ReadSnapshot, exactly
 * as a synchronous PDF export. Nothing here writes `transactions`, `ledger_entries` or `accounts.current_balance`.
 *
 * Every status change is one conditional UPDATE (queued -> running, running -> done, queued -> sent, ...), committed
 * together with its audit row; whoever loses a race writes nothing. Alerts and emails go out after the commit.
 */
class ScheduledReportRuns
{
    public const GENERIC = 'The report could not be built. It will run again at the next scheduled time.';

    public const STALE = 'The report was not built. Try again later.';

    public const INVALID = "The schedule's filters are no longer valid. Edit the schedule.";

    public const OWNER_LOST = 'The owner is no longer an active Super Admin. The schedule was paused.';

    /** R15: the owner check failed for a run planned before the last resume; the resumed schedule is not paused again. */
    public const OWNER_LOST_BEFORE_RESUME = 'The owner of this run is no longer an active Super Admin. The run was planned before the schedule was resumed, so the schedule was not paused.';

    public const FILES_DIR = 'scheduled';

    public function __construct(
        private ScheduledReports $schedules,
        private ReportService $reports,
        private ReportExporter $exporter,
        private InAppNotifications $inApp,
    ) {}

    // ------------------------------------------------------------------ dispatch (R4)

    /** @return array{queued: int, missed: int, stale_runs: int, stale_deliveries: int, settled: int} */
    public function dispatch(): array
    {
        $out = ['queued' => 0, 'missed' => 0, 'stale_runs' => 0, 'stale_deliveries' => 0, 'settled' => 0];
        $due = ReportSchedule::where('status', ReportSchedule::ACTIVE)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')->orderBy('id')->pluck('id');
        foreach ($due as $id) {
            try {
                [$queued, $missed] = $this->dispatchOne((int) $id);
                $out['queued'] += $queued;
                $out['missed'] += $missed;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $out['stale_runs'] = $this->failStaleRuns();
        $out['stale_deliveries'] = $this->failStaleDeliveries();
        $out['settled'] = $this->settleOpenRuns();

        return $out;
    }

    /** @return array{0: int, 1: int} runs queued, missed runs recorded */
    private function dispatchOne(int $id): array
    {
        return DB::transaction(function () use ($id) {
            // 1. Lock the schedule row; re-read it (a locking read sees the latest committed row).
            $s = ReportSchedule::whereKey($id)->lockForUpdate()->first();
            $now = CarbonImmutable::now();
            if (! $s || $s->status !== ReportSchedule::ACTIVE || ! $s->next_run_at || $s->next_run_at->gt($now)) {
                return [0, 0];
            }
            // 2. The planned times from next_run_at up to now.
            $planned = [];
            $t = CarbonImmutable::instance($s->next_run_at);
            while ($t->lte($now)) {
                $planned[] = $t;
                $t = ScheduledReports::nextRunAt($s->frequency, $s->weekday, $s->send_time, $t);
            }
            // 3. Older than the late window (accounting review A5): one missed run. 4. The latest within it: queued.
            $cut = $now->subMinutes(self::lateMinutes($s->frequency));
            $within = array_values(array_filter($planned, fn (CarbonImmutable $p) => $p->gte($cut)));
            $send = $within ? end($within) : null;
            $missed = array_values(array_filter($planned, fn (CarbonImmutable $p) => $p !== $send));

            // 5. The first planned time after now (paused periods are never sent afterwards).
            $s->next_run_at = $t;
            $s->save();

            // Review S2: when the missed run is the failure that pauses the schedule (R10), the on-time planned time is
            // not sent either; it is folded into the missed run, so every planned time is recorded somewhere.
            if ($missed && $send && $this->wouldPause($s)) {
                $missed[] = $send;
                $send = null;
            }

            $paused = false;
            if ($missed) {
                $paused = $this->recordMissed($s, $missed);
            }
            if ($send && ! $paused && ! ReportScheduleRun::where('schedule_id', $s->id)->where('planned_at', $send)->exists()) {
                [$from, $to] = ScheduledReports::period($s->frequency, $send);
                $run = $this->newRun($s, $send, $from, $to, ReportScheduleRun::QUEUED);
                // The `exports` connection dispatches after commit, so the worker never sees an uncommitted row.
                RunScheduledReport::dispatch($run->id);

                return [1, $missed ? 1 : 0];
            }

            return [0, $missed ? 1 : 0];
        });
    }

    /** @param  CarbonImmutable[]  $missed */
    private function recordMissed(ReportSchedule $s, array $missed): bool
    {
        $from = ScheduledReports::period($s->frequency, $missed[0])[0];
        $to = ScheduledReports::period($s->frequency, end($missed))[1];
        $run = $this->newRun($s, $missed[0], $from, $to, ReportScheduleRun::MISSED, ['missed_count' => count($missed), 'finished_at' => now()]);
        $this->systemAudit('report_schedule.run_missed', $s, ['run_id' => $run->id, 'missed_count' => count($missed),
            'period' => ['from' => $from, 'to' => $to], 'planned_at' => $missed[0]->toIso8601ZuluString()], $run->auditBranchId());
        $n = count($missed);
        $title = "{$s->name}: {$n} scheduled ".($n === 1 ? 'email was' : 'emails were').' missed';
        $this->afterCommit(fn () => $this->alertOwner($s->id, N::SCHEDULE_MISSED, $title, 'Missed periods are not sent later. The period can be viewed on screen.', true));

        return $this->countFailure($s, end($missed));
    }

    private function newRun(ReportSchedule $s, CarbonInterface $planned, string $from, string $to, string $status, array $extra = []): ReportScheduleRun
    {
        $explicit = $s->branchIds();

        return ReportScheduleRun::create([
            'schedule_id' => $s->id, 'planned_at' => $planned, 'period_from' => $from, 'period_to' => $to, 'status' => $status,
            'owner_id' => $s->owner_id, 'report' => $s->report,
            // The ReportService filters of this run: the schedule's own (type / types) and its explicit branches.
            'params' => (array) $s->params + ['branches' => $explicit],
            // R1: exactly one branch = branch mode of it; otherwise All mode (null).
            'branch_id' => $s->singleBranchId(),
            'branch_ids' => $explicit ?: Branch::orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'attempts' => 0,
        ] + $extra);
    }

    public static function lateMinutes(string $frequency): int
    {
        return (int) config("scheduled_reports.late_minutes.{$frequency}", $frequency === ReportSchedule::WEEKLY ? 1440 : 360);
    }

    /**
     * R4 / A3: runs queued or running for more than stale_run_minutes fail as `stale`, like a failed job. The age is
     * repeated in the failing UPDATE (review S3): a run a worker claimed after the SELECT has a fresh started_at and
     * is left alone.
     */
    public function failStaleRuns(): int
    {
        $cut = now()->subMinutes((int) config('scheduled_reports.stale_run_minutes', 120));
        $stale = fn ($q) => $q->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', ReportScheduleRun::QUEUED)->where('created_at', '<', $cut))
            ->orWhere(fn ($q) => $q->where('status', ReportScheduleRun::RUNNING)->where('started_at', '<', $cut)));
        $n = 0;
        ReportScheduleRun::query()->tap($stale)->orderBy('created_at')->get()
            ->each(function (ReportScheduleRun $run) use (&$n, $stale) {
                if ($this->failRun($run, 'stale', self::STALE, guard: $stale)) {
                    $n++;
                }
            });

        return $n;
    }

    /**
     * R4 / A3: deliveries still queued after delivery_stale_hours fail as `stale`; a later mail job sends nothing.
     * Review S1: a delivery `sending` for more than sending_stale_minutes (the worker died, or recording `sent` failed
     * after the mailer accepted it) fails as `unknown`: it may have gone out, so it is never sent again.
     */
    public function failStaleDeliveries(): int
    {
        $queuedCut = now()->subHours((int) config('scheduled_reports.delivery_stale_hours', 24));
        $sendingCut = now()->subMinutes((int) config('scheduled_reports.sending_stale_minutes', 60));
        $n = 0;
        foreach (ReportScheduleDelivery::where('status', ReportScheduleDelivery::QUEUED)->where('queued_at', '<', $queuedCut)->orderBy('id')->pluck('id') as $id) {
            if ($this->deliveryFailed((int) $id, 'stale', [ReportScheduleDelivery::QUEUED], fn ($q) => $q->where('queued_at', '<', $queuedCut))) {
                $n++;
            }
        }
        foreach (ReportScheduleDelivery::where('status', ReportScheduleDelivery::SENDING)->where('sending_at', '<', $sendingCut)->orderBy('id')->pluck('id') as $id) {
            if ($this->deliveryFailed((int) $id, 'unknown', [ReportScheduleDelivery::SENDING], fn ($q) => $q->where('sending_at', '<', $sendingCut))) {
                $n++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ build (R1, R5, R6)

    public function build(RunScheduledReport $job): void
    {
        // 1. Claim: only a queued run becomes running (idempotent; prune or a stale check may have failed it). Review N1:
        // also a run left `running` longer than the job timeout: its worker died (killed, out of memory) and the queue
        // handed the job out again after retry_after; a live worker is always stopped by the timeout before that.
        $dead = now()->subSeconds((int) $job->timeout);
        $claimed = ReportScheduleRun::whereKey($job->runId)
            ->where(fn ($q) => $q->where('status', ReportScheduleRun::QUEUED)
                ->orWhere(fn ($q) => $q->where('status', ReportScheduleRun::RUNNING)->where('started_at', '<', $dead)))
            ->update(['status' => ReportScheduleRun::RUNNING, 'started_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        $run = $claimed ? ReportScheduleRun::find($job->runId) : null;
        if (! $run) {
            return;
        }
        $s = ReportSchedule::find($run->schedule_id);
        $disk = Storage::disk(Export::DISK);
        $ctx = app(BranchContext::class);
        $previous = [$ctx->branchId, $ctx->allBranches, getPermissionsTeamId()];

        try {
            // 2. The owner: active and Super Admin.
            $owner = User::find($run->owner_id);
            if (! $owner || $owner->status !== 'active' || ! $owner->is_super_admin) {
                $this->ownerLost($run);
                $job->fail(new RuntimeException('owner_lost_access'));

                return;
            }

            // 3. The recipients (R5 at build).
            $branchIds = self::checkBranchIds($run);
            $queued = [];
            $skipped = [];
            foreach (User::whereIn('id', $this->schedules->currentRecipientIds($s))->orderBy('id')->get() as $u) {
                $problem = $this->schedules->recipientProblem($u, $branchIds);
                $problem === null ? $queued[(int) $u->id] = $u : $skipped[(int) $u->id] = [$u, $problem];
            }
            if (! $queued) {
                $this->finishNothingSent($run, $skipped);

                return;
            }

            // 4. One snapshot, as the stored branch context (R1), never a current header.
            $ctx->set($run->branch_id, $run->branch_id === null);
            setPermissionsTeamId($run->branch_id);
            [$built, $at] = ReadSnapshot::run(function (CarbonImmutable $at) use ($run, $s, $owner) {
                app(Settings::class)->flush();
                $p = $this->reports->params($run->report, $this->reportInput($run));
                $printed = 'Scheduled report "'.$s->name.'", set up by '.$owner->name.'. Printed at '.ReportExporter::printedAt().'.';
                $extracted = BackgroundExports::extractedLine($at, $run->planned_at);

                return [$this->exporter->buildScheduled($run->report, $p, $owner, $printed, $extracted), $at];
            });
            if ($built['built'] !== $built['rows'] || $built['unfooted']) {
                Log::error("Scheduled report run {$run->id} is incomplete; nothing sent.", ['rows' => $built['rows'], 'built' => $built['built'], 'unfooted' => $built['unfooted']]);
                throw new RuntimeException('Scheduled report incomplete.');
            }

            // 5. The file: .part (0600), renamed, size and sha256.
            $final = $size = $sha = null;
            if ($built['pdf'] !== null) {
                $disk->makeDirectory(self::FILES_DIR);
                $disk->put($run->partPath(), $built['pdf']);
                @chmod($disk->path($run->partPath()), 0600);
                $final = $run->finalPath();
                $disk->move($run->partPath(), $final);
                @chmod($disk->path($final), 0600);
                clearstatcache();
                $size = (int) filesize($disk->path($final));
                $sha = hash_file('sha256', $disk->path($final));
            }
            // 6. Attach or link (R7).
            $attached = $final !== null && $built['rows'] <= ReportService::PDF_EXPORT_CAP && $size <= (int) config('scheduled_reports.attach_max_bytes');

            // 7. Done, the deliveries and the audit row in one transaction; the emails after the commit.
            $owned = DB::transaction(function () use ($run, $built, $at, $final, $size, $sha, $attached, $queued, $skipped) {
                if (! ReportScheduleRun::whereKey($run->id)->where('status', ReportScheduleRun::RUNNING)->update([
                    'status' => ReportScheduleRun::DONE, 'rows' => $built['rows'], 'attached' => $attached, 'file_path' => $final,
                    'size_bytes' => $size, 'sha256' => $sha, 'data_as_at' => $at, 'finished_at' => now(), 'error' => null,
                    'failure_code' => null, 'updated_at' => now(),
                ])) {
                    return false;
                }
                $run->refresh();
                $this->makeDeliveries($run, $queued, $skipped);
                $this->systemAudit('report_schedule.run_built', $run->schedule, [
                    'run_id' => $run->id, 'period' => ['from' => $run->periodFrom(), 'to' => $run->periodTo()], 'rows' => $built['rows'],
                    'attached' => $attached, 'size_bytes' => $size, 'sha256' => $sha, 'data_as_at' => $at->toIso8601ZuluString(),
                    'provisional' => $built['provisional'], 'queued' => array_keys($queued),
                    'skipped' => array_map(fn ($x) => $x[1], $skipped), 'totals' => $built['totals'],
                ], $run->auditBranchId());

                return true;
            });
            if (! $owned) {
                if ($final) {
                    $disk->delete($final);
                }
                Log::warning("Scheduled report run {$run->id} finished after it was marked {$run->fresh()?->status}; the file was discarded.");

                return;
            }
            // The run is done. If one email cannot be queued (the queue store is down), the others still are; that
            // delivery stays `queued` and the dispatcher fails it as `stale` after delivery_stale_hours (review N2).
            foreach (ReportScheduleDelivery::where('run_id', $run->id)->where('status', ReportScheduleDelivery::QUEUED)->orderBy('id')->get() as $d) {
                try {
                    Notification::route('mail', $d->email)->notify(new ScheduledReportMail($d->id, $built['provisional']));
                } catch (Throwable $e) {
                    report($e);
                }
            }
            $this->alertSkipped($s, count($skipped));
        } catch (ValidationException $v) {
            $this->failRun($run, 'invalid', self::INVALID);
            $job->fail($v);
        } catch (HttpExceptionInterface $h) {
            // ReportService refuses a branch outside the stored context (403): the owner lost access (R1).
            $this->ownerLost($run);
            $job->fail($h);
        } catch (Throwable $t) {
            // Unexpected (deadlock, lost connection): retried once by the worker; failed() records the end. Back to
            // queued while the retry waits.
            ReadSnapshot::abandon();
            $this->deletePart($run);
            ReportScheduleRun::whereKey($run->id)->where('status', ReportScheduleRun::RUNNING)->update(['status' => ReportScheduleRun::QUEUED, 'updated_at' => now()]);
            throw $t;
        } finally {
            $ctx->set($previous[0], $previous[1]);
            setPermissionsTeamId($previous[2]);
        }
    }

    /** RunScheduledReport::failed(): the last attempt failed, or the job timed out. Users never see exception text. */
    public function jobFailed(string $runId, ?Throwable $e): void
    {
        ReadSnapshot::abandon();
        $run = ReportScheduleRun::find($runId);
        if ($run) {
            $this->failRun($run, $e instanceof TimeoutExceededException ? 'timeout' : 'error', self::GENERIC);
        }
    }

    /** R5 at build and at send: the branch scope the recipients are checked against ([id] = single branch). */
    public static function checkBranchIds(ReportScheduleRun $run): array
    {
        return $run->branch_id ? [(int) $run->branch_id] : array_values(array_map('intval', (array) (((array) $run->params)['branches'] ?? [])));
    }

    /** ReportService::params input of a run: its period, the requested branches (none in branch mode) and its filters. */
    private function reportInput(ReportScheduleRun $run): array
    {
        $params = (array) $run->params;
        $input = ['from' => $run->periodFrom(), 'to' => $run->periodTo(), 'branches' => $run->branch_id ? [] : (array) ($params['branches'] ?? [])];
        if ($run->report === 'income-expense') {
            $input['type'] = $params['type'] ?? 'all';
        }
        if ($run->report === 'transfers' && ! empty($params['types'])) {
            $input['types'] = $params['types'];
        }

        return $input;
    }

    /**
     * Review N3: deliveries must be inserted before the transaction takes any schedule lock. settleRun() holds share
     * locks on a run's deliveries (and the index gap after them) while it waits for the schedule lock; inserting
     * deliveries while holding a schedule lock would deadlock with it.
     *
     * @param  array<int, User>  $queued  @param  array<int, array{0: User, 1: string}>  $skipped
     */
    private function makeDeliveries(ReportScheduleRun $run, array $queued, array $skipped): void
    {
        $now = now();
        foreach ($queued as $id => $u) {
            ReportScheduleDelivery::create(['run_id' => $run->id, 'user_id' => $id, 'email' => mb_strtolower((string) $u->email),
                'status' => ReportScheduleDelivery::QUEUED, 'queued_at' => $now, 'attempts' => 0]);
        }
        foreach ($skipped as $id => [$u, $reason]) {
            ReportScheduleDelivery::create(['run_id' => $run->id, 'user_id' => $id, 'email' => mb_strtolower((string) $u->email),
                'status' => ReportScheduleDelivery::SKIPPED, 'reason' => $reason, 'attempts' => 0]);
        }
    }

    /** R5: nobody can receive. No file, the run ends `done` with 0 sent ("Nothing sent"), counted as a failure (R10). */
    private function finishNothingSent(ReportScheduleRun $run, array $skipped): void
    {
        $s = null;
        DB::transaction(function () use ($run, $skipped, &$s) {
            // Settled at once (R15): every delivery is skipped. Counted here, never again by settleRun().
            if (! ReportScheduleRun::whereKey($run->id)->where('status', ReportScheduleRun::RUNNING)
                ->update(['status' => ReportScheduleRun::DONE, 'attached' => false, 'finished_at' => now(), 'updated_at' => now(),
                    'settled_at' => now(), 'delivery_outcome' => ReportScheduleRun::NOTHING_SENT])) {
                return;
            }
            $run->refresh();
            $this->makeDeliveries($run, [], $skipped);
            $s = ReportSchedule::whereKey($run->schedule_id)->lockForUpdate()->first();
            $notCounted = $this->outsideStreak($s, $run->planned_at);
            $this->systemAudit('report_schedule.run_built', $s, [
                'run_id' => $run->id, 'period' => ['from' => $run->periodFrom(), 'to' => $run->periodTo()], 'nothing_sent' => true,
                'queued' => [], 'skipped' => array_map(fn ($x) => $x[1], $skipped),
                'counted' => $notCounted === null, 'not_counted_reason' => $notCounted,
            ], $run->auditBranchId());
            if ($notCounted === null) {
                $this->countFailure($s, $run->planned_at);
            }
        });
        if ($s) {
            $this->alertSkipped($s, count($skipped));
        }
    }

    /** R6 step 2: fail without retry, and the system pauses the schedule (S5 `paused` to every active Super Admin). */
    private function ownerLost(ReportScheduleRun $run): void
    {
        $this->failRun($run, 'owner_lost_access', self::OWNER_LOST, alertOwner: false, pause: 'owner_lost_access');
    }

    /**
     * Mark a queued or running run failed (once): its audit row, its queued deliveries skipped (`run_failed`), the
     * failure counted (R10) and the owner alerted (S5). The status change and the audit rows commit together.
     * A run planned before the last resume (R15 streak boundary, `resumed_at` read from the locked schedule row) is
     * recorded and alerted the same way, but not counted and never pauses the schedule (not even $pause: the resume
     * already checked the current owner, while the run keeps the owner it was planned with). Without that pause and
     * its S5 alert, the owner gets the `run_failed` alert instead.
     */
    public function failRun(ReportScheduleRun $run, string $code, string $message, bool $alertOwner = true, ?string $pause = null, ?\Closure $guard = null): bool
    {
        $alertInsteadOfPause = false;
        $won = DB::transaction(function () use ($run, $code, $message, $pause, $guard, &$alertInsteadOfPause) {
            if (! ReportScheduleRun::whereKey($run->id)->whereIn('status', ReportScheduleRun::ACTIVE)->when($guard, $guard)->update([
                'status' => ReportScheduleRun::FAILED, 'failure_code' => $code, 'error' => $message, 'finished_at' => now(), 'updated_at' => now(),
            ])) {
                return false;
            }
            $run->refresh();
            $s = ReportSchedule::whereKey($run->schedule_id)->lockForUpdate()->first();
            $notCounted = $this->outsideStreak($s, $run->planned_at);
            if ($notCounted !== null && $pause !== null) {
                ReportScheduleRun::whereKey($run->id)->update(['error' => self::OWNER_LOST_BEFORE_RESUME, 'updated_at' => now()]);
                $alertInsteadOfPause = true;
            }
            $this->systemAudit('report_schedule.run_failed', $s, ['run_id' => $run->id, 'code' => $code, 'attempts' => $run->attempts,
                'period' => ['from' => $run->periodFrom(), 'to' => $run->periodTo()],
                'counted' => $notCounted === null, 'not_counted_reason' => $notCounted], $run->auditBranchId());
            foreach (ReportScheduleDelivery::where('run_id', $run->id)->where('status', ReportScheduleDelivery::QUEUED)->pluck('id') as $id) {
                $this->skip(ReportScheduleDelivery::find($id), 'run_failed');
            }
            if ($notCounted === null) {
                if ($pause !== null && $s->status === ReportSchedule::ACTIVE) {
                    $this->systemPause($s, $pause);
                }
                $this->countFailure($s, $run->planned_at);
            }

            return true;
        });
        $this->deletePart($run);
        if ($won && ($alertOwner || $alertInsteadOfPause)) {
            $s = ReportSchedule::find($run->schedule_id);
            $this->alertOwner($s->id, N::SCHEDULE_RUN_FAILED, "Scheduled report {$s->name} could not be sent", (string) $run->fresh()?->error, true);
        }

        return $won;
    }

    /**
     * R10 / R15: 'planned_before_resume' when a run planned at $planned is outside the current streak because the
     * schedule was resumed after it was planned (resume reset the count, R11); null when it counts. $s is locked.
     */
    private function outsideStreak(ReportSchedule $s, CarbonInterface $planned): ?string
    {
        return $s->resumed_at && $planned->lt($s->resumed_at) ? 'planned_before_resume' : null;
    }

    /** R10: whether one more failure pauses this schedule. */
    private function wouldPause(ReportSchedule $s): bool
    {
        return $s->status === ReportSchedule::ACTIVE && (int) $s->consecutive_failures + 1 >= (int) config('scheduled_reports.failures_before_pause', 3);
    }

    /**
     * R10: one more run in a row that failed, was missed, sent nothing or was not delivered; paused by the system at the
     * limit. Always called with the schedule row locked. $planned is the latest planned time the failed run covers; it
     * is kept as `last_failure_planned_at` so a late `sent` of an older run does not reset this failure (R9).
     */
    private function countFailure(ReportSchedule $s, CarbonInterface $planned): bool
    {
        $s->consecutive_failures = (int) $s->consecutive_failures + 1;
        if (! $s->last_failure_planned_at || $s->last_failure_planned_at->lt($planned)) {
            $s->last_failure_planned_at = $planned;
        }
        $s->save();
        if ($s->status === ReportSchedule::ACTIVE && $s->consecutive_failures >= (int) config('scheduled_reports.failures_before_pause', 3)) {
            $this->systemPause($s, 'repeated_failures');

            return true;
        }

        return false;
    }

    /** The system pauses a schedule (R6 step 2, R10): its audit row now, S5 `paused` to the owner and Super Admins after the commit. */
    private function systemPause(ReportSchedule $s, string $reason): void
    {
        $old = ['status' => $s->status, 'next_run_at' => $s->next_run_at?->toIso8601ZuluString()];
        $s->forceFill(['status' => ReportSchedule::PAUSED, 'paused_reason' => $reason, 'next_run_at' => null])->save();
        $this->systemAudit('report_schedule.paused', $s, ['status' => ReportSchedule::PAUSED, 'paused_reason' => $reason, 'name' => $s->name], $s->auditBranchId(), $old);
        $title = "{$s->name} was paused: ".(ScheduledReports::PAUSED_REASONS[$reason] ?? $reason);
        $id = $s->id;
        $this->afterCommit(function () use ($id, $title) {
            // Every active Super Admin; the owner is one of them, or no longer may see schedules (QA D7).
            $this->alert($this->inApp->superAdmins(), $id, N::SCHEDULE_PAUSED, $title, 'Open the schedule, check its owner and recipients, and resume it.', true);
        });
    }

    // ------------------------------------------------------------------ send (R5 at send, R7, R8, R9)

    /**
     * ScheduledReportChannel, before the mailer. Null = send nothing (the delivery was skipped or failed here, with its
     * audit row, or it is no longer queued: sending, sent, skipped or failed). Else the delivery is now `sending` and
     * this is the verified PDF (null for a link-only email).
     *
     * @return array{pdf: ?string}|null
     */
    public function prepareSend(int $deliveryId): ?array
    {
        $d = ReportScheduleDelivery::find($deliveryId);
        if (! $d || $d->status !== ReportScheduleDelivery::QUEUED) {
            return null;
        }
        $run = ReportScheduleRun::find($d->run_id);
        $s = ReportSchedule::find($run->schedule_id);
        if ($s->status !== ReportSchedule::ACTIVE) {
            $this->skip($d, 'schedule_paused');

            return null;
        }
        $user = User::find($d->user_id);
        if ($problem = $this->schedules->recipientProblem($user, self::checkBranchIds($run))) {
            $this->skip($d, $problem);

            return null;
        }
        // Accounting review A2: only to the address checked at build, and only while it is still the user's address.
        if (mb_strtolower((string) $user->email) !== mb_strtolower((string) $d->email)) {
            $this->skip($d, 'email_changed');

            return null;
        }
        // Review S1: claim it. Whoever loses (a retry, a second worker) sends nothing.
        if (! ReportScheduleDelivery::whereKey($d->id)->where('status', ReportScheduleDelivery::QUEUED)->update([
            'status' => ReportScheduleDelivery::SENDING, 'sending_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
        ])) {
            return null;
        }
        if (! $run->attached) {
            return ['pdf' => null];
        }
        // R8: the file is read again and must be the one built.
        $disk = Storage::disk(Export::DISK);
        $path = (string) $run->file_path;
        $bytes = $path !== '' && $disk->exists($path) ? $disk->get($path) : null;
        if ($bytes === null) {
            Log::error("Scheduled report run {$run->id}: file missing from storage.", ['path' => $path]);
            $this->deliveryFailed($d->id, 'missing', [ReportScheduleDelivery::SENDING]);

            return null;
        }
        if (! hash_equals((string) $run->sha256, hash('sha256', $bytes))) {
            report(new RuntimeException("Scheduled report run {$run->id}: sha256 mismatch."));
            $this->deliveryFailed($d->id, 'damaged', [ReportScheduleDelivery::SENDING]);

            return null;
        }

        return ['pdf' => $bytes];
    }

    /** S4: the email. No amounts, balances or account names; never the words "as at". */
    public function mail(int $deliveryId, bool $provisional, ?string $pdf): MailMessage
    {
        $d = ReportScheduleDelivery::findOrFail($deliveryId);
        $run = ReportScheduleRun::findOrFail($d->run_id);
        $s = ReportSchedule::findOrFail($run->schedule_id);
        $settings = app(Settings::class);
        $title = ReportExporter::TITLES[$run->report] ?? $run->report;
        $period = self::periodLabel($run->periodFrom(), $run->periodTo());
        $owner = User::find($run->owner_id)?->name ?? 'a Super Admin';
        $local = fn (CarbonInterface $t, string $f = 'H:i') => $settings->formatDate($t->copy()->setTimezone('Asia/Colombo')->format('Y-m-d')).' '.$t->copy()->setTimezone('Asia/Colombo')->format($f);

        $mail = (new MailMessage)->subject("{$s->name}: {$title}, {$period}")
            ->greeting('Hello '.(User::find($d->user_id)?->name ?? '').',')
            ->line("{$title} for ".self::branchesLabel($run).", {$period}.");
        if ($run->data_as_at) {
            $mail->line('Data extracted '.$local($run->data_as_at).' (Asia/Colombo).');
        }
        if ($provisional) {
            $mail->line('PROVISIONAL - period not locked. Figures can still change.');
        }
        // Accounting review A5: said when the run started more than 60 minutes after it was planned.
        if ($run->started_at && $run->planned_at && $run->started_at->gt($run->planned_at->copy()->addMinutes((int) config('scheduled_reports.sent_late_minutes', 60)))) {
            $mail->line('Sent late: this email was planned for '.$local($run->planned_at).' (Asia/Colombo).');
        }
        if ($pdf !== null) {
            $mail->line('The report is attached as a PDF ('.self::size((int) $run->size_bytes).').');
            $mail->attachData($pdf, self::attachmentName($run), ['mime' => 'application/pdf']);
        } else {
            $rows = (int) $run->rows;
            $limit = '(limit '.number_format(ReportService::PDF_EXPORT_CAP).' rows and '.str_replace('.0 MB', ' MB', self::size((int) config('scheduled_reports.attach_max_bytes'))).')';
            $mail->line($rows > ReportService::PDF_EXPORT_CAP
                ? 'This report has '.number_format($rows)." rows, which is too many to attach {$limit}. Open it in Account-gold:"
                : "This report is too large to attach {$limit}. Open it in Account-gold:");
            $mail->action('Open the report', self::reportLink($run));
            $mail->line('The figures on screen are current. They may differ from the extracted time if entries in an unlocked period changed.');
        }

        return $mail->line("You receive this because {$owner} added you to the scheduled report \"{$s->name}\". To stop it, ask a Super Admin. "
            .'This email contains company financial information: do not forward it.');
    }

    /** ScheduledReportChannel: the mailer threw, nothing was accepted. The claim goes back so the queue's retry can send. */
    public function releaseSend(int $deliveryId): void
    {
        ReportScheduleDelivery::whereKey($deliveryId)->where('status', ReportScheduleDelivery::SENDING)
            ->update(['status' => ReportScheduleDelivery::QUEUED, 'sending_at' => null, 'updated_at' => now()]);
    }

    /**
     * ScheduledReportChannel: the mailer accepted the email (R9: bounces are not tracked). Retried on a deadlock; if it
     * still fails, the channel reports it and the delivery stays `sending` (see failStaleDeliveries).
     */
    public function markSent(int $deliveryId): void
    {
        $runId = null;
        DB::transaction(function () use ($deliveryId, &$runId) {
            if (! ReportScheduleDelivery::whereKey($deliveryId)->where('status', ReportScheduleDelivery::SENDING)
                ->update(['status' => ReportScheduleDelivery::SENT, 'sent_at' => now(), 'updated_at' => now()])) {
                return;
            }
            $d = ReportScheduleDelivery::find($deliveryId);
            $run = ReportScheduleRun::find($d->run_id);
            $s = ReportSchedule::find($run->schedule_id);
            $this->systemAudit('report_schedule.delivered', $s, ['run_id' => $run->id, 'recipient_id' => $d->user_id, 'email' => $d->email,
                'file' => $run->attached ? $run->sha256 : 'link'], $run->auditBranchId());
            // R9: a run with at least one delivery sent resets the count, unless a later run was already counted as a
            // failure (the streak is runs in a row). One conditional UPDATE: it reads the latest committed schedule row.
            ReportSchedule::whereKey($s->id)
                ->where(fn ($q) => $q->whereNull('last_failure_planned_at')->orWhere('last_failure_planned_at', '<', $run->planned_at))
                ->update(['consecutive_failures' => 0]);
            $runId = $run->id;
        }, 3);
        if ($runId !== null) {
            $this->settleAfterCommit($runId);
        }
    }

    /** R5 at send: a queued delivery is skipped with its reason (and `report_schedule.delivery_skipped`). */
    private function skip(ReportScheduleDelivery $d, string $reason): bool
    {
        return DB::transaction(function () use ($d, $reason) {
            if (! ReportScheduleDelivery::whereKey($d->id)->where('status', ReportScheduleDelivery::QUEUED)
                ->update(['status' => ReportScheduleDelivery::SKIPPED, 'reason' => $reason, 'updated_at' => now()])) {
                return false;
            }
            $run = ReportScheduleRun::find($d->run_id);
            $this->systemAudit('report_schedule.delivery_skipped', ReportSchedule::find($run->schedule_id),
                ['run_id' => $run->id, 'recipient_id' => $d->user_id, 'email' => $d->email, 'reason' => $reason], $run->auditBranchId());
            $this->settleAfterCommit($run->id);

            return true;
        });
    }

    /**
     * R8 / R9 / R4: a delivery in one of $from fails (damaged, missing, mail_error, stale, unknown); the owner gets a
     * bell row. $guard repeats the stale age in the UPDATE.
     *
     * @param  string[]  $from
     */
    public function deliveryFailed(int $deliveryId, string $code, array $from, ?\Closure $guard = null): bool
    {
        $s = null;
        $name = '';
        $runId = null;
        $won = DB::transaction(function () use ($deliveryId, $code, $from, $guard, &$s, &$name, &$runId) {
            if (! ReportScheduleDelivery::whereKey($deliveryId)->whereIn('status', $from)->when($guard, $guard)
                ->update(['status' => ReportScheduleDelivery::FAILED, 'reason' => $code, 'failed_at' => now(), 'updated_at' => now()])) {
                return false;
            }
            $d = ReportScheduleDelivery::find($deliveryId);
            $run = ReportScheduleRun::find($d->run_id);
            $s = ReportSchedule::find($run->schedule_id);
            $name = (string) User::whereKey($d->user_id)->value('name');
            $this->systemAudit('report_schedule.delivery_failed', $s, ['run_id' => $run->id, 'recipient_id' => $d->user_id, 'email' => $d->email,
                'reason' => $code], $run->auditBranchId());
            $runId = $run->id;

            return true;
        });
        if ($won && $s) {
            $this->alertOwner($s->id, N::SCHEDULE_DELIVERY_FAILED, "{$s->name}: email to {$name} failed", ScheduledReports::reasonText($code), false);
        }
        if ($runId !== null) {
            $this->settleAfterCommit($runId);
        }

        return $won;
    }

    // ------------------------------------------------------------------ settlement (R15)

    /**
     * R15: settle a built run once every delivery is final (sent, skipped or failed). It is called after the commit of
     * each change that makes a delivery final, and by the dispatcher sweep. Best effort: a failure here never fails the
     * caller (a mail job must not be retried after its email went out); the sweep settles the run later.
     */
    private function settleAfterCommit(string $runId): void
    {
        $this->afterCommit(fn () => $this->settleRun($runId));
    }

    /**
     * R15: the dispatcher sweep. Built runs that are not settled and have no queued or sending delivery (the settle
     * after a commit was lost: the worker died between the commit and the call).
     */
    public function settleOpenRuns(): int
    {
        $n = 0;
        ReportScheduleRun::where('status', ReportScheduleRun::DONE)->whereNull('settled_at')
            ->whereExists(fn ($q) => $q->from('report_schedule_deliveries as d')->whereColumn('d.run_id', 'report_schedule_runs.id'))
            ->whereNotExists(fn ($q) => $q->from('report_schedule_deliveries as d')->whereColumn('d.run_id', 'report_schedule_runs.id')
                ->whereIn('d.status', [ReportScheduleDelivery::QUEUED, ReportScheduleDelivery::SENDING]))
            ->orderBy('planned_at')->pluck('id')
            ->each(function ($id) use (&$n) {
                try {
                    if ($this->settleRun((string) $id)) {
                        $n++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $n;
    }

    /**
     * R15: when every delivery of a `done` run is final, record its outcome once:
     *  - at least one `sent`: delivered (the count was already reset by markSent, R9);
     *  - none sent and at least one `failed` (mail_error, damaged, missing, stale or unknown): not delivered. One more
     *    failure for R10, unless the run is outside the current streak (`not_counted_reason`): it was planned before
     *    the last resume (`planned_before_resume`, read from the locked schedule row), or a later run of the schedule
     *    already sent an email (`later_run_sent`). Always the `report_schedule.run_not_delivered` audit row, and the
     *    owner gets a bell row and an email;
     *  - only `skipped`: nothing sent. Not counted here (a run where nobody passed at build is counted when it is
     *    built, R5; skips at send are not failures).
     * Settling as `delivered` or `nothing_sent` writes no audit row (R15: bookkeeping; the deliveries have theirs).
     *
     * Exactly once under MySQL REPEATABLE READ: the run row is locked first, the deliveries are read with a locking
     * read (the latest committed rows, not an older snapshot), and the outcome is written by a conditional UPDATE
     * (`settled_at IS NULL`). A second call, concurrent or later, changes no row and does nothing. Every read before
     * the schedule lock is a locking read, so the check for a later sent run is this transaction's first consistent
     * read and sees every reset committed before the lock; a reset committed after it lands after this increment.
     * Lock order run -> deliveries -> schedule, the same as failRun (run -> schedule -> deliveries starts with the run,
     * so the two queue behind each other); markSent and deliveryFailed never lock a run.
     */
    public function settleRun(string $runId): bool
    {
        $alert = null;
        $won = DB::transaction(function () use ($runId, &$alert) {
            $run = ReportScheduleRun::whereKey($runId)->lockForUpdate()->first();
            if (! $run || $run->status !== ReportScheduleRun::DONE || $run->settled_at !== null) {
                return false;
            }
            $deliveries = ReportScheduleDelivery::where('run_id', $runId)->orderBy('id')->sharedLock()->get(['id', 'user_id', 'status', 'reason']);
            $by = $deliveries->countBy('status');
            if ($deliveries->isEmpty() || ($by[ReportScheduleDelivery::QUEUED] ?? 0) + ($by[ReportScheduleDelivery::SENDING] ?? 0) > 0) {
                return false;
            }
            $sent = (int) ($by[ReportScheduleDelivery::SENT] ?? 0);
            $failed = (int) ($by[ReportScheduleDelivery::FAILED] ?? 0);
            $outcome = $sent > 0 ? ReportScheduleRun::DELIVERED : ($failed > 0 ? ReportScheduleRun::NOT_DELIVERED : ReportScheduleRun::NOTHING_SENT);
            if (! ReportScheduleRun::whereKey($runId)->where('status', ReportScheduleRun::DONE)->whereNull('settled_at')
                ->update(['settled_at' => now(), 'delivery_outcome' => $outcome, 'updated_at' => now()])) {
                return false;
            }
            if ($outcome !== ReportScheduleRun::NOT_DELIVERED) {
                return true;
            }

            $s = ReportSchedule::whereKey($run->schedule_id)->lockForUpdate()->first();
            // Not counted when the run is outside the current streak: planned before the last resume (which reset the
            // count, R11), or a later run already sent an email. Still audited and alerted.
            $notCounted = $this->outsideStreak($s, $run->planned_at);
            if ($notCounted === null && ReportScheduleDelivery::where('status', ReportScheduleDelivery::SENT)
                ->whereIn('run_id', ReportScheduleRun::select('id')->where('schedule_id', $s->id)->where('planned_at', '>', $run->planned_at))
                ->exists()) {
                $notCounted = 'later_run_sent';
            }
            $paused = $notCounted === null ? $this->countFailure($s, $run->planned_at) : false;
            $this->systemAudit('report_schedule.run_not_delivered', $s, [
                'run_id' => $run->id, 'period' => ['from' => $run->periodFrom(), 'to' => $run->periodTo()],
                'failed' => $deliveries->where('status', ReportScheduleDelivery::FAILED)->mapWithKeys(fn ($d) => [(int) $d->user_id => $d->reason])->all(),
                'skipped' => $deliveries->where('status', ReportScheduleDelivery::SKIPPED)->mapWithKeys(fn ($d) => [(int) $d->user_id => $d->reason])->all(),
                'counted' => $notCounted === null, 'not_counted_reason' => $notCounted, 'consecutive_failures' => (int) $s->consecutive_failures, 'paused' => $paused,
            ], $run->auditBranchId());
            $alert = [$s->id, $s->name, $failed];

            return true;
        });
        if ($won && $alert) {
            [$id, $name, $failed] = $alert;
            $this->alertOwner($id, N::SCHEDULE_RUN_FAILED, "Scheduled report {$name} was not delivered",
                'No email of this run was confirmed as sent ('.$failed.' failed). An email that "may have been sent" must be checked with the recipient.', true);
        }

        return $won;
    }

    // ------------------------------------------------------------------ files (R12)

    /**
     * exports:prune: a run's PDF goes `keep_file_days` after the run finished, when no delivery is queued or sending. The
     * row keeps everything else; one `report_schedule.files_removed` system row per pass.
     */
    public function pruneFiles(): int
    {
        $disk = Storage::disk(Export::DISK);
        $cut = now()->subDays((int) config('scheduled_reports.keep_file_days', 7));
        $removed = [];
        ReportScheduleRun::whereNotNull('file_path')->whereNotNull('finished_at')->where('finished_at', '<=', $cut)
            ->whereNotExists(fn ($q) => $q->from('report_schedule_deliveries as d')->whereColumn('d.run_id', 'report_schedule_runs.id')
                ->whereIn('d.status', [ReportScheduleDelivery::QUEUED, ReportScheduleDelivery::SENDING]))
            ->orderBy('finished_at')->get()
            ->each(function (ReportScheduleRun $run) use ($disk, &$removed) {
                $path = $run->file_path;
                if (! ReportScheduleRun::whereKey($run->id)->where('file_path', $path)->update(['file_path' => null, 'file_removed_at' => now(), 'updated_at' => now()])) {
                    return;
                }
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
                $removed[] = $run->id;
            });
        if ($removed) {
            AuditLog::record('report_schedule.files_removed', null, null, ['run_ids' => $removed, 'count' => count($removed), 'by_system' => true], null,
                ['user_id' => null, 'ip' => null, 'user_agent' => 'console']);
        }

        return count($removed);
    }

    /** @return string[] files the exports orphan step must keep (runs whose PDF is still kept) */
    public static function keptFiles(): array
    {
        return ReportScheduleRun::whereNotNull('file_path')->pluck('file_path')->all();
    }

    private function deletePart(ReportScheduleRun $run): void
    {
        try {
            $disk = Storage::disk(Export::DISK);
            if ($disk->exists($run->partPath())) {
                $disk->delete($run->partPath());
            }
        } catch (Throwable $t) {
            report($t);
        }
    }

    // ------------------------------------------------------------------ labels

    /** "20 Nov 2026" for one day, "16 Nov 2026 to 22 Nov 2026" for a range (date format setting). */
    public static function periodLabel(string $from, string $to): string
    {
        $s = app(Settings::class);

        return $from === $to ? $s->formatDate($from) : $s->formatDate($from).' to '.$s->formatDate($to);
    }

    /** "All branches" (none chosen), else the codes. */
    public static function branchesLabel(ReportScheduleRun $run): string
    {
        $explicit = (array) (((array) $run->params)['branches'] ?? []);
        if (! $explicit) {
            return 'All branches';
        }

        return self::codes($explicit)->implode(', ');
    }

    /** R7 / accounting review A7: `<report>_<branches>_<from>_<to>.pdf`; branches = the code, up to 3 codes joined by "-", else ALL. */
    public static function attachmentName(ReportScheduleRun $run): string
    {
        $explicit = (array) (((array) $run->params)['branches'] ?? []);
        $part = $explicit && count($explicit) <= 3 ? self::codes($explicit)->implode('-') : 'ALL';

        return "{$run->report}_{$part}_{$run->periodFrom()}_{$run->periodTo()}.pdf";
    }

    /** R7: the report screen with the period and the branch scope (W1); the user must sign in. */
    public static function reportLink(ReportScheduleRun $run): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $params = (array) $run->params;
        $url = "{$base}/reports/{$run->report}?from={$run->periodFrom()}&to={$run->periodTo()}";
        // Review N4: the schedule's own filter, so the screen opens as the report was built.
        $filter = '';
        if ($run->report === 'income-expense' && ($params['type'] ?? 'all') !== 'all') {
            $filter = '&type='.rawurlencode((string) $params['type']);
        }
        if ($run->report === 'transfers') {
            foreach ((array) ($params['types'] ?? []) as $t) {
                $filter .= '&types='.rawurlencode((string) $t);
            }
        }
        if ($run->branch_id) {
            return "{$url}&branch={$run->branch_id}{$filter}";
        }
        $url .= '&branch=all';
        foreach ((array) ($params['branches'] ?? []) as $id) {
            $url .= '&branches='.(int) $id;
        }

        return $url.$filter;
    }

    private static function codes(array $ids)
    {
        return Branch::whereIn('id', array_map('intval', $ids))->get()->sortBy(fn (Branch $b) => [$b->is_main ? 0 : 1, $b->code])->pluck('code')->values();
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? number_format($bytes / 1024 / 1024, 1).' MB' : max(1, (int) ceil($bytes / 1024)).' KB';
    }

    // ------------------------------------------------------------------ audit and alerts (R13, S5)

    /** A system event: `by_system`, kept on the owner (the audit screen shows "System"). */
    private function systemAudit(string $event, ReportSchedule $s, array $values, ?int $branchId, ?array $old = null): void
    {
        AuditLog::record($event, $s, $old, $values + ['schedule' => $s->name, 'by_system' => true], $branchId,
            ['user_id' => $s->owner_id, 'ip' => null, 'user_agent' => 'console']);
    }

    private function alertSkipped(ReportSchedule $s, int $n): void
    {
        if ($n > 0) {
            $this->alertOwner($s->id, N::SCHEDULE_RECIPIENTS_SKIPPED, "{$s->name}: {$n} ".($n === 1 ? 'recipient' : 'recipients').' skipped', null, false);
        }
    }

    /**
     * S5 owner alerts. QA D7 / review N3: an owner who is no longer an active Super Admin cannot open the schedule, so
     * the alert goes to the other active Super Admins instead.
     */
    private function alertOwner(int $scheduleId, string $kind, string $title, ?string $body, bool $email): void
    {
        $s = ReportSchedule::find($scheduleId);
        if (! $s) {
            return;
        }
        $owner = User::find($s->owner_id);
        $to = $owner && $owner->status === 'active' && $owner->is_super_admin ? collect([$owner]) : $this->inApp->superAdmins();
        $this->alert($to, $s->id, $kind, $title, $body, $email);
    }

    /** S5: one bell row per active user (AG-094), plus the email when asked for. Best effort: never fails the caller. */
    private function alert(iterable $users, int $scheduleId, string $kind, string $title, ?string $body, bool $email): void
    {
        try {
            $active = $this->inApp->eligible($users, null);
            $this->inApp->send($active, null, $kind, ['title' => $title, 'body' => $body, 'branch_id' => null, 'branch_code' => null,
                'url' => "/scheduled-reports/{$scheduleId}", 'schedule_id' => $scheduleId]);
            if ($email && config('scheduled_reports.email_owner', true) && $active->isNotEmpty()) {
                Notification::send($active->values(), new ReportScheduleProblemMail($scheduleId, $title, $body));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function afterCommit(callable $fn): void
    {
        DB::afterCommit(function () use ($fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
