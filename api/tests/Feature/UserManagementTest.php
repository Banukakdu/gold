<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;

/** Spec users-and-roles: users, memberships, invitation (AC 1-19, 33, 34, 41). */
class UserManagementTest extends UsersAndRolesTestCase
{
    public function test_ac1_super_admin_creates_user_with_invitation(): void
    {
        $cashier = $this->branchRole($this->kdy, 'Cashier');
        $res = $this->admin($this->kdy)->postJson('/api/v1/users', [
            'name' => '  Nimal Perera ', 'email' => 'Nimal@Example.com', 'phone' => '0771234567', 'role_id' => $cashier->id,
        ])->assertCreated()->json();

        $u = User::find($res['id']);
        $this->assertSame('active', $u->status);
        $this->assertSame('nimal@example.com', $u->email); // B11
        $this->assertSame('Nimal Perera', $u->name);
        $this->assertTrue($u->isMemberOf($this->kdy->id));
        $this->assertSame('Cashier', $this->roleNameIn($u, $this->kdy));
        Notification::assertSentTo($u, InvitationNotification::class);
        $audit = AuditLog::where('event', 'user.invited')->where('auditable_id', $u->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->kdy->id, $audit->branch_id);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('Cashier', $audit->new_values['role_name']);
        $this->assertSame($this->kdy->id, $audit->new_values['branch_id']);
    }

