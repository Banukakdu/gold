<?php

namespace Tests\Feature\ReconciliationChecks;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Tests\Feature\AccountingTestCase;

/** AC 1 (fresh install) and AC 3 (granting the key): docs/specs/reconciliation-checked-by.md section 2. */
class PermissionTest extends AccountingTestCase
{
    public function test_ac1_fresh_install_catalogue_and_templates(): void
    {
        $this->assertContains('account.reconcile_check', PermissionSeeder::PERMISSIONS);
        $this->assertSame(array_search('account.reconcile', PermissionSeeder::PERMISSIONS, true) + 1,
            array_search('account.reconcile_check', PermissionSeeder::PERMISSIONS, true));
        $t = Role::whereNull('branch_id')->get()->mapWithKeys(fn (Role $r) => [$r->name => $r->permissionKeys()]);
        $this->assertContains('account.reconcile_check', $t['Branch Admin']);
        $this->assertContains('account.reconcile_check', $t['Viewer']);
        $this->assertNotContains('account.reconcile_check', $t['Accountant']);
        $this->assertNotContains('account.reconcile_check', $t['Cashier']);
        // The Roles screen lists it in the account group.
        $groups = $this->asAdmin()->getJson('/api/v1/permissions')->assertOk()->json();
        $this->assertContains('account.reconcile_check', collect($groups)->flatten()->all());
    }

    public function test_ac3_nobody_grants_the_key_without_holding_it(): void
    {
        $kdy = $this->makeBranch('KDY');
        $mgr = Role::create(['name' => 'Accountant manager', 'guard_name' => 'web', 'branch_id' => $kdy->id]);
        $mgr->syncPermissions([...PermissionSeeder::TEMPLATES['Accountant'], 'role.view', 'role.manage']);
        $acc = User::factory()->create(['status' => 'active']);
        $acc->branches()->attach($kdy->id);
        setPermissionsTeamId($kdy->id);
        $acc->assignRole($mgr);
        $viewer = $this->branchRole($kdy, 'Viewer');
        $viewer->syncPermissions(array_values(array_diff($viewer->permissionKeys(), ['account.reconcile_check']))); // an existing install's copy

        $this->actingAs($acc)->withHeader('X-Branch-Id', (string) $kdy->id)->putJson("/api/v1/roles/{$mgr->id}",
            ['permissions' => [...$mgr->permissionKeys(), 'account.reconcile_check']])
            ->assertForbidden()->assertJsonPath('message', 'You cannot grant permissions you do not have: account.reconcile_check');
        $this->assertNotContains('account.reconcile_check', $mgr->fresh()->permissionKeys());

        $ba = $this->userIn($kdy, 'Branch Admin');
        $this->actingAs($ba)->withHeader('X-Branch-Id', (string) $kdy->id)->putJson("/api/v1/roles/{$viewer->id}",
            ['permissions' => [...$viewer->fresh()->permissionKeys(), 'account.reconcile_check']])->assertOk();
        $this->assertContains('account.reconcile_check', $viewer->fresh()->permissionKeys());
        $this->assertSame(1, AuditLog::where('event', 'role.updated')->where('auditable_id', $viewer->id)->where('user_id', $ba->id)->count());
    }
}
