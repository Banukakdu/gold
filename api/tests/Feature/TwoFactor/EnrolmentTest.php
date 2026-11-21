<?php

namespace Tests\Feature\TwoFactor;

use App\Models\AuditLog;
use App\Notifications\TwoFactorChangedNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** AG-005 ACs 5-11: enrolment (F1, M2, M3). */
class EnrolmentTest extends TwoFactorTestCase
{
    public function test_ac5_setup_needs_the_password_and_returns_a_pending_secret(): void
    {
        $u = $this->member('cash@x.com');
        $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => 'wrong-pass1'])
            ->assertStatus(422)->assertJsonValidationErrors(['password' => 'The password is incorrect.']);
        $this->assertNull(DB::table('users')->where('id', $u->id)->value('two_factor_pending_secret'));
        $this->assertSame(0, AuditLog::where('event', 'auth.two_factor_setup_started')->count());

        $r = $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->assertOk();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $r->json('secret'));
        $this->assertStringStartsWith('otpauth://totp/', $r->json('otpauth_uri'));
        $this->assertStringContainsString('secret='.$r->json('secret'), $r->json('otpauth_uri'));
        $this->assertSame(now()->addMinutes(15)->toIso8601String(), $r->json('expires_at'));
        $row = AuditLog::where('event', 'auth.two_factor_setup_started')->sole();
        $this->assertEquals(['mode' => 'enable', 'recovery_code_used' => false], $row->new_values);
        $this->assertSame($u->id, $row->user_id);
        $this->assertNull($row->branch_id);
    }

    public function test_ac6_a_pending_setup_does_nothing(): void
    {
        $u = $this->member('cash@x.com');
        $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->assertOk();
        $this->as($u)->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor.enabled', false);
        $this->as($u)->getJson('/api/v1/me/two-factor')->assertOk()->assertJsonPath('enabled', false)
            ->assertJsonPath('setup_pending_until', now()->addMinutes(15)->toIso8601String());
        $this->login('cash@x.com')->assertOk()->assertJsonStructure(['token', 'user'])->assertJsonMissingPath('two_factor');
    }

    public function test_ac7_confirm_turns_two_factor_on_with_ten_recovery_codes(): void
    {
        $u = $this->member('cash@x.com');
        $secret = $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->json('secret');
        $this->as($u)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->wrongCode($secret)])
            ->assertStatus(422)->assertJsonValidationErrors(['code' => "That code didn't work. Check that the time on your phone is set automatically."]);
        $this->assertNull($u->fresh()->two_factor_confirmed_at);

        $r = $this->as($u)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($secret)])->assertOk();
        $codes = $r->json('recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[23456789abcdefghjkmnpqrstuvwxyz]{5}-[23456789abcdefghjkmnpqrstuvwxyz]{5}$/', $c);
        }
        $fresh = $u->fresh();
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertSame($secret, $fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_pending_secret);
        $this->assertSame(intdiv(now()->getTimestamp(), 30), $fresh->two_factor_last_step); // R3
        $row = AuditLog::where('event', 'auth.two_factor_enabled')->sole();
        $this->assertEquals(['forced' => false, 'recovery_codes' => 10, 'tokens_revoked' => 0], $row->new_values);
        $this->assertSame([$u->id, $u->id, null], [$row->user_id, $row->auditable_id, $row->branch_id]);
        Notification::assertSentTo($u, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'enabled'
            && str_contains($n->text(), 'If this was not you, contact your administrator at once.'));
        $this->as($u)->getJson('/api/v1/me')->assertJsonPath('two_factor.enabled', true)->assertJsonPath('two_factor.recovery_codes_left', 10);
    }

    public function test_ac8_an_expired_setup_is_refused(): void
    {
        $u = $this->member('cash@x.com');
        $secret = $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->json('secret');
        $this->travel(15 * 60 + 1)->seconds();
        $this->as($u)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors(['setup' => 'This setup has expired. Start again.']);
        $this->assertNull($u->fresh()->two_factor_confirmed_at);
        // No pending setup at all is the same error.
        $other = $this->member('other@x.com');
        $this->as($other)->postJson('/api/v1/me/two-factor/confirm', ['code' => '123456'])->assertStatus(422)->assertJsonValidationErrors('setup');
    }

    public function test_ac9_confirm_revokes_every_other_token(): void
    {
        $u = $this->member('cash@x.com');
        [$a, $b, $c] = [$u->createToken('web')->plainTextToken, $u->createToken('web')->plainTextToken, $u->createToken('web')->plainTextToken];
        $secret = $this->bearer($a)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $this->bearer($a)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($secret)])->assertOk()->assertJsonPath('tokens_revoked', 2);

        $this->bearer($b)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($c)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($a)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(2, AuditLog::where('event', 'auth.two_factor_enabled')->sole()->new_values['tokens_revoked']);
    }

    public function test_ac10_the_secret_is_stored_encrypted_and_never_returned_after_confirmation(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $raw = DB::table('users')->where('id', $u->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));

        $bodies = [
            $this->as($u)->getJson('/api/v1/me')->assertOk()->getContent(),
            $this->as($u)->getJson('/api/v1/me/two-factor')->assertOk()->getContent(),
            $this->as($this->admin)->getJson("/api/v1/users/{$u->id}")->assertOk()->getContent(),
            $this->as($this->admin)->getJson('/api/v1/users')->assertOk()->getContent(),
            $this->as($this->admin)->getJson('/api/v1/settings')->assertOk()->getContent(),
        ];
        foreach ($bodies as $body) {
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($raw, $body);
            $this->assertStringNotContainsString('two_factor_secret', $body);
        }
    }

    public function test_ac11_recovery_codes_are_stored_as_hmac_only(): void
    {
        $u = $this->member('cash@x.com');
        [, $codes] = $this->enable($u);
        $rows = DB::table('two_factor_recovery_codes')->where('user_id', $u->id)->get();
        $this->assertCount(10, $rows);
        $this->assertCount(1, $rows->pluck('batch')->unique());
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->code_hash);
            foreach ((array) $row as $value) {
                foreach ($codes as $plain) {
                    $this->assertStringNotContainsString(strtoupper(str_replace('-', '', $plain)), strtoupper((string) $value));
                    $this->assertStringNotContainsString($plain, (string) $value);
                }
            }
        }
    }
}
