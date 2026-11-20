<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\AccessChangedNotification;
use Illuminate\Support\Facades\Notification;

/** Emails every active Super Admin when a non-Super Admin changes access (AC 48). */
class AccessAlerts
{
    public static function notify(string $event, ?int $branchId, string $target, ?array $old, ?array $new): void
    {
        $actor = auth()->user();
        if (! $actor || $actor->is_super_admin) {
            return;
        }
        $admins = User::where('is_super_admin', true)->where('status', 'active')->get();
        if ($admins->isEmpty()) {
            return;
        }
        $branch = $branchId ? (Branch::find($branchId)?->code ?? (string) $branchId) : 'templates';
        try {
            // Queued after commit; the audit row is already written, so a mail failure is only reported.
            Notification::send($admins, new AccessChangedNotification($event, "{$actor->name} <{$actor->email}>", $branch, $target, $old, $new));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
