<?php

namespace App\Services\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\YearEndClose;
use App\Notifications\BackupAlertNotification;
use App\Notifications\YearEndSnapshotNotification;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * `backup:run` (docs/specs/backups.md F1, R1-R9, R13, R14). One run = one encrypted archive of the database dump,
 * control.json, the manifest and every attachment and logo file, copied off-server and recorded on a `backups` row.
 */
class BackupRunner
{
    public const LOCK = 'backup:run';

    public const CLIENT = ['ip' => null, 'user_agent' => 'artisan backup:run'];

    /** @var Closure(string, string): void */
    private Closure $say;

    public function __construct(private BackupArchive $archive, private OffsiteStore $offsite, private BackupPruner $pruner,
        private BackupVerifier $verifier) {}

    /**
     * @param  int|true|null  $yearEnd  --year-end: true = the last ended fiscal year, an int = that start year
     * @param  Closure(string, string): void  $say  (level info|warn|error, message)
     * @return int exit code
     */
    public function run(string $triggeredBy, int|true|null $yearEnd, Closure $say): int
    {
        $this->say = $say;
        $lock = Cache::lock(self::LOCK, (int) config('backup.stale_hours', 6) * 3600);
        if (! $lock->get()) {
            $say('error', 'A backup is already running.');

            return 1;
        }
        try {
            $exit = $this->failStale();
            $forced = null;
            if ($yearEnd !== null) {
                $settings = app(Settings::class);
                $fy = $yearEnd === true ? $settings->currentFiscalYear()->previous() : $settings->fiscalYearStarting($yearEnd);
                $forced = YearEndClose::active()->where('fiscal_year', $fy->startYear)->first();
                if (! $forced) {
                    $say('error', "{$fy->label()} is not closed.");

                    return 1;
                }
            }
            $now = BusinessDate::now();
            try {
                $backup = Backup::create(['filename' => 'ag-backup-'.$now->format('Ymd-His').'.zip', 'status' => Backup::RUNNING,
                    'triggered_by' => $triggeredBy, 'started_at' => $now->utc(), 'db_driver' => DB::connection()->getDriverName(), 'encrypted' => false]);
            } catch (UniqueConstraintViolationException) {
                $say('error', 'A backup is already running.');

                return 1;
            }

            $exit = max($exit, $this->execute($backup, $forced));
            $say('info', 'Pruning local backups.');

            return max($exit, $this->pruner->prune($say));
        } finally {
            $lock->release();
        }
    }

    /** R1 step 2: `running` rows older than the lock are failed. Returns 1 when any was found. */
    private function failStale(): int
    {
        $stale = Backup::where('status', Backup::RUNNING)->where('started_at', '<', now()->subHours((int) config('backup.stale_hours', 6)))->get();
        foreach ($stale as $b) {
            $this->fail($b, 'interrupted', 'Interrupted: the run did not finish.');
        }

        return $stale->isEmpty() ? 0 : 1;
    }

