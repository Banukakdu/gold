<?php

namespace Tests\Feature\TwoFactor;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\TwoFactorAlertNotification;
use App\Notifications\TwoFactorChangedNotification;
use App\Services\TwoFactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** AG-005 ACs 24-32, 62 and 66: recovery codes (R7) and the rate limits and locks (R6). */
class RecoveryAndLockoutTest extends TwoFactorTestCase
{
    private int $ipCounter = 0;

    /** A fresh IP each time, so the per-IP limits (login, `two-factor`) never interfere with the per-user counters. */
    private function ip(): string
    {
        $this->ipCounter++;

        return '10.9.'.intdiv($this->ipCounter, 250).'.'.($this->ipCounter % 250 + 1);
    }

    /** $n wrong codes for the user, 4 per challenge (so no challenge is used up). */
    private function failCodes(User $u, string $secret, int $n): void
    {
        $challenge = null;
        for ($i = 0; $i < $n; $i++) {
            if ($i % 4 === 0) {
                $challenge = $this->challenge($u, 'challenge', $this->ip());
            }
            $this->verify($challenge, ['code' => $this->wrongCode($secret)], $this->ip())->assertStatus(422)->assertJsonValidationErrors('code');
        }
    }

    // ------------------------------------------------------------------ recovery codes

    public function test_ac24_a_recovery_code_signs_in_upper_case_and_without_the_dash(): void
    {
        $u = $this->member('cash@x.com');
        [, $codes] = $this->enable($u);
        $r = $this->verify($this->challenge($u), ['recovery_code' => strtoupper(str_replace('-', '', $codes[0]))])->assertOk()
            ->assertJsonPath('recovery_code_used', true)->assertJsonPath('recovery_codes_left', 9);
        $this->bearer($r->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor.recovery_codes_left', 9);
        $row = AuditLog::where('event', 'auth.two_factor_recovery_used')->sole();
        $this->assertEquals(['remaining' => 9, 'context' => 'sign_in'], $row->new_values);
        $this->assertSame($u->id, $row->user_id);
        $this->assertEquals(['two_factor' => 'recovery'], AuditLog::where('event', 'auth.login')->sole()->new_values);
        Notification::assertSentTo($u, TwoFactorChangedNotification::class,
            fn ($n) => $n->kind === 'recovery_used' && str_contains($n->text(), 'A recovery code was used to sign in. 9 left.'));
        $used = DB::table('two_factor_recovery_codes')->whereNotNull('used_at')->sole();
        $this->assertSame('10.0.0.1', $used->used_ip);
    }

    public function test_ac25_a_used_recovery_code_is_refused(): void
    {
        $u = $this->member('cash@x.com');
        [, $codes] = $this->enable($u);
        $this->verify($this->challenge($u), ['recovery_code' => $codes[0]])->assertOk();
        $this->verify($this->challenge($u), ['recovery_code' => $codes[0]])
            ->assertStatus(422)->assertJsonValidationErrors(['recovery_code' => 'That recovery code is not valid or has already been used.']);
    }

    public function test_ac26_new_recovery_codes_supersede_the_old_ones(): void
    {
        $u = $this->member('cash@x.com');
        [$secret, $old] = $this->enable($u);
        $this->verify($this->challenge($u), ['recovery_code' => $old[0]])->assertOk();

        $this->as($u)->postJson('/api/v1/me/two-factor/recovery-codes', ['password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $new = $this->as($u)->postJson('/api/v1/me/two-factor/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->code($secret)])
            ->assertOk()->json('recovery_codes');
        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($new, $old));
        foreach (array_slice($old, 1, 3) as $c) {
            $this->verify($this->challenge($u), ['recovery_code' => $c])->assertStatus(422)->assertJsonValidationErrors('recovery_code');
        }
        $this->verify($this->challenge($u), ['recovery_code' => $new[0]])->assertOk();
        $row = AuditLog::where('event', 'auth.two_factor_recovery_regenerated')->sole();
        $this->assertEquals(['unused' => 9], $row->old_values);
        $this->assertEquals(['recovery_codes' => 10], $row->new_values);
        $this->assertSame(20, DB::table('two_factor_recovery_codes')->where('user_id', $u->id)->count()); // nothing deleted
        Notification::assertSentTo($u, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'recovery_regenerated');

        $other = $this->member('off@x.com');
        $this->as($other)->postJson('/api/v1/me/two-factor/recovery-codes', ['password' => self::PASSWORD, 'code' => '123456'])
            ->assertStatus(422)->assertJsonValidationErrors(['two_factor' => 'Two-factor sign-in is off.']);
    }

