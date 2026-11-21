<?php

namespace Tests\Feature\TwoFactor;

use App\Models\AuditLog;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;

/** AG-005 ACs 12-23: the second sign-in step (F2, P1, R4) and replay protection (R5). */
class SignInTest extends TwoFactorTestCase
{
    public function test_ac12_login_returns_a_challenge_and_nothing_else(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $lastLogin = $u->fresh()->last_login_at;
        $tokens = DB::table('personal_access_tokens')->count();

        $r = $this->login('cash@x.com')->assertOk();
        $this->assertSame('challenge', $r->json('two_factor'));
        $this->assertSame(40, strlen($r->json('challenge_token')));
        $this->assertSame(now()->addMinutes(5)->toIso8601String(), $r->json('expires_at'));
        $this->assertArrayNotHasKey('token', $r->json());
        $this->assertArrayNotHasKey('user', $r->json());
        $this->assertSame($tokens, DB::table('personal_access_tokens')->count());
        $this->assertEquals($lastLogin, $u->fresh()->last_login_at);
        $this->assertSame(0, AuditLog::where('event', 'auth.login')->count());
        // Only the sha256 of the token is stored (R4).
        $row = DB::table('two_factor_challenges')->sole();
        $this->assertSame(hash('sha256', $r->json('challenge_token')), $row->token_hash);
        $this->assertSame(['verify', '10.0.0.1'], [$row->purpose, $row->ip]);
    }

    public function test_ac13_the_current_code_signs_in(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $r = $this->verify($challenge, ['code' => $this->code($secret)])->assertOk()
            ->assertJsonPath('user.id', $u->id)->assertJsonPath('recovery_code_used', false)->assertJsonPath('recovery_codes_left', 10);
        $this->bearer($r->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'cash@x.com');
        $row = AuditLog::where('event', 'auth.login')->sole();
        $this->assertEquals(['two_factor' => 'totp'], $row->new_values);
        $this->assertSame([$u->id, null], [$row->user_id, $row->branch_id]);
        $this->assertTrue($u->fresh()->last_login_at->equalTo(now()));
        $this->assertNotNull(DB::table('two_factor_challenges')->value('consumed_at'));
        $this->assertSame(0, (int) DB::table('two_factor_challenges')->value('attempts')); // the successful attempt is not a wrong one
    }

    public function test_ac13_spaces_in_the_code_are_removed(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $code = $this->code($secret);
        $this->verify($this->challenge($u), ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertOk();
    }

    public function test_ac14_a_challenge_is_used_once(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $this->verify($challenge, ['code' => $this->code($secret)])->assertOk();
        $this->nextStep();
        $this->verify($challenge, ['code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors(['challenge_token' => 'Your sign-in has expired. Enter your email and password again.']);
    }

    public function test_ac15_a_verify_challenge_lives_five_minutes(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $this->travel(5 * 60 + 1)->seconds();
        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->assertNull(DB::table('two_factor_challenges')->value('consumed_at')); // nothing has to be done to it
    }

    public function test_ac16_a_challenge_works_only_for_its_purpose(): void
    {
        $admin = $this->member('ba@x.com', 'Branch Admin');
        $this->requireForAdmins();
        $enrol = $this->challenge($admin, 'enrol');
        $this->verify($enrol, ['code' => '123456'])->assertStatus(422)->assertJsonValidationErrors('challenge_token');

        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $verify = $this->challenge($u);
        $this->public()->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => $verify])
            ->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->public()->postJson('/api/v1/auth/two-factor/enrol/confirm', ['challenge_token' => $verify, 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->verify('x'.substr($verify, 1), ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->verify($verify, ['code' => $this->code($secret)])->assertOk(); // still open
    }

    public function test_ac17_a_wrong_password_creates_no_challenge(): void
    {
        $u = $this->member('cash@x.com');
        $this->enable($u);
        $this->login('cash@x.com', 'wrong-pass1')->assertStatus(422)->assertJsonPath('message', 'Invalid email or password.');
        $this->assertSame(0, DB::table('two_factor_challenges')->count());
    }

    public function test_ac18_deactivation_or_losing_the_last_branch_ends_the_challenge(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $this->as($this->admin, $this->kdy->id)->putJson("/api/v1/users/{$u->id}", ['status' => 'inactive'])->assertOk();
        $this->assertNotNull(DB::table('two_factor_challenges')->value('consumed_at')); // R14
        $tokens = DB::table('personal_access_tokens')->count();
        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->assertSame($tokens, DB::table('personal_access_tokens')->count());

        // Reactivation restores the same 2FA (R14); removing the last membership ends an open challenge too.
        $this->as($this->admin, $this->kdy->id)->putJson("/api/v1/users/{$u->id}", ['status' => 'active'])->assertOk();
        $this->assertTrue($u->fresh()->hasTwoFactor());
        $challenge = $this->challenge($u);
        $this->as($this->admin, $this->kdy->id)->postJson("/api/v1/users/{$u->id}/remove-from-branch")->assertOk();
        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->assertSame($tokens, DB::table('personal_access_tokens')->count());
        $this->login('cash@x.com')->assertStatus(403); // B12 refuses earlier
    }

    public function test_ac19_an_inactive_user_gets_the_inactive_message_and_no_challenge(): void
    {
        $u = $this->member('cash@x.com');
        $this->enable($u);
        $u->forceFill(['status' => 'inactive'])->save();
        $this->login('cash@x.com')->assertStatus(403)->assertJsonPath('message', 'Your account is inactive. Contact your administrator.');
        $this->assertSame(0, DB::table('two_factor_challenges')->count());
    }

    // ------------------------------------------------------------------ replay (R5)

    public function test_ac20_a_used_code_cannot_sign_in_again(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $code = $this->code($secret);
        $this->verify($this->challenge($u), ['code' => $code])->assertOk();
        $this->travel(10)->seconds();
        $this->verify($this->challenge($u), ['code' => $code])
            ->assertStatus(422)->assertJsonValidationErrors(['code' => "That code didn't work. Check that the time on your phone is set automatically."]);
    }

    public function test_ac21_an_older_code_cannot_be_used_after_a_newer_one(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $this->verify($this->challenge($u), ['code' => $this->code($secret, 30)])->assertOk(); // step s+1
        $this->verify($this->challenge($u), ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('code'); // step s
    }

    /**
     * AC 22. PHP tests run one request at a time, so the race is shown at the point that decides it: the code check is
     * a single conditional UPDATE (no read-then-write gap), and of two requests carrying the same step only the one
     * whose UPDATE moves two_factor_last_step forward gets a token.
     */
    public function test_ac22_of_two_requests_with_the_same_code_exactly_one_signs_in(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        [$c1, $c2] = [$this->challenge($u), $this->challenge($u)];
        $code = $this->code($secret);
        $this->assertTrue(TwoFactor::useTotp($u, $code) xor TwoFactor::useTotp($u, $code));
        DB::table('users')->where('id', $u->id)->update(['two_factor_last_step' => null]);

        $responses = [$this->verify($c1, ['code' => $code]), $this->verify($c2, ['code' => $code])];
        $this->assertSame([200, 422], array_map(fn ($r) => $r->status(), $responses));
        $responses[1]->assertJsonValidationErrors('code');
        $this->assertSame(1, $u->tokens()->count());
    }

    public function test_ac23_a_code_used_to_sign_in_cannot_disable(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $code = $this->code($secret);
        $token = $this->verify($this->challenge($u), ['code' => $code])->assertOk()->json('token');
        $this->bearer($token)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $code])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertTrue($u->fresh()->hasTwoFactor());
    }
}
