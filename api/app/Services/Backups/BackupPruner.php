<?php

namespace App\Services\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use App\Support\BusinessDate;
use Closure;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * R7 local retention (`backup:prune`). With LocalStore (which only removes the files of its own failed write) the ONLY
 * code that deletes on the local backups disk (AC 19 checks this). It never touches the off-server disk: off-server
 * retention is a bucket lifecycle rule. Review M2/M3: it also removes stale temp folders, `.part` files older than
 * the stale limit, and the files of runs that failed after storing (not a continuity failure, which is evidence).
 */
class BackupPruner
{
    public const CLIENT = ['ip' => null, 'user_agent' => 'artisan backup:prune'];

    /** @param  Closure(string, string): void  $say */
    public const SIDECARS = ['', '.sha256', '.manifest.json', '.audit.json'];

    /**
     * Failed runs whose files are kept: `continuity` is evidence of the rollback; `interrupted` is also what the
     * restored backup's own row looks like (it was `running` in its dump) until `backup:restored` completes it.
     */
    public const KEEP_FAILED = ['continuity', 'interrupted'];

    public function prune(Closure $say): int
    {
        $now = now();
        foreach (Workspace::sweep() as $old) {
            $say('warn', "Removed a stale temp folder left by an interrupted run ({$old}).");
        }
        $exit = $this->leftovers($say);
        $succeeded = Backup::succeeded()->orderByDesc('started_at')->orderByDesc('id')->get();
        $keep = $succeeded->take((int) config('backup.min_keep', 7))->pluck('id')->all();
        $pruned = 0;
        foreach ($succeeded as $b) {
            if ($b->local_pruned_at !== null || in_array($b->id, $keep, true)) {
                continue;
            }
            $rule = $this->rule($b, $now);
            if ($rule === null) {
                continue;
            }
            try {
                $disk = Storage::disk((string) config('backup.local_disk'));
                $disk->delete(self::names($b->filename));
                $b->update(['local_pruned_at' => $now]);
                AuditLog::record('backup.pruned', $b, null, ['filename' => $b->filename, 'started_at' => $b->started_at->toIso8601String(),
                    'kept_as' => $b->kept_as, 'rule' => $rule], null, self::CLIENT);
                $pruned++;
            } catch (Throwable $e) {
                $exit = 1;
                $msg = mb_substr($e->getMessage(), 0, 1000);
                report($e);
                AuditLog::record('backup.failed', $b, null, ['filename' => $b->filename, 'stage' => 'prune', 'error' => $msg], null, self::CLIENT);
                $say('error', "Could not prune {$b->filename}: {$msg}");
                BackupAlerts::send(new BackupAlertNotification('backup', 'prune', $b->filename,
                    BusinessDate::now()->format('Y-m-d H:i'), $msg));
            }
        }
        $say('info', "Pruned {$pruned} local backup(s).");

        return $exit;
    }

    /** @return string[] the archive and its sidecars */
    public static function names(string $filename): array
    {
        return array_map(fn ($s) => $filename.$s, self::SIDECARS);
    }

    /** Review M3: partial files, files of failed runs, and (warned, kept) archives without a row. */
    private function leftovers(Closure $say): int
    {
        $exit = 0;
        $disk = Storage::disk((string) config('backup.local_disk'));
        $limit = time() - (int) config('backup.stale_hours', 6) * 3600;
        try {
            $files = $disk->files();
            foreach ($files as $f) {
                if (str_ends_with($f, '.part') && $disk->lastModified($f) < $limit) {
                    $disk->delete($f);
                    $say('warn', "Removed a partial file left by an interrupted run ({$f}).");
                }
            }
            $archives = array_values(array_filter($files, fn ($f) => preg_match('/^ag-backup-\d{8}-\d{6}\.zip$/', $f)));
            $rows = Backup::whereIn('filename', $archives)->get()->keyBy('filename');
            foreach ($archives as $f) {
                $b = $rows[$f] ?? null;
                if ($b === null) {
                    $say('warn', "{$f} has no backup row; it is kept. Check it by hand.");
                } elseif ($b->status === Backup::FAILED && $b->local_pruned_at === null && ! in_array($b->failed_stage, self::KEEP_FAILED, true)) {
                    $disk->delete(self::names($f));
                    $b->update(['local_pruned_at' => now()]);
                    AuditLog::record('backup.pruned', $b, null, ['filename' => $f, 'started_at' => $b->started_at->toIso8601String(),
                        'kept_as' => $b->kept_as, 'rule' => 'failed_run'], null, self::CLIENT);
                    $say('info', "Removed the files of failed run {$f}.");
                }
            }
        } catch (Throwable $e) {
            report($e);
            $exit = 1;
            $say('error', 'Could not clean up leftover backup files: '.mb_substr($e->getMessage(), 0, 500));
        }

        return $exit;
    }

    /** daily_30 / monthly_12 / year_end_13 (only once its off-server copy is `copied`, review F1), or null to keep. */
    public function rule(Backup $b, $now): ?string
    {
        $started = $b->started_at;

        return match ($b->kept_as) {
            Backup::DAILY => $started->lt($now->copy()->subDays((int) config('backup.keep_daily', 30))) ? 'daily_30' : null,
            Backup::MONTHLY => $started->lt($now->copy()->subMonths((int) config('backup.keep_monthly', 12))) ? 'monthly_12' : null,
            Backup::YEAR_END => $b->offsite_status === Backup::COPIED
                && $started->lt($now->copy()->subMonths((int) config('backup.keep_year_end_months', 13))) ? 'year_end_13' : null,
            default => null,
        };
    }
}
