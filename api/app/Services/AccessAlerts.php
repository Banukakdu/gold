<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\AccessChangedNotification;
use App\Notifications\PasswordResetAlertNotification;
use App\Support\BranchAdmins;
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

    /**
     * Password reset spec R13b: a Super Admin's or a branch administrator's password was reset (by email or console).
     * Every OTHER active Super Admin is told; resets of other users are in the audit log only.
     */
    public static function passwordReset(User $user, string $via = 'email'): void
    {
        if (! $user->is_super_admin && ! BranchAdmins::branchesOf($user)) {
            return;
        }
        self::toOtherSuperAdmins($user, new PasswordResetAlertNotification('reset', "{$user->name} <{$user->email}>", $via));
    }

    /** R13c / accounting review finding 7: a Super Admin email reset was refused because the switch is off. */
    public static function passwordResetRefused(User $user): void
    {
        self::toOtherSuperAdmins($user, new PasswordResetAlertNotification('refused', $user->email));
    }

    private static function toOtherSuperAdmins(User $user, PasswordResetAlertNotification $n): void
    {
        $admins = User::where('is_super_admin', true)->where('status', 'active')->whereKeyNot($user->id)->get();
        if ($admins->isEmpty()) {
            return;
        }
        try {
            Notification::send($admins, $n);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
