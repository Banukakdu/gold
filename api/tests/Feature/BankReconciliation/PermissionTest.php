<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\BankStatement;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/** Section 8 "Permission": AC 1 (fresh install), 2, 3, 4. AC 1 on an existing database: MigrationTest. */
class PermissionTest extends BankReconciliationTestCase
{
    public function test_ac1_catalogue_and_templates_on_a_fresh_install(): void
    {
        $i = array_search('account.manage', PermissionSeeder::PERMISSIONS, true);
        $this->assertSame('account.reconcile', PermissionSeeder::PERMISSIONS[$i + 1]);
        $t = Role::whereNull('branch_id')->get()->mapWithKeys(fn (Role $r) => [$r->name => $r->permissionKeys()]);
        $this->assertContains('account.reconcile', $t['Branch Admin']);
        $this->assertContains('account.reconcile', $t['Accountant']);
        $this->assertNotContains('account.reconcile', $t['Cashier']);
        $this->assertNotContains('account.reconcile', $t['Viewer']);
        // The screen also needs account.view, held by every template that has account.reconcile.
        foreach ($t as $name => $keys) {
            if (in_array('account.reconcile', $keys, true)) {
                $this->assertContains('account.view', $keys, $name);
            }
        }
    }

    public function test_ac2_cashier_and_viewer_get_403_on_every_route(): void
    {
        $line = $this->line($this->top)->id;
        foreach ([$this->csh, $this->vw] as $u) {
            $c = $this->as($u, $this->kdy);
            $c->getJson($this->url().'?statement_date=2026-10-31')->assertForbidden()->assertJsonPath('message', 'Missing permission: account.reconcile');
            $c->postJson($this->url(null, '/marks'), ['mark' => [['entry_id' => $line, 'cleared_date' => '2026-10-31']]])->assertForbidden()
                ->assertJsonPath('message', 'Missing permission: account.reconcile');
            $c->postJson($this->url(null, '/complete'), ['statement_date' => '2026-10-31', 'statement_balance' => '0.00'])->assertForbidden()
                ->assertJsonPath('message', 'Missing permission: account.reconcile');
            $c->postJson($this->url(null, '/statements/1/undo'), ['reason' => 'Wrong closing balance'])->assertForbidden()
                ->assertJsonPath('message', 'Missing permission: account.reconcile');
        }
        // Nobody may grant the key without holding it: a KDY role manager without account.reconcile is refused.
        $manager = Role::create(['name' => 'Manager', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $manager->syncPermissions(['role.view', 'role.manage', 'account.view']);
        $clerk = Role::create(['name' => 'Clerk', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $clerk->syncPermissions(['account.view']);
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($manager);
        $this->as($u, $this->kdy)->putJson("/api/v1/roles/{$clerk->id}", ['permissions' => ['account.view', 'account.reconcile']])->assertForbidden();
        $this->assertNotContains('account.reconcile', $clerk->fresh()->permissionKeys());
    }

    public function test_ac3_other_branch_account_is_404_and_super_admin_all_mode_works(): void
    {
        $gal = '/api/v1/accounts/'.$this->galBank->id.'/reconciliation';
        $c = $this->as($this->acc, $this->kdy);
        $c->getJson($gal.'?statement_date=2026-10-31')->assertNotFound();
        $c->postJson($gal.'/marks', ['unmark' => [1]])->assertNotFound();
        $c->postJson($gal.'/complete', ['statement_date' => '2026-10-31', 'statement_balance' => '0.00'])->assertNotFound();
        $c->postJson($gal.'/statements/1/undo', ['reason' => 'Wrong closing balance'])->assertNotFound();

        $this->sa()->getJson($gal.'?statement_date=2026-10-31')->assertOk()->assertJsonPath('account.branch.code', 'GAL');
        $this->sa()->postJson($gal.'/complete', ['statement_date' => '2026-10-31', 'statement_balance' => '0.00'])->assertCreated();
    }

    public function test_ac4_non_bank_account_is_422(): void
    {
        $this->as($this->acc, $this->kdy)->getJson('/api/v1/accounts/'.$this->kdyCash->id.'/reconciliation')
            ->assertStatus(422)->assertJsonPath('message', 'Only bank accounts can be reconciled.');
        $this->as($this->acc, $this->kdy)->postJson('/api/v1/accounts/'.$this->kdyCash->id.'/reconciliation/complete', ['statement_date' => '2026-10-31', 'statement_balance' => '0'])
            ->assertStatus(422)->assertJsonPath('message', 'Only bank accounts can be reconciled.');
    }

    public function test_statement_of_another_account_is_404(): void
    {
        $this->sa()->postJson('/api/v1/accounts/'.$this->galBank->id.'/reconciliation/complete', ['statement_date' => '2026-10-31', 'statement_balance' => '0.00'])->assertCreated();
        $id = BankStatement::withoutGlobalScopes()->sole()->id;
        $this->undo($id, by: $this->admin, branch: 'all')->assertNotFound();
    }
}
