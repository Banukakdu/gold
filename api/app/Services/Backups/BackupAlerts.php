<?php

namespace App\Services\Backups;

use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** R8: mail to every active Super Admin with sendNow (not queued). A mail failure is reported, never thrown. */
final class BackupAlerts
{
    public static function send(BaseNotification $n): bool
    {
        $admins = User::where('is_super_admin', true)->where('status', 'active')->get();
        if ($admins->isEmpty()) {
            return true;
        }
        try {
            Notification::sendNow($admins, $n);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
