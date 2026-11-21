<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

/** Spec users-and-roles: roles, templates (AC 20-32, 45, 46). */
class RoleManagementTest extends UsersAndRolesTestCase
{
    public function test_ac20_role_names_are_unique_per_branch_ignoring_case_and_spaces(): void
    {
        $this->branchRole($this->kdy, 'Cashier');
        $this->admin($this->kdy)->postJson('/api/v1/roles', ['name' => '  cashier ', 'permissions' => ['transaction.view']])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $gle = $this->makeBranch('GLE');
        $this->admin($gle)->postJson('/api/v1/roles', ['name' => 'Cashier', 'permissions' => ['transaction.view']])
            ->assertCreated()->assertJsonPath('branch_id', $gle->id);
        // templates are unique among templates
        $this->admin()->postJson('/api/v1/roles', ['name' => 'VIEWER'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_ac21_rename_to_existing_name_is_422(): void
    {
        $this->branchRole($this->kdy, 'Cashier');
        $viewer = $this->branchRole($this->kdy, 'Viewer');
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$viewer->id}", ['name' => 'CASHIER'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$viewer->id}", ['name' => 'viewer'])->assertOk()->assertJsonPath('name', 'viewer');
    }

    public function test_ac22_non_super_admin_cannot_grant_missing_permissions(): void
    {
        $mgr = $this->customRole($this->kdy, 'Role Clerk', ['role.view', 'role.manage', 'transaction.view']);
        $actor = $this->userWith($this->kdy, $mgr);
        $this->as($actor, $this->kdy)->postJson('/api/v1/roles', ['name' => 'Bad', 'permissions' => ['transaction.view', 'audit.view', 'income.void']])
            ->assertForbidden()->assertJsonPath('message', 'You cannot grant permissions you do not have: audit.view, income.void');
        $this->assertNull(Role::where('name', 'Bad')->first());
        $own = $this->as($actor, $this->kdy)->postJson('/api/v1/roles', ['name' => 'Ok', 'permissions' => ['transaction.view']])->assertCreated()->json();
        $this->as($actor, $this->kdy)->putJson("/api/v1/roles/{$own['id']}", ['permissions' => ['transaction.view', 'audit.view']])
            ->assertForbidden()->assertJsonPath('message', 'You cannot grant permissions you do not have: audit.view');
    }

    public function test_ac23_non_super_admin_cannot_edit_role_with_more_permissions(): void
    {
        $mgr = $this->customRole($this->kdy, 'Role Clerk', ['role.view', 'role.manage', 'transaction.view']);
        $actor = $this->userWith($this->kdy, $mgr);
        $acct = $this->branchRole($this->kdy, 'Accountant');
        $this->as($actor, $this->kdy)->putJson("/api/v1/roles/{$acct->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->as($actor, $this->kdy)->putJson("/api/v1/roles/{$acct->id}", ['status' => 'inactive'])->assertForbidden();
        $this->assertSame('Accountant', $acct->fresh()->name);
    }

    public function test_ac24_permission_catalogue_grouped_by_module_each_key_once(): void
    {
        $groups = $this->admin($this->kdy)->getJson('/api/v1/permissions')->assertOk()->json();
        $all = collect($groups)->flatten();
        $this->assertEqualsCanonicalizing(PermissionSeeder::PERMISSIONS, $all->all());
        $this->assertSame($all->count(), $all->unique()->count());
        foreach ($groups as $module => $keys) {
            foreach ($keys as $k) {
                $this->assertStringStartsWith("$module.", $k);
            }
        }
    }

    public function test_ac25_ac26_deactivated_role_grants_nothing_until_reactivated(): void
    {
        $this->userIn($this->kdy, 'Branch Admin');
        $a = $this->userIn($this->kdy, 'Cashier');
        $b = $this->userIn($this->kdy, 'Cashier');
        $role = $this->branchRole($this->kdy, 'Cashier');

        $listed = collect($this->admin($this->kdy)->getJson('/api/v1/roles')->json())->firstWhere('id', $role->id);
        $this->assertSame(2, $listed['users_count']);

        $this->admin($this->kdy)->putJson("/api/v1/roles/{$role->id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame('inactive', $role->fresh()->status);
        foreach ([$a, $b] as $u) {
            $this->assertTrue($u->isMemberOf($this->kdy->id));
            $me = $this->meBranches($u)['KDY'];
            $this->assertSame([], $me['permissions']);
            $this->assertSame([], $me['roles']);
        }
        $this->as($a->fresh(), $this->kdy)->postJson('/api/v1/incomes', [])->assertForbidden();
        $row = collect($this->admin($this->kdy)->getJson('/api/v1/users')->json('data'))->firstWhere('id', $a->id);
        $this->assertSame('inactive', $row['memberships'][0]['role_status']);
        $this->assertSame('Cashier', $row['memberships'][0]['role_name']);
        $this->assertSame([], $row['roles_in_branch']);

        $this->admin($this->kdy)->putJson("/api/v1/roles/{$role->id}", ['status' => 'active'])->assertOk();
        $this->assertContains('income.create', $this->meBranches($a)['KDY']['permissions']);
        $this->as($a->fresh(), $this->kdy)->postJson('/api/v1/incomes', [])->assertStatus(422); // allowed again, fails validation
    }

    public function test_ac27_no_delete_endpoints_for_users_roles_or_templates(): void
    {
        foreach (Route::getRoutes() as $r) {
            if (in_array('DELETE', $r->methods(), true)) {
                $this->assertDoesNotMatchRegularExpression('#users|roles|audit#', $r->uri());
            }
        }
        $u = $this->userIn($this->kdy, 'Viewer');
        $this->admin($this->kdy)->deleteJson("/api/v1/users/{$u->id}")->assertStatus(405);
        $this->admin($this->kdy)->deleteJson('/api/v1/roles/'.$this->branchRole($this->kdy, 'Viewer')->id)->assertStatus(405);
    }

    public function test_ac28_ac45_fresh_install_templates(): void
    {
        $tpl = Role::whereNull('branch_id')->get()->keyBy('name');
        $this->assertEqualsCanonicalizing(['Branch Admin', 'Accountant', 'Cashier', 'Viewer'], $tpl->keys()->all());
        $this->assertEqualsCanonicalizing(PermissionSeeder::PERMISSIONS, $tpl['Branch Admin']->permissionKeys());
        $this->assertEqualsCanonicalizing(['branch.view', 'account.view', 'account.manage', 'transfer.create', 'transfer.reverse', 'transaction.view',
            'income.create', 'income.edit', 'income.void', 'expense.create', 'expense.edit', 'expense.void',
            'report.dashboard', 'report.view', 'report.export', 'audit.view', 'category.manage', 'transfer.acknowledge'], $tpl['Accountant']->permissionKeys());
        $this->assertEqualsCanonicalizing(['branch.view', 'account.view', 'transaction.view', 'income.create', 'expense.create', 'report.dashboard',
            'transfer.acknowledge'], $tpl['Cashier']->permissionKeys());
        $this->assertEqualsCanonicalizing(['branch.view', 'account.view', 'transaction.view', 'report.dashboard', 'report.view', 'audit.view'], $tpl['Viewer']->permissionKeys());
        foreach (['Accountant', 'Cashier', 'Viewer'] as $n) {
            $this->assertEmpty(array_filter($tpl[$n]->permissionKeys(), fn ($k) => str_starts_with($k, 'user.') || str_starts_with($k, 'role.')));
        }
    }

    public function test_ac29_ac39_copy_templates_into_branch(): void
    {
        $res = $this->admin($this->kdy)->postJson('/api/v1/roles/copy-templates')->assertCreated()->json();
        $this->assertEqualsCanonicalizing(['Branch Admin', 'Accountant', 'Cashier', 'Viewer'], $res['created']);
        $this->assertSame([], $res['skipped']);
        foreach (Role::whereNull('branch_id')->get() as $tpl) {
            $copy = Role::where('branch_id', $this->kdy->id)->where('name', $tpl->name)->firstOrFail();
            $this->assertSame($tpl->permissionKeys(), $copy->permissionKeys());
            $audit = AuditLog::where('event', 'role.copied_from_templates')->where('auditable_id', $copy->id)->firstOrFail();
            $this->assertSame($this->kdy->id, $audit->branch_id);
            // MySQL JSON columns do not keep key order, so compare by key.
            $this->assertSame(['name', 'permissions', 'template_id'], array_values(Arr::sort(array_keys($audit->new_values))));
            $this->assertSame($tpl->id, $audit->new_values['template_id']);
            $this->assertSame($tpl->name, $audit->new_values['name']);
            $this->assertSame($tpl->permissionKeys(), $audit->new_values['permissions']);
        }

        $again = $this->admin($this->kdy)->postJson('/api/v1/roles/copy-templates')->assertCreated()->json();
        $this->assertSame([], $again['created']);
        $this->assertEqualsCanonicalizing(['Branch Admin', 'Accountant', 'Cashier', 'Viewer'], $again['skipped']);
        $this->admin()->postJson('/api/v1/roles/copy-templates')->assertStatus(400);
    }

    public function test_ac30_templates_and_copies_are_independent(): void
    {
        $copy = $this->branchRole($this->kdy, 'Cashier');
        $tpl = Role::whereNull('branch_id')->where('name', 'Cashier')->first();
        $before = $copy->permissionKeys();

        $this->admin()->putJson("/api/v1/roles/{$tpl->id}", ['permissions' => ['transaction.view']])->assertOk();
        $this->assertSame($before, $copy->fresh()->permissionKeys());

        $this->admin($this->kdy)->putJson("/api/v1/roles/{$copy->id}", ['permissions' => ['transaction.view', 'report.view']])->assertOk();
        $this->assertSame(['transaction.view'], $tpl->fresh()->permissionKeys());
    }

    public function test_ac31_only_super_admin_copies_or_edits_templates(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $this->as($badmin, $this->kdy)->postJson('/api/v1/roles/copy-templates')->assertForbidden();
        $tpl = Role::whereNull('branch_id')->where('name', 'Viewer')->first();
        $this->as($badmin, $this->kdy)->putJson("/api/v1/roles/{$tpl->id}", ['name' => 'X'])->assertForbidden();
        $this->assertSame('Viewer', $tpl->fresh()->name);
        // non-Super Admin still sees templates read-only
        $roles = collect($this->as($badmin, $this->kdy)->getJson('/api/v1/roles')->assertOk()->json());
        $this->assertCount(4, $roles->where('is_template', true));
    }

    public function test_ac32_all_branches_mode(): void
    {
        $u = $this->addTo($this->userIn($this->kdy, 'Cashier'), $this->main, 'Viewer');
        $rows = collect($this->admin('all')->getJson('/api/v1/users')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(User::pluck('id')->all(), $rows->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['MAIN · Viewer', 'KDY · Cashier'],
            collect($rows->firstWhere('id', $u->id)['memberships'])->map(fn ($m) => "{$m['branch_code']} · {$m['role_name']}")->all());
        $this->assertTrue($rows->firstWhere('id', $this->admin->id)['is_super_admin']);

        $roles = collect($this->admin('all')->getJson('/api/v1/roles')->json());
        $this->assertTrue($roles->every(fn ($r) => $r['is_template']));
        $this->admin('all')->postJson('/api/v1/users', ['name' => 'N', 'email' => 'n@example.com', 'role_id' => $this->branchRole($this->kdy, 'Viewer')->id])->assertStatus(400);
    }

    public function test_ac35_users_without_permission_cannot_reach_users_or_roles(): void
    {
        $cashier = $this->userIn($this->kdy, 'Cashier');
        $u = $this->userIn($this->kdy, 'Viewer');
        $this->as($cashier, $this->kdy)->getJson('/api/v1/users')->assertForbidden();
        $this->as($cashier, $this->kdy)->getJson("/api/v1/users/{$u->id}")->assertForbidden();
        $this->as($cashier, $this->kdy)->getJson('/api/v1/roles')->assertForbidden();
        $this->as($cashier, $this->kdy)->getJson('/api/v1/permissions')->assertForbidden();
    }

    public function test_ac46_reseeding_does_not_reset_templates_or_touch_copies(): void
    {
        $tpl = Role::whereNull('branch_id')->where('name', 'Accountant')->first();
        $copy = $this->branchRole($this->kdy, 'Accountant');
        $copy->syncPermissions(['transaction.view']);
        $this->admin()->putJson("/api/v1/roles/{$tpl->id}", ['permissions' => array_values(array_diff($tpl->permissionKeys(), ['report.export']))])->assertOk();

        $this->seed(PermissionSeeder::class);
        $this->assertNotContains('report.export', $tpl->fresh()->permissionKeys());
        $this->assertSame(['transaction.view'], $copy->fresh()->permissionKeys());
    }
}