    public function test_ac2_duplicate_email_any_case_is_rejected(): void
    {
        $role = $this->branchRole($this->kdy, 'Cashier');
        $existing = $this->userIn($this->kdy, 'Viewer');
        $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'X', 'email' => strtoupper($existing->email), 'role_id' => $role->id])
            ->assertStatus(422)->assertJsonValidationErrors('email')->assertJsonPath('existing_user_id', $existing->id);

        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $this->as($badmin, $this->kdy)->postJson('/api/v1/users', ['name' => 'X', 'email' => $existing->email, 'role_id' => $role->id])
            ->assertStatus(422)->assertJsonValidationErrors('email')->assertJsonPath('existing_user_id', null);
    }

    public function test_ac3_role_list_marks_templates_and_only_has_active_branch_roles(): void
    {
        $gle = $this->makeBranch('GLE');
        $this->branchRole($gle, 'Viewer');
        $kc = $this->branchRole($this->kdy, 'Cashier');
        $ka = $this->branchRole($this->kdy, 'Accountant');
        $ka->update(['status' => 'inactive']);

        $roles = collect($this->admin($this->kdy)->getJson('/api/v1/roles')->assertOk()->json());
        $assignable = $roles->where('is_template', false)->where('status', 'active');
        $this->assertSame([$kc->id], $assignable->pluck('id')->values()->all());
        $this->assertTrue($roles->where('is_template', false)->every(fn ($r) => $r['branch_id'] === $this->kdy->id));
        $this->assertSame(['Accountant', 'Branch Admin', 'Cashier', 'Viewer'], $roles->where('is_template', true)->pluck('name')->sort()->values()->all());
    }

    public function test_ac4_template_role_cannot_be_assigned(): void
    {
        $tpl = Role::whereNull('branch_id')->where('name', 'Cashier')->first();
        $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role_id' => $tpl->id])
            ->assertStatus(422)->assertJsonPath('errors.role_id.0', 'Pick a role of this branch.');
        $this->assertNull(User::where('email', 'x@example.com')->first());

        $u = $this->userIn($this->kdy, 'Viewer');
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $tpl->id])
            ->assertStatus(422)->assertJsonPath('message', 'Pick a role of this branch.');
    }

    public function test_ac5_inactive_role_cannot_be_assigned(): void
    {
        $role = $this->branchRole($this->kdy, 'Cashier');
        $role->update(['status' => 'inactive']);
        $u = $this->userIn($this->kdy, 'Viewer');
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $role->id])
            ->assertStatus(422)->assertJsonPath('message', 'This role is inactive.');
        $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role_id' => $role->id])
            ->assertStatus(422)->assertJsonPath('message', 'This role is inactive.');
    }

    public function test_ac6_super_admin_adds_existing_user_to_another_branch(): void
    {
        $u = $this->userIn($this->kdy, 'Cashier');
        $viewer = $this->branchRole($this->main, 'Viewer');
        $this->admin($this->main)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $viewer->id])->assertOk();

        $b = $this->meBranches($u);
        $this->assertSame(['Cashier'], $b['KDY']['roles']);
        $this->assertSame(['Viewer'], $b['MAIN']['roles']);
        $this->assertContains('income.create', $b['KDY']['permissions']);
        $this->assertNotContains('income.create', $b['MAIN']['permissions']);
    }

    public function test_ac7_assigning_a_role_replaces_the_role_in_that_branch_only(): void
    {
        $u = $this->addTo($this->userIn($this->kdy, 'Cashier'), $this->main, 'Viewer');
        $acct = $this->branchRole($this->kdy, 'Accountant');
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $acct->id])->assertOk();

        $b = $this->meBranches($u);
        $this->assertSame(['Accountant'], $b['KDY']['roles']);
        $this->assertSame(['Viewer'], $b['MAIN']['roles']);
    }

    public function test_ac8_branch_admin_sees_only_own_branch_members_and_memberships(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $shared = $this->addTo($this->userIn($this->kdy, 'Cashier'), $this->main, 'Viewer');
        $other = $this->userIn($this->main, 'Viewer');

        $rows = collect($this->as($badmin, $this->kdy)->getJson('/api/v1/users')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$badmin->id, $shared->id], $rows->pluck('id')->all());
        $this->assertNotContains($other->id, $rows->pluck('id'));
        $sharedRow = $rows->firstWhere('id', $shared->id);
        $this->assertSame([$this->kdy->id], collect($sharedRow['memberships'])->pluck('branch_id')->all());
        $this->assertSame(['Cashier'], $sharedRow['roles_in_branch']);

        // filters
        $rows = $this->as($badmin, $this->kdy)->getJson('/api/v1/users?q='.urlencode($shared->email))->json('data');
        $this->assertSame([$shared->id], array_column($rows, 'id'));
        $this->admin()->putJson("/api/v1/users/{$shared->id}", ['status' => 'inactive'])->assertOk();
        $rows = $this->as($badmin, $this->kdy)->getJson('/api/v1/users?status=inactive')->json('data');
        $this->assertSame([$shared->id], array_column($rows, 'id'));
    }

    public function test_ac9_branch_admin_gets_404_for_user_outside_branch(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $other = $this->userIn($this->main, 'Viewer');
        $this->as($badmin, $this->kdy)->getJson("/api/v1/users/{$other->id}")->assertNotFound();
        $this->as($badmin, $this->kdy)->putJson("/api/v1/users/{$other->id}", ['name' => 'Y'])->assertNotFound();
        $this->as($badmin, $this->kdy)->postJson("/api/v1/users/{$other->id}/role", ['role_id' => $this->branchRole($this->kdy, 'Viewer')->id])->assertNotFound();

        $mine = $this->userIn($this->kdy, 'Viewer');
        $this->as($badmin, $this->kdy)->getJson("/api/v1/users/{$mine->id}")->assertOk()->assertJsonPath('memberships.0.role_name', 'Viewer');
    }

    public function test_ac10_cannot_assign_role_with_permission_actor_lacks(): void
    {
        $limited = $this->customRole($this->kdy, 'User Clerk', ['user.view', 'user.edit', 'user.create', 'transaction.view']);
        $actor = $this->userWith($this->kdy, $limited);
        $target = $this->userWith($this->kdy, $this->customRole($this->kdy, 'Reader', ['transaction.view']));
        $withAudit = $this->customRole($this->kdy, 'Auditor', ['transaction.view', 'audit.view']);

        $this->as($actor, $this->kdy)->postJson("/api/v1/users/{$target->id}/role", ['role_id' => $withAudit->id])->assertForbidden();
        $this->assertSame('Reader', $this->roleNameIn($target, $this->kdy));
        $this->as($actor, $this->kdy)->postJson('/api/v1/users', ['name' => 'Z', 'email' => 'z@example.com', 'role_id' => $withAudit->id])->assertForbidden();
        $this->assertNull(User::where('email', 'z@example.com')->first());
    }

    public function test_ac11_cannot_change_or_remove_user_with_more_permissions(): void
    {
        $limited = $this->customRole($this->kdy, 'User Clerk', ['user.view', 'user.edit', 'transaction.view']);
        $actor = $this->userWith($this->kdy, $limited);
        $target = $this->userIn($this->kdy, 'Accountant');
        $reader = $this->customRole($this->kdy, 'Reader', ['transaction.view']);

        $this->as($actor, $this->kdy)->postJson("/api/v1/users/{$target->id}/role", ['role_id' => $reader->id])
            ->assertForbidden()->assertJsonPath('message', 'You cannot change the role of a user with more permissions than you.');
        $this->as($actor, $this->kdy)->postJson("/api/v1/users/{$target->id}/remove-from-branch")->assertForbidden();
        $this->assertSame('Accountant', $this->roleNameIn($target, $this->kdy));
        $this->assertTrue($target->isMemberOf($this->kdy->id));
    }

    public function test_ac12_non_super_admin_cannot_edit_super_admin(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $this->admin->branches()->attach($this->kdy->id);
        $h = $this->as($badmin, $this->kdy);
        $h->putJson("/api/v1/users/{$this->admin->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->as($badmin, $this->kdy)->postJson("/api/v1/users/{$this->admin->id}/role", ['role_id' => $this->branchRole($this->kdy, 'Viewer')->id])->assertForbidden();
        $this->as($badmin, $this->kdy)->postJson("/api/v1/users/{$this->admin->id}/remove-from-branch")->assertForbidden();
        $this->assertSame('Super Admin', $this->admin->fresh()->name);
    }

    public function test_ac13_shared_user_global_fields_need_super_admin(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $shared = $this->addTo($this->userIn($this->kdy, 'Cashier'), $this->main, 'Viewer');
        $msg = 'This user also works in other branches; ask a Super Admin.';

        $this->as($badmin, $this->kdy)->putJson("/api/v1/users/{$shared->id}", ['email' => 'new@example.com'])->assertForbidden()->assertJsonPath('message', $msg);
        $this->as($badmin, $this->kdy)->putJson("/api/v1/users/{$shared->id}", ['status' => 'inactive'])->assertForbidden()->assertJsonPath('message', $msg);
        $this->assertSame('active', $shared->fresh()->status);

        $viewer = $this->branchRole($this->kdy, 'Viewer');
        $this->as($badmin, $this->kdy)->postJson("/api/v1/users/{$shared->id}/role", ['role_id' => $viewer->id])->assertOk();
        $this->assertSame('Viewer', $this->roleNameIn($shared, $this->kdy));

        // only-KDY user: Branch Admin may edit
        $local = $this->userIn($this->kdy, 'Cashier');
        $this->as($badmin, $this->kdy)->putJson("/api/v1/users/{$local->id}", ['phone' => '0710000000', 'email' => 'Local@Example.com'])->assertOk();
        $this->assertSame('local@example.com', $local->fresh()->email);
    }

    public function test_ac14_super_admin_removes_user_from_branch(): void
    {
        $u = $this->addTo($this->userIn($this->kdy, 'Accountant'), $this->main, 'Viewer');
        $today = now()->toDateString();
        $this->admin()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '1000', 'txn_date' => $today])->assertCreated();
        $txn = $this->as($u, $this->kdy)->postJson('/api/v1/incomes', [
            'account_id' => $this->cashOf($this->kdy)->id, 'category_id' => $this->category('income')->id, 'txn_date' => $today, 'amount' => '500',
        ])->assertCreated()->json();

        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/remove-from-branch")->assertOk()->assertJson(['ok' => true]);

        $this->as($u->fresh(), $this->kdy)->getJson('/api/v1/accounts')->assertForbidden();
        $this->as($u->fresh(), $this->main)->getJson('/api/v1/accounts')->assertOk();
        $this->assertDatabaseHas('transactions', ['id' => $txn['id'], 'created_by' => $u->id, 'status' => 'posted']);
        $this->assertSame('500.00', $this->balance($this->cashOf($this->kdy)));
        $this->assertNull($u->fresh()->roleInBranch($this->kdy->id));
        $this->assertTrue(AuditLog::where('event', 'user.removed_from_branch')->where('auditable_id', $u->id)->exists());
        $this->assertSame(['MAIN'], array_keys($this->meBranches($u)));

        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/remove-from-branch")->assertNotFound();
        $this->admin()->postJson("/api/v1/users/{$u->id}/remove-from-branch")->assertStatus(400);
    }

    public function test_ac15_last_branch_admin_is_protected(): void
    {
        $only = $this->userIn($this->kdy, 'Branch Admin');
        $role = $this->branchRole($this->kdy, 'Branch Admin');
        $viewer = $this->branchRole($this->kdy, 'Viewer');
        $msg = 'This is the last Branch Admin of Branch KDY. Give another user that role first.';

        $this->admin($this->kdy)->postJson("/api/v1/users/{$only->id}/role", ['role_id' => $viewer->id])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->admin($this->kdy)->postJson("/api/v1/users/{$only->id}/remove-from-branch")->assertStatus(422)->assertJsonPath('message', $msg);
        $this->admin($this->kdy)->putJson("/api/v1/users/{$only->id}", ['status' => 'inactive'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$role->id}", ['status' => 'inactive'])->assertStatus(422)->assertJsonPath('message', $msg);
        $without = array_values(array_diff($role->permissionKeys(), ['role.manage']));
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$role->id}", ['permissions' => $without])->assertStatus(422)->assertJsonPath('message', $msg);
        $without = array_values(array_diff($role->permissionKeys(), ['user.edit']));
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$role->id}", ['permissions' => $without])->assertStatus(422);

        $this->assertSame('Branch Admin', $this->roleNameIn($only, $this->kdy));
        $this->assertTrue($only->isMemberOf($this->kdy->id));
        $this->assertSame('active', $only->fresh()->status);
        $this->assertSame('active', $role->fresh()->status);
        $this->assertContains('role.manage', $role->fresh()->permissionKeys());

        // Renamed copies still count (permissions decide), and a branch that already had none is not blocked.
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$role->id}", ['name' => 'Manager'])->assertOk();
        $gle = $this->makeBranch('GLE');
        $c = $this->userIn($gle, 'Cashier');
        $this->admin($gle)->putJson("/api/v1/users/{$c->id}", ['status' => 'inactive'])->assertOk();
    }

    public function test_ac16_one_of_two_branch_admins_can_be_deactivated(): void
    {
        $a = $this->userIn($this->kdy, 'Branch Admin');
        $this->userIn($this->kdy, 'Branch Admin');
        $this->admin($this->kdy)->putJson("/api/v1/users/{$a->id}", ['status' => 'inactive'])->assertOk();
        $this->assertSame('inactive', $a->fresh()->status);
    }

    public function test_ac17_nobody_changes_their_own_access(): void
    {
        $a = $this->userIn($this->kdy, 'Branch Admin');
        $this->userIn($this->kdy, 'Branch Admin');
        $msg = 'You cannot change your own access.';
        $this->as($a, $this->kdy)->putJson("/api/v1/users/{$a->id}", ['status' => 'inactive'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->as($a, $this->kdy)->postJson("/api/v1/users/{$a->id}/role", ['role_id' => $this->branchRole($this->kdy, 'Viewer')->id])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->as($a, $this->kdy)->postJson("/api/v1/users/{$a->id}/remove-from-branch")->assertStatus(422)->assertJsonPath('message', $msg);
        $this->admin($this->kdy)->putJson("/api/v1/users/{$this->admin->id}", ['status' => 'inactive'])->assertStatus(422);
        $this->assertSame('active', $a->fresh()->status);
    }

    public function test_ac18_deactivation_revokes_all_tokens_and_blocks_login(): void
    {
        $u = User::factory()->create(['status' => 'active', 'email' => 'cash@example.com', 'password' => 'secret123']);
        $this->addTo($u, $this->kdy, 'Cashier');
        $t1 = $this->postJson('/api/v1/auth/login', ['email' => 'cash@example.com', 'password' => 'secret123'])->assertOk()->json('token');
        $t2 = $this->postJson('/api/v1/auth/login', ['email' => 'cash@example.com', 'password' => 'secret123'])->assertOk()->json('token');
        foreach ([$t1, $t2] as $t) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders()->withToken($t)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/accounts')->assertOk();
        }

        $this->admin($this->kdy)->putJson("/api/v1/users/{$u->id}", ['status' => 'inactive'])->assertOk();
        $this->assertSame(0, $u->tokens()->count());
        foreach ([$t1, $t2] as $t) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders()->withToken($t)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/accounts')->assertUnauthorized();
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson('/api/v1/auth/login', ['email' => 'cash@example.com', 'password' => 'secret123'])
            ->assertForbidden()->assertJsonPath('message', 'Your account is inactive. Contact your administrator.');
        $this->assertTrue(AuditLog::where('event', 'user.updated')->where('auditable_id', $u->id)->where('old_values->status', 'active')->exists());
    }

    public function test_ac19_reactivated_user_keeps_branches_and_roles(): void
    {
        $u = User::factory()->create(['status' => 'active', 'email' => 'back@example.com', 'password' => 'secret123']);
        $this->addTo($u, $this->kdy, 'Cashier');
        $this->addTo($u, $this->main, 'Viewer');
        $before = $this->meBranches($u);

        $this->admin()->putJson("/api/v1/users/{$u->id}", ['status' => 'inactive'])->assertOk();
        $this->admin()->putJson("/api/v1/users/{$u->id}", ['status' => 'active'])->assertOk();
        $this->flushHeaders()->postJson('/api/v1/auth/login', ['email' => 'back@example.com', 'password' => 'secret123'])->assertOk();
        $this->assertEquals($before, $this->meBranches($u));
    }

    public function test_b12_user_without_branches_cannot_log_in(): void
    {
        $u = User::factory()->create(['status' => 'active', 'email' => 'none@example.com', 'password' => 'secret123']);
        $this->flushHeaders()->postJson('/api/v1/auth/login', ['email' => 'none@example.com', 'password' => 'secret123'])
            ->assertForbidden()->assertJsonPath('message', 'You do not have access to any branch. Contact your administrator.');
    }

    public function test_ac33_invitation_link_sets_password_once_and_expires(): void
    {
        $role = $this->branchRole($this->kdy, 'Cashier');
        $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'Inv', 'email' => 'inv@example.com', 'role_id' => $role->id])->assertCreated();
        $u = User::where('email', 'inv@example.com')->first();
        $token = null;
        Notification::assertSentTo($u, InvitationNotification::class, function ($n) use (&$token, $u) {
            $token = $n->token;

            return str_contains($n->url($u), '/set-password?token=') && str_contains($n->url($u), 'email=inv%40example.com');
        });
        $expired = ['message' => 'This link has expired. Ask your administrator to resend the invitation.'];

        // weak password rejected, token still usable
        $this->flushHeaders()->postJson('/api/v1/auth/set-password', ['token' => $token, 'email' => 'inv@example.com', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/set-password', ['token' => $token, 'email' => 'inv@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['email' => 'inv@example.com', 'password' => 'newpass123'])->assertOk();
        // reused
        $this->postJson('/api/v1/auth/set-password', ['token' => $token, 'email' => 'inv@example.com', 'password' => 'other1234', 'password_confirmation' => 'other1234'])
            ->assertStatus(422)->assertJson($expired);

        // expired after 60 minutes
        $role2 = $this->branchRole($this->kdy, 'Viewer');
        $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'Late', 'email' => 'late@example.com', 'role_id' => $role2->id])->assertCreated();
        $late = User::where('email', 'late@example.com')->first();
        $lateToken = null;
        Notification::assertSentTo($late, InvitationNotification::class, function ($n) use (&$lateToken) {
            $lateToken = $n->token;

            return true;
        });
        $this->travel(61)->minutes();
        $this->flushHeaders()->postJson('/api/v1/auth/set-password', ['token' => $lateToken, 'email' => 'late@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertStatus(422)->assertJson($expired);
    }

    public function test_ac34_resend_invitation_only_before_first_login(): void
    {
        $role = $this->branchRole($this->kdy, 'Cashier');
        $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'Inv', 'email' => 'inv@example.com', 'role_id' => $role->id])->assertCreated();
        $u = User::where('email', 'inv@example.com')->first();

        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/resend-invite")->assertOk();
        Notification::assertSentToTimes($u, InvitationNotification::class, 2);
        $audit = AuditLog::where('event', 'user.invite_resent')->where('auditable_id', $u->id)->first();
        $this->assertSame($this->kdy->id, $audit->branch_id);

        $u->forceFill(['last_login_at' => now()])->save();
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/resend-invite")->assertStatus(422);
        $u->forceFill(['last_login_at' => null, 'status' => 'inactive'])->save();
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/resend-invite")->assertStatus(422);
    }

    public function test_ac41_password_is_refused_outside_local_and_testing(): void
    {
        $role = $this->branchRole($this->kdy, 'Cashier');
        $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'T', 'email' => 't@example.com', 'password' => 'secret123', 'role_id' => $role->id])->assertCreated();
        Notification::assertNotSentTo(User::where('email', 't@example.com')->first(), InvitationNotification::class);

        $this->app['env'] = 'production';
        try {
            $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'P', 'email' => 'p@example.com', 'password' => 'secret123', 'role_id' => $role->id])
                ->assertStatus(422)->assertJsonPath('errors.password.0', 'Passwords are set by the user through the invitation.');
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertNull(User::where('email', 'p@example.com')->first());
    }
}