    public function test_ac27_exactly_one_of_code_or_recovery_code(): void
    {
        $u = $this->member('cash@x.com');
        [$secret, $codes] = $this->enable($u);
        $challenge = $this->challenge($u);
        $this->verify($challenge, ['code' => $this->code($secret), 'recovery_code' => $codes[0]])->assertStatus(422);
        $this->verify($challenge, [])->assertStatus(422);
        $this->verify($challenge, ['code' => $this->code($secret)])->assertOk(); // neither counted as an attempt
    }

    public function test_ac66_recovery_codes_survive_an_app_key_rotation(): void
    {
        $u = $this->member('cash@x.com');
        [$secret, $codes] = $this->enable($u);
        $k1 = config('app.key');
        $plain = strtoupper(str_replace('-', '', $codes[0]));
        $row = DB::table('two_factor_recovery_codes')->where('user_id', $u->id)->pluck('code_hash')->all();
        $this->assertNotContains(hash_hmac('sha256', $plain, $k1), $row);
        $this->assertNotContains(hash_hmac('sha256', $plain, base64_decode(substr($k1, 7))), $row);

        $k2 = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => $k2, 'app.previous_keys' => [$k1]]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Model::encryptUsing(null);
        try {
            $this->verify($this->challenge($u), ['recovery_code' => $codes[0]])->assertOk()->assertJsonPath('recovery_codes_left', 9);
            $this->verify($this->challenge($u), ['code' => $this->code($secret)])->assertOk(); // the secret decrypts with APP_PREVIOUS_KEYS
        } finally {
            config(['app.key' => $k1, 'app.previous_keys' => []]);
            $this->app->forgetInstance('encrypter');
            Crypt::clearResolvedInstance('encrypter');
        }
    }

    // ------------------------------------------------------------------ limits and locks

    public function test_ac28_five_wrong_codes_use_up_a_challenge(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        for ($i = 0; $i < 5; $i++) {
            $this->verify($challenge, ['code' => $this->wrongCode($secret)])->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $row = DB::table('two_factor_challenges')->sole();
        $this->assertSame(5, (int) $row->attempts);
        $this->assertNotNull($row->consumed_at);
    }

    public function test_ac29_ac30_ten_wrong_codes_start_a_fifteen_minute_lock(): void
    {
        $sa2 = $this->superAdmin('sa2@x.com');
        $u = $this->member('ba@x.com', 'Branch Admin');
        [$secret] = $this->enable($u);
        $this->failCodes($u, $secret, 10); // challenges of 4, 4 and 2

        $this->login('ba@x.com', self::PASSWORD, $this->ip())->assertStatus(429)
            ->assertJsonPath('message', 'Too many wrong codes. Try again in 15 minutes.');
        $this->assertSame(3, DB::table('two_factor_challenges')->count()); // no new challenge
        $row = AuditLog::where('event', 'auth.two_factor_locked')->sole();
        $this->assertSame([$u->id, $u->id, null], [$row->user_id, $row->auditable_id, $row->branch_id]);
        $this->assertSame('short', $row->new_values['level']);
        $this->assertSame(10, $row->new_values['failures']);
        $this->assertSame(now()->addMinutes(15)->toIso8601String(), $row->new_values['until']);
        $this->assertCount(10, $row->new_values['ips']);
        $this->assertSame(['sign_in', true], [$row->new_values['source'], $row->new_values['password_known']]); // review S2
        Notification::assertSentTo($u, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'locked'
            && str_contains($n->text(), 'A wrong password or code was entered too many times for your account (when signing in or confirming a change). If this was not you, reset your password now.'));
        Notification::assertSentTo([$sa2, $this->admin], TwoFactorAlertNotification::class, fn ($n) => $n->kind === 'locked');
        Notification::assertNotSentTo($u, TwoFactorAlertNotification::class);

        // While locked, every code endpoint is 429; a wrong password is still the normal 422 (AC 30).
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)])->assertStatus(429);
        $this->login('ba@x.com', 'wrong-pass1', $this->ip())->assertStatus(422)->assertJsonPath('message', 'Invalid email or password.');
        $this->travel(15 * 60 + 1)->seconds();
        $this->login('ba@x.com', self::PASSWORD, $this->ip())->assertOk()->assertJsonPath('two_factor', 'challenge');
        $this->assertSame(1, AuditLog::where('event', 'auth.two_factor_locked')->count());
    }

    public function test_ac29_a_cashier_lock_alerts_no_super_admin(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $this->failCodes($u, $secret, 10);
        $this->login('cash@x.com', self::PASSWORD, $this->ip())->assertStatus(429);
        Notification::assertNotSentTo($this->admin, TwoFactorAlertNotification::class);
    }

    public function test_a_successful_code_clears_the_short_counter(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $this->failCodes($u, $secret, 9);
        $this->signIn($u, $secret, $this->ip());
        $this->failCodes($u, $secret, 9);
        $this->login('cash@x.com', self::PASSWORD, $this->ip())->assertOk()->assertJsonPath('two_factor', 'challenge');
        $this->assertSame(0, AuditLog::where('event', 'auth.two_factor_locked')->count());
    }

    public function test_step_up_passwords_count_towards_the_lock(): void
    {
        $u = $this->member('cash@x.com');
        $this->enable($u);
        for ($i = 0; $i < 10; $i++) {
            $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => 'wrong-pass1', 'code' => '123456'])
                ->assertStatus(422)->assertJsonValidationErrors(['password' => 'The password is incorrect.']);
        }
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => 'wrong-pass1', 'code' => '123456'])->assertStatus(429);
        $this->assertSame(1, AuditLog::where('event', 'auth.two_factor_locked')->count());
    }

    public function test_ac31_eleven_verify_requests_from_one_ip_in_a_minute(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->verify(str_repeat('x', 40), ['code' => '123456'], '10.7.7.7')->assertStatus(422);
        }
        $this->verify(str_repeat('x', 40), ['code' => '123456'], '10.7.7.7')->assertStatus(429);
        $this->public('10.7.7.7')->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => 'x'])->assertStatus(429);
        $this->verify(str_repeat('x', 40), ['code' => '123456'], '10.7.7.8')->assertStatus(422); // per IP
    }

    public function test_ac32_a_password_reset_does_not_clear_the_lock(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $this->failCodes($u, $secret, 10);
        $this->public()->postJson('/api/v1/auth/forgot-password', ['email' => 'cash@x.com'])->assertStatus(202);
        $token = Notification::sent($u, PasswordResetLinkNotification::class)->last()->token;
        $this->public()->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'cash@x.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertNoContent();
        $this->login('cash@x.com', 'newpass123', $this->ip())->assertStatus(429);
        $this->assertTrue($u->fresh()->hasTwoFactor()); // R13: 2FA stays on
        $this->travel(15 * 60 + 1)->seconds();
        $this->login('cash@x.com', 'newpass123', $this->ip())->assertOk()->assertJsonPath('two_factor', 'challenge');
    }

    public function test_ac62_thirty_wrong_codes_in_a_day_start_a_24_hour_lock(): void
    {
        $sa2 = $this->superAdmin('sa2@x.com');
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        foreach ([1, 2] as $round) {
            $this->failCodes($u, $secret, 10);
            $this->login('cash@x.com', self::PASSWORD, $this->ip())->assertStatus(429);
            $this->travel(15 * 60 + 1)->seconds();
        }
        $this->failCodes($u, $secret, 10);
        $start = now();

        $r = $this->login('cash@x.com', self::PASSWORD, $this->ip())->assertStatus(429);
        $this->assertSame('Too many wrong codes. Try again in 24 hours.', $r->json('message'));
        $extended = AuditLog::where('event', 'auth.two_factor_locked')->get()->filter(fn ($a) => $a->new_values['level'] === 'extended');
        $this->assertCount(1, $extended);
        $this->assertSame(30, $extended->first()->new_values['failures']);
        $this->assertCount(20, $extended->first()->new_values['ips']); // max 20
        $this->assertSame(2, AuditLog::where('event', 'auth.two_factor_locked')->count() - 1);
        Notification::assertSentTo($u, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'locked' && $n->details['level'] === 'extended');
        // Every other active Super Admin, although the user is a Cashier.
        Notification::assertSentTo([$this->admin, $sa2], TwoFactorAlertNotification::class,
            fn ($n) => $n->kind === 'locked' && $n->details['level'] === 'extended');

        $this->travel(15 * 60 + 1)->seconds();
        $this->login('cash@x.com', self::PASSWORD, $this->ip())->assertStatus(429); // the short lock alone would have ended
        $this->travelTo($start->copy()->addDay()->addSecond());
        $this->login('cash@x.com', self::PASSWORD, $this->ip())->assertOk()->assertJsonPath('two_factor', 'challenge');
        $this->assertNull(TwoFactor::lockedFor($u));
    }
}
