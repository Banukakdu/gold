<?php

namespace App\Services\Backups;

use App\Models\User;
use App\Notifications\BackupAlertNotification;
use App\Notifications\InAppNotification;
use App\Services\InAppNotifications;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
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
        $sent = self::mail($n, $admins);
        // AG-094 R4: the in-app copy is written after the mail attempt, in its own try/catch, and is not a channel of
        // the notification: a database error here must never make the mail look failed (and send the fallback twice).
        self::inApp($n, $admins);

        return $sent;
    }

    private static function mail(BaseNotification $n, ?Collection $admins): bool
    {
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

    /**
     * AG-094 R1 / R4: a backup or restore-test failure also appears in each active Super Admin's bell, with the email
     * subject as title and the stage and time only (never the error text). Nothing is written when the Super Admins
     * could not be read: the database is down and the email is the alert.
     */
    private static function inApp(BaseNotification $n, ?Collection $admins): void
    {
        if (! $n instanceof BackupAlertNotification || $admins === null || $admins->isEmpty()) {
            return;
        }
        try {
            app(InAppNotifications::class)->send($admins, null, InAppNotification::BACKUP_FAILED, [
                'title' => $n->subject(), 'body' => "Stage: {$n->stage}, {$n->at}", 'branch_id' => null, 'branch_code' => null, 'url' => '/backups',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The lock store (review M1/M5): not the database, so an outage cannot stop the run before it alerts. */
    public static function locks(): Repository
    {
        return Cache::store(config('backup.lock_store') ?: null);
    }
}
