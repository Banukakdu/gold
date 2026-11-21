<?php

namespace Tests\Feature\TwoFactor;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\TwoFactorAlertNotification;
use App\Notifications\TwoFactorChangedNotification;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** AG-005 ACs 33-41, 61, 63 and 67: turning off (M5), moving phone (M2/M3), the Super Admin reset (A1) and C1. */
class DisableMoveResetTest extends TwoFactorTestCase
{
    private const REASON = 'Checked in person';

    private function reset(User $actor, User $target, array $body)
    {
        return $this->as($actor)->postJson("/api/v1/users/{$target->id}/two-factor/reset", $body);
    }

    /** Super Admin A with 2FA on. Returns A's secret. */
    private function adminWithTwoFactor(): string
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();

        return $this->enable($this->admin)[0];
    }

    /** n wrong codes at P1 for the user (4 per challenge), each from its own IP. */
    private function lockOut(User $u, string $secret): void
    {
        $challenge = null;
        for ($i = 0; $i < 10; $i++) {
            if ($i % 4 === 0) {
                $challenge = $this->challenge($u, 'challenge', "10.6.0.{$i}");
            }
            $this->verify($challenge, ['code' => $this->wrongCode($secret)], "10.6.1.{$i}")->assertStatus(422);
        }
        $this->assertNotNull(TwoFactor::lockedFor($u));
    }

    // ------------------------------------------------------------------ M5

    public function test_ac33_disable_clears_the_secret_and_supersedes_the_codes(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)])->assertNoContent();

        $raw = DB::table('users')->where('id', $u->id)->first();
        $this->assertNull($raw->two_factor_confirmed_at);
        $this->assertNull($raw->two_factor_secret);
        $this->assertNull($raw->two_factor_last_step);
        $this->assertNull($raw->two_factor_pending_secret);
        $this->assertSame(10, DB::table('two_factor_recovery_codes')->where('user_id', $u->id)->whereNotNull('superseded_at')->count());
        $this->assertSame(10, DB::table('two_factor_recovery_codes')->where('user_id', $u->id)->count());
        $row = AuditLog::where('event', 'auth.two_factor_disabled')->sole();
        $this->assertArrayHasKey('confirmed_at', $row->old_values);
        $this->assertEquals(['superseded_codes' => 10], $row->new_values);
        Notification::assertSentTo($u, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'disabled');
        Notification::assertNotSentTo($this->admin, TwoFactorAlertNotification::class); // a Cashier: no alert
        $this->login('cash@x.com')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_ac33_an_admin_turning_it_off_alerts_the_other_super_admins(): void
    {
        $sa2 = $this->superAdmin('sa2@x.com');
        $u = $this->member('ba@x.com', 'Branch Admin');
        [, $codes] = $this->enable($u);
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'recovery_code' => $codes[0]])->assertNoContent();
        Notification::assertSentTo([$this->admin, $sa2], TwoFactorAlertNotification::class, fn ($n) => $n->kind === 'disabled');
        $this->assertEquals(['remaining' => 9, 'context' => 'disable'], AuditLog::where('event', 'auth.two_factor_recovery_used')->sole()->new_values);
    }

    public function test_ac34_a_wrong_password_or_code_keeps_it_on(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => 'wrong-pass1', 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->wrongCode($secret)])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'recovery_code' => 'aaaaa-aaaaa'])
            ->assertStatus(422)->assertJsonValidationErrors('recovery_code');
        $this->assertTrue($u->fresh()->hasTwoFactor());
        $this->assertSame(0, AuditLog::where('event', 'auth.two_factor_disabled')->count());
    }

    public function test_ac35_a_required_admin_cannot_turn_it_off(): void
    {
        $u = $this->member('ba@x.com', 'Branch Admin');
        [$secret] = $this->enable($u);
        $this->requireForAdmins();
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors(['two_factor' => 'Two-factor sign-in is required for your account and cannot be turned off.']);
        $this->as($u)->getJson('/api/v1/me/two-factor')->assertJsonPath('required', true);
        $this->assertTrue($u->fresh()->hasTwoFactor());
    }

    // ------------------------------------------------------------------ move phone

    public function test_ac36_move_to_a_new_phone_with_a_recovery_code(): void
    {
        $u = $this->member('cash@x.com');
        [$old, $codes] = $this->enable($u);
        $tokenA = $u->createToken('web')->plainTextToken;
        $tokenB = $u->createToken('web')->plainTextToken;
        $this->bearer($tokenA)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('code'); // 2FA on: a code or a recovery code is needed
        $new = $this->bearer($tokenA)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD, 'recovery_code' => $codes[0]])
            ->assertOk()->json('secret');
        $this->assertNotSame($old, $new);
        $this->assertEquals(['mode' => 'move', 'recovery_code_used' => true],
            AuditLog::where('event', 'auth.two_factor_setup_started')->latest('id')->first()->new_values);

        $r = $this->bearer($tokenA)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($new)])->assertOk();
        $this->assertNull($r->json('recovery_codes'));
        $this->assertSame(1, $r->json('tokens_revoked'));
        $this->bearer($tokenB)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($tokenA)->getJson('/api/v1/me')->assertOk();
        $this->assertEquals(['tokens_revoked' => 1], AuditLog::where('event', 'auth.two_factor_moved')->sole()->new_values);
        $this->assertSame(9, TwoFactor::recoveryCodesLeft($u));
        Notification::assertSentTo($u, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'moved');

        $this->nextStep();
        $this->verify($this->challenge($u), ['code' => $this->code($old)])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->verify($this->challenge($u), ['code' => $this->code($new)])->assertOk();
        $this->verify($this->challenge($u), ['recovery_code' => $codes[1]])->assertOk(); // the other codes are unchanged
    }

    public function test_ac37_an_unconfirmed_move_keeps_the_old_secret(): void
    {
        $u = $this->member('cash@x.com');
        [$old] = $this->enable($u);
        $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD, 'code' => $this->code($old)])->assertOk();
        $this->nextStep();
        $this->verify($this->challenge($u), ['code' => $this->code($old)])->assertOk();
        $this->assertSame($old, $u->fresh()->two_factor_secret);
    }

    public function test_setup_is_limited_to_five_per_fifteen_minutes(): void
    {
        $u = $this->member('cash@x.com');
        for ($i = 0; $i < 5; $i++) {
            $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->assertOk();
        }
        $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->assertStatus(429);
    }

    // ------------------------------------------------------------------ A1

    public function test_ac38_a_super_admin_resets_a_cashier(): void
    {
        $sa2 = $this->superAdmin('sa2@x.com');
        $aSecret = $this->adminWithTwoFactor();
        $c = $this->member('cash@x.com');
        [$cSecret] = $this->enable($c);
        [$t1, $t2] = [$c->createToken('web')->plainTextToken, $c->createToken('web')->plainTextToken];
        $challenge = $this->challenge($c);

        $this->reset($this->admin, $c, ['reason' => '  Phone call ok  ', 'password' => self::PASSWORD, 'code' => $this->code($aSecret)])
            ->assertOk()->assertExactJson(['tokens_revoked' => 2]);
        $this->bearer($t1)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($t2)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertNotNull(DB::table('two_factor_challenges')->where('token_hash', hash('sha256', $challenge))->value('consumed_at'));
        $this->assertFalse($c->fresh()->hasTwoFactor());
        $this->assertNull(DB::table('users')->where('id', $c->id)->value('two_factor_secret'));

        $row = AuditLog::where('event', 'auth.two_factor_reset')->sole();
        $this->assertSame([$this->admin->id, $c->id, null], [$row->user_id, $row->auditable_id, $row->branch_id]);
        $this->assertSame(['confirmed_at', 'last_locked_at', 'recovery_codes_left'], collect($row->old_values)->keys()->sort()->values()->all());
        $this->assertSame(10, $row->old_values['recovery_codes_left']);
        $this->assertEquals(['reason' => 'Phone call ok', 'via' => 'app', 'tokens_revoked' => 2, 'superseded_codes' => 10,
            'challenges_consumed' => 1, 'lock_cleared' => false], $row->new_values);
        Notification::assertSentTo($c, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'reset'
            && str_contains($n->text(), 'reset by '.$this->admin->name));
        Notification::assertSentTo($sa2, TwoFactorAlertNotification::class, fn ($n) => $n->kind === 'reset' && $n->details['reason'] === 'Phone call ok');
        Notification::assertNotSentTo($this->admin, TwoFactorAlertNotification::class);
        $this->login('cash@x.com')->assertOk()->assertJsonStructure(['token']); // not required: password only
    }

    public function test_ac39_bad_reason_password_or_missing_code_change_nothing(): void
    {
        $aSecret = $this->adminWithTwoFactor();
        $c = $this->member('cash@x.com');
        $this->enable($c);
        $this->reset($this->admin, $c, ['reason' => '123456789', 'password' => self::PASSWORD, 'code' => $this->code($aSecret)])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->reset($this->admin, $c, ['reason' => str_repeat('a', 501), 'password' => self::PASSWORD, 'code' => $this->code($aSecret)])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => 'wrong-pass1', 'code' => $this->code($aSecret)])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertTrue($c->fresh()->hasTwoFactor());
        $this->assertSame(0, AuditLog::where('event', 'auth.two_factor_reset')->count());
    }

    public function test_ac40_branch_admins_cannot_reset_and_nobody_resets_themselves(): void
    {
        $ba = $this->member('ba@x.com', 'Branch Admin');
        $c = $this->member('cash@x.com');
        $this->enable($c);
        $this->as($ba, $this->kdy->id)->postJson("/api/v1/users/{$c->id}/two-factor/reset", ['reason' => self::REASON, 'password' => self::PASSWORD])
            ->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $this->reset($this->admin, $this->admin, ['reason' => self::REASON, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors(['user' => 'Use Security to change your own two-factor sign-in.']);
        $off = $this->member('off@x.com');
        $this->reset($this->admin, $off, ['reason' => self::REASON, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors(['user' => 'Two-factor sign-in is not on for this user.']);
        $c->forceFill(['status' => 'inactive'])->save();
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors(['user' => 'This user is inactive.']);
        $this->as($this->admin)->postJson('/api/v1/users/999999/two-factor/reset', ['reason' => self::REASON, 'password' => self::PASSWORD])->assertNotFound();
    }

    public function test_ac61_no_reset_while_a_recent_lock_has_no_password_reset_after_it(): void
    {
        $aSecret = $this->adminWithTwoFactor();
        $c = $this->member('cash@x.com');
        [$cSecret] = $this->enable($c);
        $this->lockOut($c, $cSecret);
        $this->travel(2)->days();

        $this->as($this->admin)->getJson("/api/v1/users/{$c->id}")->assertOk()->assertJsonPath('two_factor.reset_blocked_since', fn ($v) => $v !== null);
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => self::PASSWORD, 'code' => $this->code($aSecret)])
            ->assertStatus(422)->assertJsonValidationErrors('user')
            ->assertJsonPath('errors.user.0', fn ($m) => str_starts_with($m, "Someone entered this user's password without the code on "));
        $this->artisan('auth:two-factor-reset', ['email' => 'cash@x.com', '--reason' => self::REASON, '--confirm' => 'cash@x.com'])
            ->expectsOutputToContain('auth:reset-password')->assertExitCode(1);
        $this->assertTrue($c->fresh()->hasTwoFactor());
        $this->assertSame(0, AuditLog::where('event', 'auth.two_factor_reset')->count());

        // C resets the password by email; then the same reset is allowed and clears the lock.
        $this->public()->postJson('/api/v1/auth/forgot-password', ['email' => 'cash@x.com'])->assertStatus(202);
        $token = Notification::sent($c, PasswordResetLinkNotification::class)->last()->token;
        $this->public()->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'cash@x.com',
            'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertNoContent();
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => self::PASSWORD, 'code' => $this->code($aSecret)])->assertOk();
        $this->assertTrue(AuditLog::where('event', 'auth.two_factor_reset')->sole()->new_values['lock_cleared']);
        $this->assertNull(TwoFactor::lockedFor($c));
    }

    public function test_ac61_an_active_lock_is_cleared_by_the_reset(): void
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $c = $this->member('cash@x.com');
        [$cSecret] = $this->enable($c);
        $this->lockOut($c, $cSecret);
        DB::table('audit_logs')->insert(['event' => 'auth.password_reset', 'auditable_type' => User::class, 'auditable_id' => $c->id,
            'created_at' => now()]);
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => self::PASSWORD])->assertOk();
        $this->assertNull(TwoFactor::lockedFor($c));
        $this->login('cash@x.com')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_ac61_a_lock_older_than_seven_days_does_not_block(): void
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $c = $this->member('cash@x.com');
        [$cSecret] = $this->enable($c);
        $this->lockOut($c, $cSecret);
        $this->travel(8)->days();
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_a_super_admin_may_reset_another_super_admin_who_is_alerted_too(): void
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $b = $this->superAdmin('sab@x.com');
        $this->enable($b);
        $this->reset($this->admin, $b, ['reason' => self::REASON, 'password' => self::PASSWORD])->assertOk();
        Notification::assertSentTo($b, TwoFactorAlertNotification::class, fn ($n) => $n->kind === 'reset');
        Notification::assertSentTo($b, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'reset');
    }

    // ------------------------------------------------------------------ C1

    public function test_ac41_ac63_console_reset(): void
    {
        $a = $this->superAdmin('saa@x.com');
        $b = $this->superAdmin('sab@x.com');
        $d = $this->superAdmin('sad@x.com');
        $this->enable($b);
        $b->createToken('web');
        $b->createToken('web');

        $this->artisan('auth:two-factor-reset', ['email' => 'SAB@x.com', '--reason' => 'Lost phone, verified in person'])
            ->expectsOutputToContain('Super Admin: yes')
            ->expectsQuestion('Type the email again to confirm', 'sab@x.com')
            ->expectsOutputToContain('2 session(s) signed out')->assertExitCode(0);

        $this->assertFalse($b->fresh()->hasTwoFactor());
        $this->assertSame(0, $b->tokens()->count());
        $row = AuditLog::where('event', 'auth.two_factor_reset')->sole();
        $this->assertNull($row->user_id);
        $this->assertSame([null, 'console'], [$row->ip, $row->user_agent]);
        $this->assertSame('console', $row->new_values['via']);
        $this->assertSame('Lost phone, verified in person', $row->new_values['reason']);
        $this->assertNotEmpty($row->new_values['os_user']);
        $this->assertNotEmpty($row->new_values['host']);
        Notification::assertSentTo($b, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'reset'
            && str_contains($n->text(), 'by the server console'));
        Notification::assertSentTo([$a, $d, $this->admin], TwoFactorAlertNotification::class, fn ($n) => $n->kind === 'reset' && $n->actor === null);
        Notification::assertNotSentTo($b, TwoFactorAlertNotification::class);
    }

    public function test_ac41_ac63_console_refusals_change_nothing(): void
    {
        $b = $this->superAdmin('sab@x.com');
        $this->enable($b);
        $off = $this->member('off@x.com');
        $reason = ['--reason' => 'Lost phone, verified in person'];

        $this->artisan('auth:two-factor-reset', ['email' => 'nobody@x.com'] + $reason)->expectsOutputToContain('No user with this email.')->assertExitCode(1);
        $this->artisan('auth:two-factor-reset', ['email' => 'off@x.com'] + $reason)
            ->expectsOutputToContain('Two-factor sign-in is not on for this user.')->assertExitCode(1);
        $this->artisan('auth:two-factor-reset', ['email' => 'sab@x.com', '--reason' => 'too short'])->assertExitCode(1);
        $this->artisan('auth:two-factor-reset', ['email' => 'sab@x.com', '--no-interaction' => true] + $reason)->assertExitCode(1);
        $this->artisan('auth:two-factor-reset', ['email' => 'sab@x.com', '--no-interaction' => true, '--confirm' => 'other@x.com'] + $reason)->assertExitCode(1);
        $this->artisan('auth:two-factor-reset', ['email' => 'sab@x.com'])
            ->expectsQuestion('Reason (how did you check it was really them?)', 'short')->assertExitCode(1);

        $this->assertTrue($b->fresh()->hasTwoFactor());
        $this->assertFalse($off->fresh()->hasTwoFactor());
        $this->assertSame(0, AuditLog::where('event', 'auth.two_factor_reset')->count());
        Notification::assertNotSentTo([$this->admin, $b], TwoFactorAlertNotification::class);

        $this->artisan('auth:two-factor-reset', ['email' => 'sab@x.com', '--no-interaction' => true, '--confirm' => 'sab@x.com'] + $reason)->assertExitCode(0);
        $this->assertFalse($b->fresh()->hasTwoFactor());
    }

    // ------------------------------------------------------------------ R12 closing the loop

    public function test_ac67_the_first_set_up_after_a_reset_is_alerted_once(): void
    {
        $sa2 = $this->superAdmin('sa2@x.com');
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $c = $this->member('cash@x.com');
        $this->enable($c);
        Notification::assertNotSentTo($sa2, TwoFactorAlertNotification::class); // no alert for turning it on
        $this->reset($this->admin, $c, ['reason' => self::REASON, 'password' => self::PASSWORD])->assertOk();

        $this->enable($c);
        Notification::assertSentTo($sa2, TwoFactorAlertNotification::class, fn ($n) => $n->kind === 're_enrolled'
            && $n->text() === "{$c->name} <cash@x.com> set up two-factor sign-in again after a reset, from IP 10.0.0.1.");
        Notification::assertNotSentTo($c, TwoFactorAlertNotification::class);
        $this->as($c)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($c->fresh()->two_factor_secret)])
            ->assertNoContent();
        $this->enable($c);
        $sent = Notification::sent($sa2, TwoFactorAlertNotification::class)->filter(fn ($n) => $n->kind === 're_enrolled');
        $this->assertCount(1, $sent);
    }
}
