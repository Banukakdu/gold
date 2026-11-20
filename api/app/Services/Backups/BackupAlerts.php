<?php

namespace App\Services\Backups;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * R8: mail to every active Super Admin with sendNow (not queued). A mail failure is reported, never thrown.
 * Review M1: when the Super Admins cannot be read (the database is down), or none is active, the alert goes to
 * BACKUP_ALERT_EMAILS, which needs no database.
 */
final class BackupAlerts
{
    public static function send(BaseNotification $n): bool
    {
        try {
            $admins = User::where('is_super_admin', true)->where('status', 'active')->get();
        } catch (Throwable $e) {
            report($e);
            $admins = null; // unreadable: the database is down
        }
        if ($admins !== null && $admins->isNotEmpty()) {
            try {
                Notification::sendNow($admins, $n);

                return true;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $fallback = (array) config('backup.alert_emails', []);
        if ($fallback === []) {
            // R12: no active Super Admin and nothing failed is not an error (the audit row is still written).
            return $admins !== null && $admins->isEmpty();
        }
        try {
            Notification::route('mail', $fallback)->notifyNow($n);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** The lock store (review M1/M5): not the database, so an outage cannot stop the run before it alerts. */
    public static function locks(): Repository
    {
        return Cache::store(config('backup.lock_store') ?: null);
    }
}
