<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Support\BusinessDate;
use App\Support\DummyHash;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    /**
     * N2 accepts a token only when N1 would send one now (R5, R11): active, and for a Super Admin only while the switch
     * is on and another active Super Admin exists (reviewer N8: a link made earlier is refused once that changes).
     */
    public static function emailResetAllowed(User $user): bool
    {
        return self::refusal($user) === null;
    }

    /**
     * Use a reset or invitation token once (N2, N5).
     * - Reviewer R1: exactly one bcrypt check on every path, against the row's hash when a live row exists, else the
     *   dummy hash, so timing does not tell whether the account, the row or the user's state is the reason.
     * - QA Q1: the row is read FOR UPDATE and only the request whose conditional delete removes it may go on, so two
     *   parallel submits (MySQL or SQLite) consume the token once; the loser gets the normal token error.
     * - A database error is re-thrown without the original exception: its SQL bindings may hold the new bcrypt hash,
     *   which must never reach the log.
     *
     * @param  Closure(User): void  $apply  runs inside the transaction after the token is consumed
     */
    public static function consume(?User $user, string $email, string $token, bool $allowed, Closure $apply): bool
    {
        $table = config('auth.passwords.'.config('auth.defaults.passwords').'.table');
        $email = $user?->email ?? $email;
        try {
            return DB::transaction(function () use ($table, $user, $email, $token, $allowed, $apply) {
                $row = DB::table($table)->where('email', $email)->lockForUpdate()->first();
                $live = $row && ! self::expired($row->created_at);
                $match = $live ? Hash::check($token, $row->token) : DummyHash::check($token);
                if (! $user || ! $allowed || ! $match) {
                    return false;
                }
                if (DB::table($table)->where('email', $email)->where('token', $row->token)->delete() !== 1) {
                    return false; // a parallel request used it first
                }
                $apply($user);

                return true;
            });
        } catch (QueryException $e) {
            throw new \RuntimeException('The password could not be saved (database error '.$e->getCode().').');
        }
    }

    private static function expired(?string $createdAt): bool
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return ! $createdAt || Carbon::parse($createdAt)->addMinutes($minutes)->isPast();
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
