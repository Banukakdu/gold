<?php

namespace Tests\Feature\TwoFactor;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use App\Support\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AccountingTestCase;

/**
 * Helpers for docs/specs/two-factor.md (AG-005). Notifications are faked, the queue is sync and the clock is fixed
 * (Carbon::setTestNow), so "the code at T" is Totp::codeAt(secret, floor(T / 30)).
 */
abstract class TwoFactorTestCase extends AccountingTestCase
{
    public const PASSWORD = 'secret-pass1';

    /** The seeded Super Admin's password (DatabaseSeeder). */
    public const ADMIN_PASSWORD = 'ChangeMe123';

    protected Branch $kdy;

    /** Every secret, code, recovery code and challenge token seen in a test (AC 51). */
    protected array $seen = [];

    protected bool $fakeNotifications = true;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->fakeNotifications) {
            Notification::fake();
        }
        // On a step boundary + 1 s, so "+10 s" stays in the same step.
        Carbon::setTestNow(Carbon::parse('2026-11-20 05:00:01', 'UTC'));
        $this->kdy = $this->makeBranch('KDY');
        $this->app['auth']->forgetGuards();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An active KDY member with a role template and a known password. */
    protected function member(string $email, string $role = 'Cashier'): User
    {
        $u = $this->userIn($this->kdy, $role);
        $u->forceFill(['email' => $email, 'password' => self::PASSWORD, 'last_login_at' => now()->subDay()])->save();

        return $u->fresh();
    }

    /** A KDY member whose only role is a custom role with exactly these permissions. */
    protected function custom(string $email, array $permissions): User
    {
        $role = Role::create(['name' => 'Custom '.$email, 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions($permissions);
        $u = User::factory()->create(['status' => 'active', 'email' => $email, 'password' => self::PASSWORD]);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($role);

        return $u->fresh();
    }

    protected function superAdmin(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'password' => self::PASSWORD, 'status' => 'active']);
        $u->forceFill(['is_super_admin' => true, 'last_login_at' => now()->subDay()])->save();

        return $u->fresh();
    }

    protected function public(string $ip = '10.0.0.1')
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** Signed in with a real Sanctum token. */
    protected function bearer(string $token, string $ip = '10.0.0.1')
    {
        return $this->public($ip)->withToken($token);
    }

    /** Signed in as the user (actingAs, no token row). */
    protected function as(User $u, string|int $branch = 'all', string $ip = '10.0.0.1')
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $ip])->actingAs($u->fresh())
            ->withHeader('X-Branch-Id', (string) $branch);
    }

    protected function login(string $email, string $password = self::PASSWORD, string $ip = '10.0.0.1'): TestResponse
    {
        $r = $this->public($ip)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
        foreach (['challenge_token', 'token'] as $k) {
            if (is_string($r->json($k))) {
                $this->seen[] = $r->json($k);
            }
        }

        return $r;
    }

    /** A new challenge token for the user (asserts the login returned one of this kind). */
    protected function challenge(User $u, string $kind = 'challenge', string $ip = '10.0.0.1'): string
    {
        $r = $this->login($u->email, self::PASSWORD, $ip)->assertOk()->assertJsonPath('two_factor', $kind);

        return $r->json('challenge_token');
    }

    protected function verify(string $challenge, array $body, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->public($ip)->postJson('/api/v1/auth/two-factor/verify', ['challenge_token' => $challenge] + $body);
    }

    /** The code of the secret at now + $offset seconds. */
    protected function code(string $secret, int $offset = 0): string
    {
        $code = Totp::codeAt($secret, Totp::stepAt(now()->getTimestamp() + $offset));
        $this->seen[] = $code;

        return $code;
    }

    /** A 6-digit code that is not valid for the secret now (none of the 3 accepted steps). */
    protected function wrongCode(string $secret): string
    {
        $valid = [$this->code($secret, -30), $this->code($secret), $this->code($secret, 30)];
        for ($n = 0; ; $n++) {
            $c = str_pad((string) ((123456 + $n * 7919) % 1000000), 6, '0', STR_PAD_LEFT);
            if (! in_array($c, $valid, true)) {
                return $c;
            }
        }
    }

    /** Move the clock to the next 30-second step (+1 s into it), so a new code can be used (R5). */
    protected function nextStep(int $steps = 1): void
    {
        Carbon::setTestNow(now()->addSeconds(30 * $steps));
    }

    /** Turn 2FA on through M2 + M3 (actingAs). Returns [secret, recovery codes]. */
    protected function enable(User $u): array
    {
        $secret = $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $codes = $this->as($u)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($secret)])->assertOk()->json('recovery_codes');
        $this->seen[] = $secret;
        array_push($this->seen, ...$codes);
        $this->nextStep();

        return [$secret, $codes];
    }

    protected function requireForAdmins(bool $on = true): void
    {
        Setting::put('two_factor_required_for_admins', $on ? '1' : '0');
        app(Settings::class)->flush();
    }

    /** Sign in with a recovery-free TOTP flow and return the Sanctum token. */
    protected function signIn(User $u, string $secret, string $ip = '10.0.0.1'): string
    {
        $token = $this->verify($this->challenge($u, 'challenge', $ip), ['code' => $this->code($secret)], $ip)->assertOk()->json('token');
        $this->seen[] = $token;
        $this->nextStep();

        return $token;
    }
}
