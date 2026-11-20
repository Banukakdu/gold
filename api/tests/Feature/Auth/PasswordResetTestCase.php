<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AccountingTestCase;

/** Helpers for docs/specs/password-reset.md (AG-002 and the AG-001 login gaps). */
abstract class PasswordResetTestCase extends AccountingTestCase
{
    public const TOKEN_MESSAGE = 'This reset link is invalid or has expired.';

    public const REQUESTED = 'If an account exists for this email, a reset link has been sent.';

    protected Branch $kdy;

    protected bool $fakeNotifications = true;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->fakeNotifications) {
            Notification::fake();
        }
        $this->kdy = $this->makeBranch('KDY');
        $this->app['auth']->forgetGuards();
    }

    /** An active member of KDY with a role template and a known password. */
    protected function member(string $email, string $role = 'Cashier', array $attrs = []): User
    {
        $u = $this->userIn($this->kdy, $role);
        $u->forceFill($attrs + ['email' => $email, 'password' => 'oldpass123', 'last_login_at' => now()->subDay()])->save();

        return $u->fresh();
    }

    protected function public(string $ip = '10.0.0.1')
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    protected function forgot(string $email, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->public($ip)->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
    }

    protected function reset(string $email, string $token, string $password = 'newpass123', ?string $confirm = null, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->public($ip)->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm ?? $password,
        ]);
    }

    protected function login(string $email, string $password, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->public($ip)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** The plain token of the latest reset link emailed to the user. */
    protected function linkToken(User $u): string
    {
        $sent = Notification::sent($u, PasswordResetLinkNotification::class);
        $this->assertNotEmpty($sent, 'No reset link was sent.');

        return $sent->last()->token;
    }

    /** Request a link for the user and return its token. */
    protected function tokenFor(User $u): string
    {
        $this->forgot($u->email)->assertStatus(202);

        return $this->linkToken($u);
    }

    protected function secondSuperAdmin(string $email = 'sa2@example.com'): User
    {
        $u = User::factory()->create(['email' => $email, 'password' => 'oldpass123', 'status' => 'active']);
        $u->forceFill(['is_super_admin' => true, 'last_login_at' => now()->subDay()])->save();

        return $u->fresh();
    }
}
