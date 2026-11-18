<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Support\BusinessDate;

/** Shared steps of a password reset by email (N2) and by the console (N6), docs/specs/password-reset.md. */
final class PasswordResets
{
    public const TOKEN_MESSAGE = 'This reset link is invalid or has expired.';

    /** Reasons N1 refuses to send a link (R5, R11), or null when a link may be sent. */
    public static function refusal(User $user): ?string
    {
        return match (true) {
            $user->status !== 'active' => 'inactive',
            $user->is_super_admin && ! config('auth.super_admin_email_reset') => 'super_admin_email_reset_disabled',
            $user->is_super_admin && ! User::where('is_super_admin', true)->where('status', 'active')->whereKeyNot($user->id)->exists() => 'no_other_super_admin',
            default => null,
        };
    }

    /** N2 accepts a token only for an active user, and for a Super Admin only while the switch is on (R5, R11). */
    public static function emailResetAllowed(User $user): bool
    {
        return $user->status === 'active' && (! $user->is_super_admin || config('auth.super_admin_email_reset'));
    }

    /** Inside the caller's transaction: new password and every API token revoked (R8). Returns the revoked count. */
    public static function setPassword(User $user, string $password): int
    {
        $user->forceFill(['password' => $password])->save();

        return $user->tokens()->delete();
    }

    /** After commit (R13): "password changed" to the user and the Super Admin alert. Mail failures are only reported. */
    public static function notify(User $user, ?string $ip, string $via): void
    {
        $when = BusinessDate::now()->format('Y-m-d H:i').' ('.BusinessDate::timezone().')';
        try {
            $user->notify(new PasswordChangedNotification($when, $ip));
        } catch (\Throwable $e) {
            report($e);
        }
        AccessAlerts::passwordReset($user, $via);
    }
}
