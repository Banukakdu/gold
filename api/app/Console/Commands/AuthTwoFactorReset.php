<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Console\Command;

/**
 * AG-005 C1 / accounting review 4: reset a user's two-factor sign-in from the server (for example the only Super Admin
 * lost their phone and codes). Never silent: it always writes auth.two_factor_reset (via console, os_user, host),
 * emails the user and alerts every other active Super Admin; there is no option to skip any of these.
 */
class AuthTwoFactorReset extends Command
{
    protected $signature = 'auth:two-factor-reset {email : The sign-in email of the user}
        {--reason= : How the person\'s identity was checked (10 to 500 characters)}
        {--confirm= : The email again (required with --no-interaction)}';

    protected $description = 'Turn off a user\'s two-factor sign-in, revoke their sessions and notify them and the Super Admins';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $reason = trim((string) ($this->option('reason') ?? ''));
        if ($reason === '' && $this->input->isInteractive()) {
            $reason = trim((string) $this->ask('Reason (how did you check it was really them?)'));
        }
        $length = mb_strlen($reason);
        if ($length < 10 || $length > 500) {
            $this->error('Enter a reason of 10 to 500 characters (--reason=).');

            return self::FAILURE;
        }

        $user = User::findByEmail($email);
        if (! $user) {
            $this->error('No user with this email.');

            return self::FAILURE;
        }
        if (! $user->hasTwoFactor()) {
            $this->error('Two-factor sign-in is not on for this user.');

            return self::FAILURE;
        }
        if ($user->status !== 'active') {
            $this->error('This user is inactive. Activate them first.');

            return self::FAILURE;
        }

        $lastLock = TwoFactor::latestLock($user);
        $this->line("Name: {$user->name}");
        $this->line("Email: {$user->email}");
        $this->line('Super Admin: '.($user->is_super_admin ? 'yes' : 'no'));
        $this->line('Last 2FA lock: '.($lastLock ? TwoFactor::local($lastLock->created_at) : 'none'));

        $confirm = $this->option('confirm');
        if ($confirm === null && $this->input->isInteractive()) {
            $confirm = $this->ask('Type the email again to confirm');
        }
        if (! is_string($confirm) || mb_strtolower(trim($confirm)) !== $user->email) {
            $this->error('The email does not match. Nothing was changed.');

            return self::FAILURE;
        }

        if ($lock = TwoFactor::resetBlockedBy($user)) {
            $this->error("Someone entered this user's password without the code on ".TwoFactor::local($lock->created_at).'.');
            $this->error('Reset their password first (Forgot password, or php artisan auth:reset-password '.$user->email.'), then reset two-factor.');

            return self::FAILURE;
        }

        $revoked = TwoFactor::reset($user, null, $reason, ['os_user' => self::osUser(), 'host' => (string) gethostname()]);
        $this->info("Two-factor sign-in reset for {$user->email}. {$revoked} session(s) signed out.");

        return self::SUCCESS;
    }

    private static function osUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $name = posix_getpwuid(posix_geteuid())['name'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return get_current_user() ?: 'unknown';
    }
}
