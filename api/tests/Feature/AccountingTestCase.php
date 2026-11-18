<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AccountingTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Branch $main;

    protected Account $mainAcc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('is_super_admin', true)->first();
        $this->main = Branch::main();
        $this->mainAcc = Account::withoutGlobalScopes()->where('is_main_account', true)->first();
    }

    protected function asAdmin(string|int $branch = 'all')
    {
        return $this->actingAs($this->admin)->withHeader('X-Branch-Id', (string) $branch);
    }

    protected function makeBranch(string $code): Branch
    {
        $this->asAdmin()->postJson('/api/v1/branches', ['code' => $code, 'name' => "Branch $code"])->assertCreated();

        return Branch::where('code', $code)->first();
    }

    protected function cashOf(Branch $b): Account
    {
        return Account::withoutGlobalScopes()->where('branch_id', $b->id)->where('name', 'Cash')->first();
    }

    /** Member of the branch holding the branch copy of a template (created from the template if missing). */
    protected function userIn(Branch $b, string $role): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($b->id);
        setPermissionsTeamId($b->id);
        $u->assignRole($this->branchRole($b, $role));

        return $u;
    }

    protected function branchRole(Branch $b, string $name): Role
    {
        $existing = Role::where('branch_id', $b->id)->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }
        $tpl = Role::where('name', $name)->whereNull('branch_id')->firstOrFail();
        $role = Role::create(['name' => $name, 'guard_name' => 'web', 'branch_id' => $b->id]);
        $role->syncPermissions($tpl->permissionKeys());

        return $role;
    }

    protected function category(string $type): Category
    {
        return Category::where('type', $type)->whereNotNull('parent_id')->first();
    }

    protected function balance(Account $a): string
    {
        return (string) Account::withoutGlobalScopes()->find($a->id)->current_balance;
    }
}
