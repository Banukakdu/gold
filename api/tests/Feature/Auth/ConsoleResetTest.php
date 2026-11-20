<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/** Password reset spec N6 / accounting review finding 2: AC 43. */
class ConsoleResetTest extends PasswordResetTestCase
{
    private function command(string $email, string $password, ?string $confirm = null)
    {
        return $this->artisan('auth:reset-password', ['email' => $email])
            ->expectsQuestion('New password', $password)
            ->expectsQuestion('Confirm new password', $confirm ?? $password);
    }

    public function test_ac43_console_reset_of_a_super_admin_while_email_reset_is_off(): void
    {
        $b = $this->secondSuperAdmin();
        $sa = $this->admin;
        $sa->createToken('web');
        $sa->createToken('web');
        Password::broker()->createToken($sa);
        foreach (['10.8.0.1', '10.8.0.2'] as $ip) {
            for ($n = 0; $n < 5; $n++) {
                $this->login($sa->email, 'wrong-pass1', $ip);
            }
            $this->login($sa->email, 'ChangeMe123', $ip)->assertStatus(429);
        }
        $this->app['auth']->forgetGuards();

        $this->command(strtoupper($sa->email), 'Recovered-42')->expectsOutputToContain('2 session(s) signed out')->assertExitCode(0);

        $this->assertTrue(Hash::check('Recovered-42', $sa->fresh()->password));
        $this->assertSame(0, $sa->tokens()->count());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $sa->email)->exists());
        foreach (['10.8.0.1', '10.8.0.2'] as $ip) {
            $this->login($sa->email, 'Recovered-42', $ip)->assertOk(); // locks of every IP are cleared
        }
        $row = AuditLog::where('event', 'auth.password_reset')->sole();
        $this->assertNull($row->user_id);
        $this->assertSame($sa->id, $row->auditable_id);
        $this->assertSame([null, 'console'], [$row->ip, $row->user_agent]); // reviewer N6
        // assertEquals: MySQL's JSON column does not keep key order.
        $this->assertEquals(['user_id' => $sa->id, 'email' => $sa->email, 'via' => 'console', 'tokens_revoked' => 2], $row->new_values);

        Notification::assertSentTo($sa, PasswordChangedNotification::class, fn ($n) => $n->ip === null && str_contains($n->text(), 'from the server console'));
        Notification::assertSentTo($b, PasswordResetAlertNotification::class, fn ($n) => $n->kind === 'reset' && $n->via === 'console');
        Notification::assertNotSentTo($sa, PasswordResetAlertNotification::class);
    }

    public function test_ac43_console_refuses_a_weak_or_mismatched_password_and_unknown_email(): void
    {
        $u = $this->member('u@x.com');
        $u->createToken('web');

        $this->command('u@x.com', 'abcdefgh')->expectsOutputToContain('at least one number')->assertExitCode(1);
        $this->command('u@x.com', 'newpass123', 'newpass124')->expectsOutputToContain('confirmation does not match')->assertExitCode(1);
        $this->artisan('auth:reset-password', ['email' => 'nobody@x.com'])->expectsOutputToContain('No user with this email.')->assertExitCode(1);

        $this->assertTrue(Hash::check('oldpass123', $u->fresh()->password));
        $this->assertSame(1, $u->tokens()->count());
        $this->assertSame(0, AuditLog::where('event', 'auth.password_reset')->count());
        Notification::assertNothingSent();
    }

    public function test_console_reset_of_a_cashier_sends_no_admin_alert(): void
    {
        $u = $this->member('u@x.com');
        $this->command('u@x.com', 'newpass123')->assertExitCode(0);
        $this->login('u@x.com', 'newpass123')->assertOk();
        Notification::assertSentTo($u, PasswordChangedNotification::class);
        Notification::assertNotSentTo($this->admin, PasswordResetAlertNotification::class);
    }
}
