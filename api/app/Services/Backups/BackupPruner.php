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
 * R7 local retention (`backup:prune`). The ONLY code that deletes on the local backups disk (AC 19 checks this).
 * It never touches the off-server disk: off-server retention is a bucket lifecycle rule.
 */
class BackupPruner
{
    public const CLIENT = ['ip' => null, 'user_agent' => 'artisan backup:prune'];

    /** @param  Closure(string, string): void  $say */
    public function prune(Closure $say): int
    {
        $now = now();
        $succeeded = Backup::succeeded()->orderByDesc('started_at')->orderByDesc('id')->get();
        $keep = $succeeded->take((int) config('backup.min_keep', 7))->pluck('id')->all();
        $exit = 0;
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
                $disk->delete([$b->filename, $b->filename.'.sha256', $b->filename.'.manifest.json']);
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
