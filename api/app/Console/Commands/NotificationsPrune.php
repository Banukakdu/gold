<?php

namespace App\Console\Commands;

use App\Services\InAppNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AG-094 R11 (daily 02:30 Asia/Colombo): permanently deletes in-app notification rows created more than 90 days ago,
 * read or unread, in chunks, and logs the count.
 *
 * EXCEPTION to the "nothing is hard-deleted" rule (CLAUDE.md), decided by the accounting review of
 * docs/specs/notifications.md (Q1, finding 5) and limited to exactly this: a notification is a per-user inbox pointer
 * to an event that is already recorded and audited elsewhere, not an accounting record or audit evidence (R10). This
 * command deletes from the `notifications` table only, selects by `created_at` only and never cascades; it never
 * touches transactions, ledger_entries, accounts, audit_logs, exports or any other table. It is NOT a precedent for
 * deleting anything else. No audit row is written for the prune (R10).
 */
class NotificationsPrune extends Command
{
    public const CHUNK = 1000;

    protected $signature = 'notifications:prune';

    protected $description = 'Delete in-app notifications older than 90 days (notifications table only)';

    public function handle(): int
    {
        $cutoff = now()->subDays(InAppNotifications::RETENTION_DAYS);
        $deleted = 0;
        do {
            $ids = DB::table('notifications')->where('created_at', '<', $cutoff)->orderBy('created_at')->limit(self::CHUNK)->pluck('id');
            if ($ids->isNotEmpty()) {
                $deleted += DB::table('notifications')->whereIn('id', $ids->all())->delete();
            }
        } while ($ids->count() === self::CHUNK);

        Log::info("notifications:prune deleted {$deleted} notification(s) created before {$cutoff->toIso8601String()}.");
        $this->info("Deleted {$deleted} notifications older than ".InAppNotifications::RETENTION_DAYS.' days.');

        return self::SUCCESS;
    }
}
