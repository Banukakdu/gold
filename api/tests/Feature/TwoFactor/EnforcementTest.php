<?php

namespace Tests\Feature\TwoFactor;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Setting;
use App\Notifications\TwoFactorAlertNotification;
use App\Support\TwoFactorPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** AG-005 ACs 42-49, 60 and 64: "Require two-factor sign-in for admins" (R8, R9, F3, F4, S6). */
class EnforcementTest extends TwoFactorTestCase
{
    private function putSettings(array $body)
    {
        return $this->as($this->admin)->putJson('/api/v1/settings', $body);
    }

    private function settingValue(): string
    {
        return (string) Setting::where('key', 'two_factor_required_for_admins')->value('value');
    }

    // ------------------------------------------------------------------ the setting

    public function test_ac42_turning_it_on_needs_the_super_admins_own_two_factor(): void
    {
        $sa2 = $this->superAdmin('sa2@x.com');
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $this->putSettings(['two_factor_required_for_admins' => true])->assertStatus(422)
            ->assertJsonValidationErrors(['two_factor_required_for_admins' => 'Turn on two-factor sign-in for your own account first.']);
        $this->assertSame('0', $this->settingValue());

        [$secret] = $this->enable($this->admin);
        $this->putSettings(['two_factor_required_for_admins' => true, 'password' => self::PASSWORD, 'code' => $this->code($secret)])->assertOk()
            ->assertJsonPath('two_factor_required_for_admins', true);
        $this->assertSame('1', $this->settingValue());
        $row = AuditLog::where('event', 'settings.updated')->sole();
        $this->assertEquals(['two_factor_required_for_admins' => false], $row->old_values);
        $this->assertEquals(['two_factor_required_for_admins' => true], $row->new_values);
        $this->assertNull($row->branch_id);
        Notification::assertSentTo($sa2, TwoFactorAlertNotification::class, fn ($n) => $n->kind === 'setting' && $n->details['enabled'] === true);
        Notification::assertNotSentTo($this->admin, TwoFactorAlertNotification::class);
        $this->as($this->admin)->getJson('/api/v1/settings')->assertJsonPath('last_changed.two_factor_required_for_admins.by.id', $this->admin->id);
        // Not on the public settings.
        $this->as($this->admin)->getJson('/api/v1/settings/public')->assertJsonMissingPath('two_factor_required_for_admins');
    }

    public function test_ac64_changing_the_setting_needs_password_and_code_both_ways(): void
    {
        $sa2 = $this->superAdmin('sa2@x.com');
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        [$secret] = $this->enable($this->admin);
        foreach ([[true, '0', '1'], [false, '1', '0']] as [$value, $before, $after]) {
            $this->putSettings(['two_factor_required_for_admins' => $value])->assertStatus(422)->assertJsonValidationErrors('password');
            $this->putSettings(['two_factor_required_for_admins' => $value, 'password' => 'wrong-pass1', 'code' => $this->code($secret)])
                ->assertStatus(422)->assertJsonValidationErrors('password');
            $this->putSettings(['two_factor_required_for_admins' => $value, 'password' => self::PASSWORD])->assertStatus(422)->assertJsonValidationErrors('code');
            $this->putSettings(['two_factor_required_for_admins' => $value, 'password' => self::PASSWORD, 'code' => $this->wrongCode($secret)])
                ->assertStatus(422)->assertJsonValidationErrors('code');
            $this->assertSame($before, $this->settingValue());
            $this->putSettings(['two_factor_required_for_admins' => $value, 'password' => self::PASSWORD, 'code' => $this->code($secret)])->assertOk();
            $this->assertSame($after, $this->settingValue());
            $this->nextStep();
        }
        $this->assertSame(2, AuditLog::where('event', 'settings.updated')->count());
        $this->assertCount(2, Notification::sent($sa2, TwoFactorAlertNotification::class));
        // Unchanged value: no step-up and no row.
        $this->putSettings(['two_factor_required_for_admins' => false])->assertOk();
        $this->assertSame(2, AuditLog::where('event', 'settings.updated')->count());
    }

