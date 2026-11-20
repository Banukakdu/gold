<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/** Password reset spec N2: AC 11-20, 22, 23, 41. AC 21 is in ResetMailFailureTest. */
class ResetPasswordTest extends PasswordResetTestCase
{
    public function test_ac11_reset_changes_the_password(): void
    {
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);

        $this->reset('U@x.com', $token)->assertNoContent();
        $this->login('u@x.com', 'newpass123')->assertOk();
        $this->login('u@x.com', 'oldpass123')->assertStatus(422)->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_ac12_a_used_token_is_refused(): void
    {
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);
        $this->reset('u@x.com', $token)->assertNoContent();

        $this->reset('u@x.com', $token, 'another123')->assertStatus(422)
            ->assertExactJson(['message' => self::TOKEN_MESSAGE, 'errors' => ['token' => [self::TOKEN_MESSAGE]]]);
    }

    public function test_ac13_expired_or_replaced_token_is_refused(): void
    {
        $this->freezeTime();
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);
        $this->travel(61)->minutes();
        $this->reset('u@x.com', $token)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);

        $old = $this->tokenFor($u);
        $this->travel(61)->seconds();
        $new = $this->tokenFor($u);
        $this->assertNotSame($old, $new);
        $this->reset('u@x.com', $old)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
        $this->reset('u@x.com', $new)->assertNoContent();
    }

    public function test_ac14_every_failure_reason_gives_the_same_body(): void
    {
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);

        $bodies = [
            $this->reset('nobody@x.com', $token)->assertStatus(422)->json(),
            $this->reset('u@x.com', str_repeat('a', 64))->assertStatus(422)->json(),
        ];
        $u->forceFill(['status' => 'inactive'])->save(); // deactivated after the request (without the E4 cleanup)
        $bodies[] = $this->reset('u@x.com', $token)->assertStatus(422)->json();

        $this->assertSame(['message' => self::TOKEN_MESSAGE, 'errors' => ['token' => [self::TOKEN_MESSAGE]]], $bodies[0]);
        $this->assertSame([$bodies[0], $bodies[0]], [$bodies[1], $bodies[2]]);
        $this->assertTrue(Hash::check('oldpass123', $u->fresh()->password));
    }

    public function test_ac15_ac16_policy_failures_keep_the_token(): void
    {
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);

        foreach (['abcdefgh', '12345678', 'abc1234'] as $weak) {
            $this->reset('u@x.com', $token, $weak)->assertStatus(422)->assertJsonValidationErrors('password')->assertJsonMissingValidationErrors('token');
        }
        $this->reset('u@x.com', $token, 'newpass123', 'newpass124')->assertStatus(422)->assertJsonValidationErrors('password');

        $this->reset('u@x.com', $token)->assertNoContent();
    }

    public function test_ac17_all_sessions_are_revoked(): void
    {
        $u = $this->member('u@x.com');
        $tokens = [$u->createToken('web')->plainTextToken, $u->createToken('web')->plainTextToken];
        foreach ($tokens as $t) {
            $this->public()->withToken($t)->getJson('/api/v1/me')->assertOk();
        }

        $this->reset('u@x.com', $this->tokenFor($u))->assertNoContent();

        foreach ($tokens as $t) {
            $this->public()->withToken($t)->getJson('/api/v1/me')->assertUnauthorized();
        }
        $this->assertSame(2, AuditLog::where('event', 'auth.password_reset')->sole()->new_values['tokens_revoked']);
    }

    public function test_ac18_audit_row_has_the_actor_and_no_secrets(): void
    {
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);
        $tokenHash = DB::table('password_reset_tokens')->where('email', 'u@x.com')->value('token');

        $this->reset('u@x.com', $token, 'Secret-pass-42', null, '10.4.4.4')->assertNoContent();

        $row = AuditLog::where('event', 'auth.password_reset')->sole();
        $this->assertSame($u->id, $row->user_id);
        $this->assertSame([User::class, $u->id], [$row->auditable_type, $row->auditable_id]);
        // assertEquals: MySQL's JSON column does not keep key order.
        $this->assertEquals(['user_id' => $u->id, 'email' => 'u@x.com', 'first_password' => false, 'tokens_revoked' => 0], $row->new_values);
        $this->assertFalse($row->new_values['first_password']);
        $this->assertSame('10.4.4.4', $row->ip);
        $this->assertNotNull($row->created_at);
        $this->assertNull($row->branch_id);

        $dump = json_encode(DB::table('audit_logs')->get());
        foreach (['Secret-pass-42', $u->fresh()->password, $token, $tokenHash] as $secret) {
            $this->assertStringNotContainsString(str_replace('/', '\/', $secret), $dump);
        }
    }

    public function test_ac19_password_changed_email_has_time_and_ip_and_no_link(): void
    {
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 11, 18)->setTime(4, 30));
        $u = $this->member('u@x.com');
        $this->reset('u@x.com', $this->tokenFor($u), 'newpass123', null, '10.5.5.5')->assertNoContent();

        Notification::assertSentTo($u, PasswordChangedNotification::class, function ($n) use ($u) {
            $mail = $n->toMail($u);
            $text = implode(' ', $mail->introLines);

            return $mail->actionUrl === null && ! str_contains($text, 'token')
                && str_contains($text, 'on 2026-11-18 10:00 (Asia/Colombo) from IP 10.5.5.5')
                && str_contains($text, 'signed out on all devices');
        });
    }

    public function test_ac20_who_gets_the_admin_alert(): void
    {
        config(['auth.super_admin_email_reset' => true]);
        $b = $this->secondSuperAdmin();

        // Super Admin A resets: B is told, A is not.
        $this->reset($this->admin->email, $this->tokenFor($this->admin))->assertNoContent();
        Notification::assertSentTo($b, PasswordResetAlertNotification::class,
            fn ($n) => $n->kind === 'reset' && $n->subject() === "Password reset: {$this->admin->name} <{$this->admin->email}>");
        Notification::assertNotSentTo($this->admin, PasswordResetAlertNotification::class);

        // A branch administrator resets: every active Super Admin is told.
        $ba = $this->member('ba@x.com', 'Branch Admin');
        $this->reset('ba@x.com', $this->tokenFor($ba))->assertNoContent();
        Notification::assertSentToTimes($this->admin, PasswordResetAlertNotification::class, 1);
        Notification::assertSentToTimes($b, PasswordResetAlertNotification::class, 2);

        // A Cashier resets: nobody is told.
        $c = $this->member('c@x.com');
        $this->reset('c@x.com', $this->tokenFor($c))->assertNoContent();
        Notification::assertSentTimes(PasswordResetAlertNotification::class, 3);
    }

    public function test_ac22_ten_resets_per_ip_per_minute(): void
    {
        for ($n = 1; $n <= 10; $n++) {
            $this->reset("p$n@x.com", 'bogus', 'newpass123', null, '10.6.0.1')->assertStatus(422);
        }
        $this->reset('p11@x.com', 'bogus', 'newpass123', null, '10.6.0.1')->assertStatus(429)
            ->assertJsonPath('message', 'Too many requests. Try again in 1 minute.');
    }

    public function test_ac23_reset_clears_the_login_lock_of_the_request_ip(): void
    {
        $u = $this->member('u@x.com');
        for ($n = 0; $n < 5; $n++) {
            $this->login('u@x.com', 'wrong-pass1', '10.7.0.1')->assertStatus(422);
        }
        $this->login('u@x.com', 'oldpass123', '10.7.0.1')->assertStatus(429);

        $this->reset('u@x.com', $this->tokenFor($u), 'newpass123', null, '10.7.0.1')->assertNoContent();
        $this->login('u@x.com', 'newpass123', '10.7.0.1')->assertOk();
    }

    public function test_ac41_super_admin_token_refused_once_the_switch_is_off(): void
    {
        config(['auth.super_admin_email_reset' => true]);
        $this->secondSuperAdmin();
        $token = $this->tokenFor($this->admin);

        config(['auth.super_admin_email_reset' => false]);
        $this->reset($this->admin->email, $token)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
        $this->public()->postJson('/api/v1/auth/set-password', ['token' => $token, 'email' => $this->admin->email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertStatus(422)->assertJsonValidationErrors('token');
        $this->login($this->admin->email, 'ChangeMe123')->assertOk();
    }

    public function test_n8_super_admin_link_refused_once_no_other_super_admin_is_active(): void
    {
        config(['auth.super_admin_email_reset' => true]);
        $b = $this->secondSuperAdmin();
        $token = $this->tokenFor($this->admin);
        $b->forceFill(['status' => 'inactive'])->save();

        $this->reset($this->admin->email, $token)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
        $b->forceFill(['status' => 'active'])->save();
        $this->reset($this->admin->email, $token)->assertNoContent();
    }
}
