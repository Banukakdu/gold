<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Services\AccessAlerts;
use App\Services\PasswordResets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Password;

/**
 * N1 job (password reset spec section 5): all user-dependent work of "Forgot password" runs here, so the request's
 * response time does not depend on the account (R2). The requester's IP and user agent come from the request,
 * because AuditLog::record would otherwise read the worker's (accounting review finding 3).
 */
class SendPasswordResetLink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $email, public ?string $ip, public ?string $userAgent) {}

    public function handle(): void
    {
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($this->email)])->first();
        if (! $user) {
            return; // no audit row: unknown addresses stay out of the log (Q8)
        }
        if ($reason = PasswordResets::refusal($user)) {
            $this->audit('auth.password_reset_refused', $user, ['email' => $user->email, 'reason' => $reason]);
            if ($reason === 'super_admin_email_reset_disabled') {
                AccessAlerts::passwordResetRefused($user); // R13c
            }

            return;
        }
        $broker = Password::broker();
        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            return; // Password::RESET_THROTTLED: quietly, 60 s per account
        }
        // Replaces any older row for the email (an invitation included, R6). Only a hash is stored (R1).
        $token = $broker->createToken($user);
        $this->audit('auth.password_reset_requested', $user, ['email' => $user->email]);
        try {
            $user->notify(new PasswordResetLinkNotification($token));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function audit(string $event, User $user, array $new): void
    {
        AuditLog::record($event, $user, null, $new, null, $this->ip, $this->userAgent);
    }
}