    public function test_ac48_pending_admins_on_get_settings(): void
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $this->enable($this->admin);
        $this->requireForAdmins();
        $b1 = $this->member('b1@x.com', 'Branch Admin');
        $b1->forceFill(['name' => 'Bandara'])->save();
        $b2 = $this->member('b2@x.com', 'Branch Admin');
        $b2->forceFill(['name' => 'Alwis'])->save();
        $this->member('b3@x.com', 'Branch Admin')->forceFill(['status' => 'inactive'])->save();
        $this->member('cash@x.com');
        $this->as($this->admin)->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('two_factor_required_for_admins', true)
            ->assertJsonPath('two_factor_pending_admins.count', 2)
            ->assertJsonPath('two_factor_pending_admins.users', [
                ['id' => $b2->id, 'name' => 'Alwis', 'email' => 'b2@x.com'],
                ['id' => $b1->id, 'name' => 'Bandara', 'email' => 'b1@x.com'],
            ]);
        $this->assertSame(2, TwoFactorPolicy::pendingAdmins()['count']);
    }

    // ------------------------------------------------------------------ who is an admin (section 2)

    public function test_ac43_ac60_who_must_enrol_at_sign_in(): void
    {
        $gal = $this->makeBranch('GAL');
        $this->requireForAdmins();
        $this->member('ba@x.com', 'Branch Admin');
        $this->custom('create@x.com', ['user.create']);
        $this->custom('transfer@x.com', ['transfer.approve']);
        $this->custom('expense@x.com', ['expense.approve']);
        $this->custom('voids@x.com', ['income.void', 'expense.void', 'transfer.reverse']);
        $this->member('acc@x.com', 'Accountant');
        $inactive = $this->custom('inactive@x.com', ['user.edit', 'role.manage']);
        Role::where('name', 'Custom inactive@x.com')->update(['status' => 'inactive']);
        // An admin role in a branch the user is not a member of does not count (R18).
        $nonMember = $this->member('nonmember@x.com');
        setPermissionsTeamId($gal->id);
        $nonMember->assignRole($this->branchRole($gal, 'Branch Admin'));

        foreach (['ba@x.com', 'create@x.com', 'transfer@x.com', 'expense@x.com'] as $email) {
            $this->login($email)->assertOk()->assertJsonPath('two_factor', 'enrol');
        }
        foreach (['voids@x.com', 'acc@x.com', 'inactive@x.com', 'nonmember@x.com'] as $email) {
            $this->login($email)->assertOk()->assertJsonStructure(['token', 'user']);
        }
        $this->login($this->admin->email, self::ADMIN_PASSWORD)->assertOk()->assertJsonPath('two_factor', 'enrol');
        $this->assertSame(TwoFactorPolicy::ADMIN_KEYS, ['user.create', 'user.edit', 'role.manage', 'transfer.approve', 'expense.approve']);
    }

    public function test_ac60_an_approvers_existing_token_is_limited(): void
    {
        $u = $this->custom('expense@x.com', ['expense.approve', 'transaction.view']);
        $token = $u->createToken('web')->plainTextToken;
        $this->bearer($token)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')->assertOk();
        $this->requireForAdmins();
        $this->bearer($token)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')
            ->assertForbidden()->assertJsonPath('code', 'two_factor_enrolment_required');
    }

    // ------------------------------------------------------------------ forced enrolment (F3)

    public function test_ac44_forced_enrolment_signs_in_with_recovery_codes(): void
    {
        $this->requireForAdmins();
        $u = $this->member('ba@x.com', 'Branch Admin');
        $old = $u->createToken('web')->plainTextToken;
        $r = $this->login('ba@x.com')->assertOk()->assertJsonPath('two_factor', 'enrol')
            ->assertJsonPath('expires_at', now()->addMinutes(15)->toIso8601String()); // an enrol challenge lives 15 minutes
        $this->assertArrayNotHasKey('token', $r->json());
        $challenge = $r->json('challenge_token');

        $secret = $this->public()->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => $challenge])->assertOk()->json('secret');
        $this->assertEquals(['mode' => 'forced', 'recovery_code_used' => false], AuditLog::where('event', 'auth.two_factor_setup_started')->sole()->new_values);
        $this->public()->postJson('/api/v1/auth/two-factor/enrol/confirm', ['challenge_token' => $challenge, 'code' => $this->wrongCode($secret)])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $r = $this->public()->postJson('/api/v1/auth/two-factor/enrol/confirm', ['challenge_token' => $challenge, 'code' => $this->code($secret)])
            ->assertOk()->assertJsonPath('user.id', $u->id);
        $this->assertCount(10, $r->json('recovery_codes'));
        $this->bearer($r->json('token'))->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')->assertOk();
        $this->bearer($old)->getJson('/api/v1/me')->assertUnauthorized(); // R10: sessions made with the password only end

        $this->assertTrue(AuditLog::where('event', 'auth.two_factor_enabled')->sole()->new_values['forced']);
        $this->assertEquals(['two_factor' => 'enrolled'], AuditLog::where('event', 'auth.login')->sole()->new_values);
        $this->assertNotNull($u->fresh()->last_login_at);
        $this->assertNotNull(DB::table('two_factor_challenges')->value('consumed_at'));
        $this->public()->postJson('/api/v1/auth/two-factor/enrol/confirm', ['challenge_token' => $challenge, 'code' => $this->code($secret, 30)])
            ->assertStatus(422)->assertJsonValidationErrors('challenge_token');
    }

    public function test_ac45_an_expired_enrol_challenge_turns_nothing_on(): void
    {
        $this->requireForAdmins();
        $u = $this->member('ba@x.com', 'Branch Admin');
        $challenge = $this->challenge($u, 'enrol');
        $secret = $this->public()->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => $challenge])->assertOk()->json('secret');
        $this->travel(15 * 60 + 1)->seconds();
        $this->public()->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => $challenge])
            ->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->public()->postJson('/api/v1/auth/two-factor/enrol/confirm', ['challenge_token' => $challenge, 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->assertFalse($u->fresh()->hasTwoFactor());
        $this->login('ba@x.com')->assertOk()->assertJsonPath('two_factor', 'enrol'); // starts again
    }

    public function test_enrol_start_is_limited_per_challenge(): void
    {
        $this->requireForAdmins();
        $u = $this->member('ba@x.com', 'Branch Admin');
        $challenge = $this->challenge($u, 'enrol');
        for ($i = 0; $i < 5; $i++) {
            $this->public("10.5.0.{$i}")->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => $challenge])->assertOk();
        }
        $this->public('10.5.1.1')->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => $challenge])->assertStatus(429);
    }

    // ------------------------------------------------------------------ existing tokens (R9, F4)

    public function test_ac46_ac49_an_existing_token_is_limited_until_set_up(): void
    {
        $u = $this->member('ba@x.com', 'Branch Admin');
        $token = $u->createToken('web')->plainTextToken;
        $this->requireForAdmins();
        $api = fn () => $this->bearer($token)->withHeader('X-Branch-Id', (string) $this->kdy->id);

        $api()->getJson('/api/v1/transactions')->assertForbidden()
            ->assertExactJson(['message' => 'Set up two-factor sign-in to continue.', 'code' => 'two_factor_enrolment_required']);
        $api()->getJson('/api/v1/users')->assertForbidden();
        $api()->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor.required', true)->assertJsonPath('two_factor.enabled', false);
        $api()->getJson('/api/v1/settings/public')->assertOk();
        $api()->getJson('/api/v1/me/two-factor')->assertOk()->assertJsonPath('required', true);
        $api()->postJson('/api/v1/me/two-factor/recovery-codes', [])->assertForbidden();
        $secret = $api()->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $api()->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($secret)])->assertOk()->assertJsonCount(10, 'recovery_codes');
        $this->assertTrue(AuditLog::where('event', 'auth.two_factor_enabled')->sole()->new_values['forced']);
        $api()->getJson('/api/v1/transactions')->assertOk(); // same token

        // AC 49: the setting off again; 2FA stays on, and a required admin without 2FA is back to normal.
        $other = $this->member('ba2@x.com', 'Branch Admin');
        $otherToken = $other->createToken('web')->plainTextToken;
        $this->bearer($otherToken)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')->assertForbidden();
        $this->requireForAdmins(false);
        $this->bearer($otherToken)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')->assertOk();
        $this->assertTrue($u->fresh()->hasTwoFactor());

        $this->requireForAdmins();
        $this->bearer($otherToken)->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_ac47_a_new_admin_role_applies_at_the_next_request(): void
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $this->enable($this->admin);
        $this->requireForAdmins();
        $u = $this->member('cash@x.com');
        $token = $u->createToken('web')->plainTextToken;
        $this->bearer($token)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')->assertOk();
        $this->as($this->admin, $this->kdy->id)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $this->branchRole($this->kdy, 'Branch Admin')->id])
            ->assertOk();
        $this->bearer($token)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')
            ->assertForbidden()->assertJsonPath('code', 'two_factor_enrolment_required');
    }

    public function test_a_super_admin_without_two_factor_is_limited_too(): void
    {
        $this->requireForAdmins();
        $this->as($this->admin)->getJson('/api/v1/settings')->assertForbidden()->assertJsonPath('code', 'two_factor_enrolment_required');
        $this->as($this->admin)->getJson('/api/v1/me')->assertOk();
    }
}
