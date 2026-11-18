<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\PasswordResets;
use App\Support\LoginThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Password reset spec N6 / accounting review finding 2: recovery by someone with server access. The only route for a
 * Super Admin while email reset is off (R11), and how the bootstrap Super Admin replaces ADMIN_PASSWORD.
 */
class AuthResetPassword extends Command
{
    protected $signature = 'auth:reset-password {email : The sign-in email of the user}';

    protected $description = 'Set a new password for a user (hidden input), revoke their sessions and notify them';

    public function handle(): int
    {
        $user = User::findByEmail((string) $this->argument('email'));
        if (! $user) {
            $this->error('No user with this email.');

            return self::FAILURE;
        }

        $input = ['password' => (string) $this->secret('New password'), 'password_confirmation' => (string) $this->secret('Confirm new password')];
        $validator = Validator::make($input, ['password' => ['required', 'string', PasswordRule::defaults(), 'confirmed']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        $revoked = DB::transaction(function () use ($user, $input) {
            $revoked = PasswordResets::setPassword($user, $input['password']);
            Password::broker()->getRepository()->delete($user); // any pending reset or invitation link
            // No actor: the console has no signed-in user (auth()->id() is null).
            AuditLog::record('auth.password_reset', $user, null, [
                'user_id' => $user->id, 'email' => $user->email, 'via' => 'console', 'tokens_revoked' => $revoked,
            ], null, ['ip' => null, 'user_agent' => 'console']); // reviewer N6: not the CLI's 127.0.0.1 / Symfony agent

            return $revoked;
        });
        LoginThrottle::clearAll($user->email);
        PasswordResets::notify($user, null, 'console');

        $this->info("Password changed for {$user->email}. {$revoked} session(s) signed out.");
        if ($user->status !== 'active') {
            $this->warn('This user is inactive and still cannot sign in until an administrator activates them.');
        }

        return self::SUCCESS;
    }
}