    private function execute(Backup $backup, ?YearEndClose $forced): int
    {
        $say = $this->say;
        $tmp = null;
        $stage = 'precheck';
        try {
            $tmp = Workspace::create();
            $dumper = $this->dumper();
            $password = $this->precheck($dumper);
            $continuity = $this->continuity();
            if ($continuity) {
                $say('warn', 'Continuity check failed: '.$continuity['message'].' The backup is still taken.');
            }
            $close = $forced ?? $this->pendingYearEnd($backup->started_at);
            $maxIds = ControlTotals::maxIds();

            $stage = 'dump';
            $say('info', "Dumping the {$dumper->driver()} database.");
            $dbPath = $tmp.'/'.$dumper->entryName();
            $dumper->dump($dbPath);
            // R14: the close must still be in force after the dump (a reopen in between makes it a plain backup).
            if ($close && $close->fresh()?->status !== YearEndClose::CLOSED) {
                $say('warn', 'The fiscal year was reopened during the run; this is not a year-end snapshot.');
                $close = null;
            }

            $stage = 'archive';
            $control = ControlTotals::compute($maxIds);
            file_put_contents($tmp.'/control.json', json_encode($control, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            [$fileEntries, $filesInfo] = $this->files();
            $manifest = $this->manifest($backup, $dumper, $dbPath, $tmp.'/control.json', $maxIds, $filesInfo, $close, $continuity);
            file_put_contents($tmp.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zipPath = $tmp.'/'.$backup->filename;
            $entries = [[$dumper->entryName(), $dbPath, true], ['manifest.json', $tmp.'/manifest.json', true], ['control.json', $tmp.'/control.json', true],
                ...$fileEntries];
            $this->archive->build($zipPath, $entries, $password);

            $stage = 'verify_archive';
            $problem = $this->archive->selfCheck($zipPath, $password, 3 + $manifest['files_count'],
                [$dumper->entryName() => $manifest['db_sha256'], 'control.json' => $manifest['control_sha256']]);
            if ($problem !== null) {
                throw new BackupException($problem, 'verify_archive');
            }

            $stage = 'store';
            $sha = hash_file('sha256', $zipPath);
            $size = filesize($zipPath);
            file_put_contents($tmp.'/sha', "{$sha}  {$backup->filename}\n");
            $local = Storage::disk((string) config('backup.local_disk'));
            foreach ([[$zipPath, $backup->filename], [$tmp.'/sha', $backup->filename.'.sha256'], [$tmp.'/manifest.json', $backup->filename.'.manifest.json']] as [$from, $to]) {
                $in = fopen($from, 'rb');
                $local->writeStream($to, $in);
                is_resource($in) && fclose($in);
            }
            $firstOfMonth = $this->firstOfMonth($backup);
            $backup->fill([
                'size_bytes' => $size, 'sha256' => $sha, 'encrypted' => $password !== null, 'files_count' => $manifest['files_count'],
                'kept_as' => $close ? Backup::YEAR_END : ($firstOfMonth ? Backup::MONTHLY : Backup::DAILY),
                'fiscal_year' => $close?->fiscal_year, 'year_end_close_id' => $close?->id, 'manifest' => $manifest,
            ])->save();

            $stage = 'offsite';
            $offsiteError = $this->copyOffsite($backup, $close, $firstOfMonth, [$zipPath, $tmp.'/sha', $tmp.'/manifest.json']);

            $unbalanced = ControlTotals::unbalanced($control);
            $controlError = $unbalanced ? 'Debits differ from credits in: '.implode('; ', $unbalanced).'.' : null;
            $backup->fill([
                'status' => $continuity ? Backup::FAILED : Backup::SUCCEEDED,
                'failed_stage' => $continuity ? 'continuity' : ($controlError ? 'control' : null),
                'error' => $continuity['message'] ?? $controlError,
                'finished_at' => now(),
            ])->save();
            Workspace::remove($tmp);
            $tmp = null;

            return $this->report($backup, $continuity, $controlError, $offsiteError, $unbalanced, $control);
        } catch (Throwable $e) {
            $msg = $e instanceof BackupException ? $e->getMessage() : mb_substr($e->getMessage(), 0, 1000);
            if (! $e instanceof BackupException) {
                report($e);
            }
            $this->fail($backup, $e instanceof BackupException ? $e->stage : $stage, $msg);

            return 1;
        } finally {
            Workspace::remove($tmp);
        }
    }

    /** Audit rows, alerts and (for a year-end snapshot) the immediate restore test and the hash email. */
    private function report(Backup $backup, ?array $continuity, ?string $controlError, ?string $offsiteError, array $unbalanced, array $control): int
    {
        $say = $this->say;
        $exit = 0;
        AuditLog::record('backup.created', $backup, null, [
            'filename' => $backup->filename, 'size_bytes' => $backup->size_bytes, 'sha256' => $backup->sha256, 'db_driver' => $backup->db_driver,
            'encrypted' => $backup->encrypted, 'files_count' => $backup->files_count, 'kept_as' => $backup->kept_as,
            'offsite_status' => $backup->offsite_status, 'duration_seconds' => $backup->durationSeconds(), 'triggered_by' => $backup->triggered_by,
        ], null, self::CLIENT);
        $say('info', "Backup {$backup->filename} written (".number_format((int) $backup->size_bytes).' bytes, sha256 '.$backup->sha256.').');

        if ($offsiteError !== null) {
            $exit = 1;
            AuditLog::record('backup.offsite_failed', $backup, null, ['filename' => $backup->filename, 'error' => $offsiteError], null, self::CLIENT);
            $this->alert('backup', 'offsite', $backup, $offsiteError);
            $say('error', "Off-server copy failed: {$offsiteError}");
        }
        if ($controlError !== null) {
            $exit = 1;
            AuditLog::record('backup.control_failed', $backup, null, ['filename' => $backup->filename, 'error' => $controlError, 'branches' => $unbalanced], null, self::CLIENT);
            $this->alert('backup', 'control', $backup, $controlError);
            $say('error', $controlError);
        }
        if ($continuity !== null) {
            $exit = 1;
            AuditLog::record('backup.continuity_failed', $backup, null, ['filename' => $backup->filename] + $continuity['values'], null, self::CLIENT);
            AuditLog::record('backup.failed', $backup, null, ['filename' => $backup->filename, 'stage' => 'continuity', 'error' => $continuity['message']], null, self::CLIENT);
            $this->alert('backup', 'continuity', $backup, $continuity['message']);
            $say('error', $continuity['message']);

            return $exit;
        }

        if ($backup->kept_as === Backup::YEAR_END) {
            $label = (string) $backup->fiscalYearLabel();
            AuditLog::record('backup.year_end', $backup, null, ['filename' => $backup->filename, 'fiscal_year' => $backup->fiscal_year, 'label' => $label,
                'close_id' => $backup->year_end_close_id, 'sha256' => $backup->sha256, 'total_debit' => Money::fromCents($control['total']['debit']),
                'total_credit' => Money::fromCents($control['total']['credit'])], null, self::CLIENT);
            // Review F7: a year-end snapshot is restore-tested at once.
            $say('info', "Year-end snapshot {$label}: running the restore test.");
            if (! $this->verifier->verify($backup->filename, $backup, $say, 'artisan backup:run')) {
                $exit = 1;
            }
            // Review F8: the hash, held by the business outside the server.
            $total = $control['total']['debit'] === $control['total']['credit'] ? Money::format(Money::fromCents($control['total']['debit']))
                : Money::format(Money::fromCents($control['total']['debit'])).' (debits) / '.Money::format(Money::fromCents($control['total']['credit'])).' (credits)';
            if (! BackupAlerts::send(new YearEndSnapshotNotification($label, $backup->filename, (string) $backup->sha256, $total))) {
                $exit = 1;
            }
        }

        return $exit;
    }

    private function dumper(): DatabaseDumper
    {
        return app(DatabaseDumper::class);
    }

    /** R1 step 4 + review F6. Returns the archive password (null = unencrypted, allowed outside production only). */
    private function precheck(DatabaseDumper $dumper): ?string
    {
        $password = (string) config('backup.password');
        if ($password === '') {
            if (app()->environment('production')) {
                throw new BackupException('BACKUP_ARCHIVE_PASSWORD is not set.', 'precheck');
            }
            ($this->say)('warn', 'BACKUP_ARCHIVE_PASSWORD is empty: the archive is NOT encrypted (refused in production).');
            $password = null;
        } elseif (mb_strlen($password) < (int) config('backup.min_password_length', 20)) {
            throw new BackupException('BACKUP_ARCHIVE_PASSWORD must be at least '.config('backup.min_password_length', 20).' characters.', 'precheck');
        }
        if (DB::connection()->getDriverName() === 'sqlite' && in_array(DB::connection()->getConfig('database'), [':memory:', ''], true)) {
            throw new BackupException('The database is in memory (:memory:); there is nothing to back up.', 'precheck');
        }
        $local = Storage::disk((string) config('backup.local_disk'));
        $root = $local->path('');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $last = (int) Backup::succeeded()->orderByDesc('started_at')->value('size_bytes');
        $need = $last > 0 ? 2 * $last : (int) config('backup.first_run_free_bytes');
        $free = (int) @disk_free_space($root);
        if ($free < $need) {
            throw new BackupException('Not enough free disk space: '.self::bytes($free).' free, '.self::bytes($need).' needed.', 'precheck');
        }
        foreach ($dumper->nonTransactionalTables() as $t) {
            throw new BackupException("Table {$t} is not InnoDB.", 'precheck');
        }

        return $password;
    }

    /**
     * R13 / review F4. The newest manifest (local, else off-server) that is not itself a continuity failure. Raised when
     * the live max id of audit_logs or ledger_entries is lower than that manifest's, unless a `backup.restored` row
     * written after that backup names a backup at or before it.
     *
     * @return array{message: string, values: array}|null
     */
    private function continuity(): ?array
    {
        $local = Storage::disk((string) config('backup.local_disk'));
        $manifests = collect($local->files())->filter(fn ($f) => str_ends_with($f, '.manifest.json'))->sortDesc()
            ->map(fn ($f) => json_decode((string) $local->get($f), true))->filter(fn ($m) => is_array($m))->values()->all();
        if ($manifests === [] && $this->offsite->configured()) {
            try {
                $manifests = $this->offsite->manifests();
            } catch (Throwable $e) {
                report($e);
            }
        }
        $m = collect($manifests)->first(fn ($m) => empty($m['continuity_failed']));
        if (! $m || empty($m['backup']) || empty($m['max_ids'])) {
            return null;
        }
        foreach (['audit_logs', 'ledger_entries'] as $table) {
            $live = (int) DB::table($table)->max('id');
            $was = (int) ($m['max_ids'][$table] ?? 0);
            if ($live >= $was) {
                continue;
            }
            $since = CarbonImmutable::parse((string) $m['created_at'])->utc();
            $declared = AuditLog::where('event', 'backup.restored')->where('created_at', '>=', $since->format('Y-m-d H:i:s'))->get(['new_values'])
                ->contains(fn (AuditLog $a) => strcmp((string) ($a->new_values['filename'] ?? "\u{FFFF}"), (string) $m['backup']) <= 0);
            if ($declared) {
                return null;
            }

            return [
                'message' => "Database rolled back without a recorded restore: {$table} max id is {$live}, backup {$m['backup']} had {$was}.",
                'values' => ['newer_backup' => $m['backup'], 'table' => $table, 'live_max_id' => $live, 'backup_max_id' => $was],
            ];
        }

        return null;
    }

    /** R14: the oldest close in force before the run start that has no succeeded year-end snapshot yet. */
    private function pendingYearEnd($startedAt): ?YearEndClose
    {
        return YearEndClose::active()->where('closed_at', '<', $startedAt)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('backups')->whereColumn('backups.year_end_close_id', 'year_end_closes.id')
                ->where('backups.status', Backup::SUCCEEDED)->where('backups.kept_as', Backup::YEAR_END))
            ->orderBy('closed_at')->orderBy('id')->first();
    }

    /** R7: no other succeeded backup started earlier in the same calendar month (business timezone). */
    private function firstOfMonth(Backup $backup): bool
    {
        $start = $backup->started_at->copy()->setTimezone(BusinessDate::timezone())->startOfMonth()->utc();

        return ! Backup::succeeded()->whereKeyNot($backup->id)->where('started_at', '>=', $start)->where('started_at', '<', $backup->started_at)->exists();
    }

    /** @return array{0: array, 1: array} [zip entries, per-disk counts and bytes] */
    private function files(): array
    {
        $entries = [];
        $info = [];
        foreach (['attachments', 'branding'] as $name) {
            $disk = Storage::disk($name);
            $count = 0;
            $bytes = 0;
            foreach ($disk->allFiles() as $path) {
                if (str_starts_with(basename($path), '.')) {
                    continue; // .gitignore and the like
                }
                $full = $disk->path($path);
                $entries[] = ["files/{$name}/{$path}", $full, false];
                $count++;
                $bytes += (int) filesize($full);
            }
            $info[$name] = ['files_count' => $count, 'files_bytes' => $bytes];
        }

        return [$entries, $info];
    }

    private function manifest(Backup $backup, DatabaseDumper $dumper, string $dbPath, string $controlPath, array $maxIds, array $files,
        ?YearEndClose $close, ?array $continuity): array
    {
        $fy = $close ? app(Settings::class)->fiscalYearStarting((int) $close->fiscal_year) : null;

        return [
            'backup' => $backup->filename,
            'app_version' => self::appVersion(),
            'created_at' => $backup->started_at->toIso8601String(),
            'db_driver' => $dumper->driver(),
            'db_entry' => $dumper->entryName(),
            'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'db_sha256' => hash_file('sha256', $dbPath),
            'control_sha256' => hash_file('sha256', $controlPath),
            'counts' => ControlTotals::counts($maxIds),
            'max_ids' => $maxIds,
            'files' => $files,
            'files_count' => array_sum(array_column($files, 'files_count')),
            'year_end' => $close ? ['fiscal_year' => (int) $close->fiscal_year, 'label' => $fy->label(), 'close_id' => $close->id,
                'closed_at' => $close->closed_at?->toIso8601String(), 'closed_by' => $close->closedBy?->name] : null,
            'continuity_failed' => $continuity ? $continuity['values'] : null,
        ];
    }

    /**
     * R6: daily/ always, monthly/ for the first backup of a month, yearly/FY.../ for a year-end snapshot; archive and
     * both sidecars each. An off-server failure does not fail the backup. Returns the error, or null.
     */
    private function copyOffsite(Backup $backup, ?YearEndClose $close, bool $firstOfMonth, array $locals): ?string
    {
        if (! $this->offsite->configured()) {
            $backup->update(['offsite_status' => Backup::NOT_CONFIGURED]);
            ($this->say)('warn', 'No off-server disk is configured (BACKUP_OFFSITE_DISK): the backup is kept only on this server.');

            return null;
        }
        $prefixes = ['daily'];
        if ($firstOfMonth) {
            $prefixes[] = 'monthly';
        }
        if ($close) {
            $prefixes[] = 'yearly/FY'.app(Settings::class)->fiscalYearStarting((int) $close->fiscal_year)->shortLabel();
        }
        $names = [$backup->filename, $backup->filename.'.sha256', $backup->filename.'.manifest.json'];
        $pairs = [];
        foreach ($prefixes as $p) {
            foreach ($locals as $i => $local) {
                $pairs[] = [$local, "{$p}/{$names[$i]}"];
            }
        }
        try {
            $this->offsite->putNew($pairs);
            $backup->update(['offsite_status' => Backup::COPIED, 'offsite_error' => null]);
            ($this->say)('info', 'Copied off-server to '.implode(', ', $prefixes).'.');

            return null;
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 1000) ?: $e::class;
            if (! $e instanceof BackupException) {
                report($e);
            }
            $backup->update(['offsite_status' => Backup::OFFSITE_FAILED, 'offsite_error' => $msg]);

            return $msg;
        }
    }

    private function fail(Backup $backup, string $stage, string $error): void
    {
        $backup->update(['status' => Backup::FAILED, 'failed_stage' => $stage, 'error' => $error, 'finished_at' => now()]);
        AuditLog::record('backup.failed', $backup, null, ['filename' => $backup->filename, 'stage' => $stage, 'error' => $error], null, self::CLIENT);
        ($this->say)('error', "Backup {$backup->filename} FAILED at {$stage}: {$error}");
        $this->alert('backup', $stage, $backup, $error);
    }

    private function alert(string $kind, string $stage, Backup $backup, string $error): void
    {
        $at = $backup->started_at->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d H:i');
        if (! BackupAlerts::send(new BackupAlertNotification($kind, $stage, $backup->filename, $at, $error))) {
            ($this->say)('error', 'The alert email could not be sent; see the log.');
        }
    }

    private static function appVersion(): ?string
    {
        try {
            $p = new Process(['git', 'rev-parse', '--short', 'HEAD'], base_path());
            $p->setTimeout(5);
            $p->run();

            return $p->isSuccessful() ? trim($p->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function bytes(int $b): string
    {
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($b >= $size) {
                return number_format($b / $size, 1).' '.$unit;
            }
        }

        return $b.' B';
    }
}
