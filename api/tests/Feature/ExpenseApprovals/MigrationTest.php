<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Models\AuditLog;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AC 29 and section 5 "Schema": the key on a fresh seed and on an existing install (with full Branch Admin copies in
 * every branch), the cents column, and a rollback that refuses while expense requests exist. No RefreshDatabase: the
 * test changes the schema and MySQL commits DDL. It starts from migrate:fresh and, on MySQL, leaves an empty migrated
 * database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_11_30_000000_expense_approvals.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    private function templates(): array
    {
        return Role::whereNull('branch_id')->get()->mapWithKeys(fn (Role $r) => [$r->name => $r->permissionKeys()])->all();
    }

    private const AG051 = 'database/migrations/2026_11_28_000000_transfer_approvals.php';

    private function rollback(string $file = self::FILE): void
    {
        $this->artisan('migrate:rollback', ['--path' => $file, '--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A branch role holding exactly $only, inserted as an old install would have it. */
    private function copy(int $branch, string $name, array $only): int
    {
        $now = now();
        $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'branch_id' => $branch, 'created_at' => $now, 'updated_at' => $now]);
        foreach (DB::table('permissions')->whereIn('name', $only)->pluck('id') as $pid) {
            DB::table('role_has_permissions')->insert(['permission_id' => $pid, 'role_id' => $id]);
        }

        return $id;
    }

    public function test_ac29_fresh_seed_gives_the_key_to_the_star_template_only(): void
    {
        $this->assertTrue(DB::table('permissions')->where('name', 'expense.approve')->exists());
        $this->assertSame('expense.approve', PermissionSeeder::PERMISSIONS[array_search('expense.void', PermissionSeeder::PERMISSIONS, true) + 1]);
        $t = $this->templates();
        $this->assertContains('expense.approve', $t['Branch Admin']);
        foreach (['Accountant', 'Cashier', 'Viewer'] as $name) {
            $this->assertNotContains('expense.approve', $t[$name], $name);
        }
        $this->assertTrue(Schema::hasColumn('branches', 'expense_approval_threshold_cents'));
        $this->assertSame(0, DB::table('branches')->whereNotNull('expense_approval_threshold_cents')->count());
        $this->assertTrue(Schema::hasIndex('transactions', 'transactions_created_by_category_txn_date_index'));
    }

    public function test_ac29_existing_install_gets_the_key_on_full_branch_admin_copies_only(): void
    {
        $this->rollback();
        $this->assertFalse(Schema::hasColumn('branches', 'expense_approval_threshold_cents'));
        $this->assertFalse(DB::table('permissions')->where('name', 'expense.approve')->exists());

        $now = now();
        $kdy = DB::table('branches')->insertGetId(['code' => 'KDY', 'name' => 'Kandy', 'is_main' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $main = DB::table('branches')->where('is_main', true)->value('id');
        $keys = DB::table('permissions')->pluck('id', 'name');
        $copy = function (int $branch, string $name, array $only) use ($keys, $now): int {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'branch_id' => $branch, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($keys->only($only) as $pid) {
                DB::table('role_has_permissions')->insert(['permission_id' => $pid, 'role_id' => $id]);
            }

            return $id;
        };
        $all = $keys->keys()->all();
        // Review 2: AG-051 gave transfer.approve to MAIN copies only, so a real KDY Branch Admin copy lacks it.
        $kdyAdmin = $copy($kdy, 'Branch Admin', array_diff($all, ['transfer.approve']));
        $mainAdmin = $copy($main, 'Branch Admin', $all);
        $narrow = $copy($kdy, 'Clerk', array_diff($all, ['role.manage', 'transfer.approve']));
        $accountant = $copy($kdy, 'Accountant', PermissionSeeder::TEMPLATES['Accountant']);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Schema::hasColumn('branches', 'expense_approval_threshold_cents'));
        $t = $this->templates();
        $this->assertContains('expense.approve', $t['Branch Admin']);
        $this->assertNotContains('expense.approve', $t['Accountant']);
        $this->assertContains('expense.approve', Role::find($kdyAdmin)->permissionKeys());
        $this->assertContains('expense.approve', Role::find($mainAdmin)->permissionKeys());
        $this->assertNotContains('expense.approve', Role::find($narrow)->permissionKeys());
        $this->assertNotContains('transfer.approve', Role::find($kdyAdmin)->permissionKeys());
        $this->assertNotContains('expense.approve', Role::find($accountant)->permissionKeys());
        $rows = AuditLog::where('event', 'role.updated')->where('new_values->source', 'migration expense_approvals (AG-064)')->orderBy('auditable_id')->get();
        $this->assertSame([$kdyAdmin, $mainAdmin], $rows->pluck('auditable_id')->map(fn ($v) => (int) $v)->sort()->values()->all());
        $kdyRow = $rows->firstWhere('auditable_id', $kdyAdmin);
        $this->assertSame($kdy, (int) $kdyRow->branch_id);
        $this->assertNotContains('expense.approve', $kdyRow->old_values['permissions']);
        $this->assertContains('expense.approve', $kdyRow->new_values['permissions']);

        // Running again (or the seeder afterwards) adds nothing twice.
        (require base_path(self::FILE))->up();
        $this->seed(PermissionSeeder::class);
        $this->assertSame(1, DB::table('permissions')->where('name', 'expense.approve')->count());
        $this->assertCount(2, AuditLog::where('event', 'role.updated')->where('new_values->source', 'migration expense_approvals (AG-064)')->get());
    }

    /** Review 2: a pre-AG-051 install upgraded in one deploy (AG-051, AG-094, AG-064 migrations together). */
    public function test_ac29_pre_ag051_install_upgraded_in_one_deploy(): void
    {
        // AG-033 review 3: account.reconcile is added later too, so roll it back first; AG-051 and AG-064 must not
        // require it of a full copy (their exclusion lists) or they would grant nothing.
        $this->rollback('database/migrations/2026_12_01_000000_bank_reconciliation.php');
        $this->rollback();
        $this->rollback('database/migrations/2026_11_29_000000_create_notifications_table.php');
        $this->rollback(self::AG051);
        $this->assertFalse(DB::table('permissions')->whereIn('name', ['transfer.approve', 'expense.approve', 'account.reconcile'])->exists());

        $now = now();
        $kdy = DB::table('branches')->insertGetId(['code' => 'KDY', 'name' => 'Kandy', 'is_main' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $main = (int) DB::table('branches')->where('is_main', true)->value('id');
        $old = DB::table('permissions')->pluck('name')->all();
        $kdyAdmin = $this->copy($kdy, 'Branch Admin', $old);
        $mainAdmin = $this->copy($main, 'Branch Admin', $old);
        $cashier = $this->copy($kdy, 'Cashier', PermissionSeeder::TEMPLATES['Cashier']);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertContains('expense.approve', Role::find($kdyAdmin)->permissionKeys());
        $this->assertNotContains('transfer.approve', Role::find($kdyAdmin)->permissionKeys());
        $this->assertContains('expense.approve', Role::find($mainAdmin)->permissionKeys());
        $this->assertContains('transfer.approve', Role::find($mainAdmin)->permissionKeys());
        $this->assertNotContains('expense.approve', Role::find($cashier)->permissionKeys());
        // ...and AG-033 then finds both full copies (review finding 4).
        $this->assertContains('account.reconcile', Role::find($kdyAdmin)->permissionKeys());
        $this->assertContains('account.reconcile', Role::find($mainAdmin)->permissionKeys());
        $this->assertNotContains('account.reconcile', Role::find($cashier)->permissionKeys());
    }

    /** Review 5: rollback refuses while a limit is set, and audits every grant it removes. */
    public function test_rollback_refuses_while_a_limit_is_set_and_audits_removed_grants(): void
    {
        $branch = DB::table('branches')->where('is_main', true)->value('id');
        $role = $this->copy((int) $branch, 'Branch Admin', DB::table('permissions')->pluck('name')->all());
        DB::table('branches')->where('id', $branch)->update(['expense_approval_threshold_cents' => 5000000]);
        try {
            (require base_path(self::FILE))->down();
            $this->fail('Rollback did not refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expense approval limit', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('branches', 'expense_approval_threshold_cents'));
        $this->assertTrue(DB::table('permissions')->where('name', 'expense.approve')->exists());

        DB::table('branches')->update(['expense_approval_threshold_cents' => null]);
        $this->rollback();
        $this->assertFalse(Schema::hasColumn('branches', 'expense_approval_threshold_cents'));
        $row = AuditLog::where('event', 'role.updated')->where('auditable_id', $role)->where('new_values->source', 'rollback expense_approvals (AG-064)')->sole();
        $this->assertContains('expense.approve', $row->old_values['permissions']);
        $this->assertNotContains('expense.approve', $row->new_values['permissions']);
        $this->assertSame((int) $branch, (int) $row->branch_id);
        $this->assertGreaterThanOrEqual(2, AuditLog::where('new_values->source', 'rollback expense_approvals (AG-064)')->count()); // + the template
    }

    public function test_rollback_refuses_while_expense_requests_exist(): void
    {
        $now = now();
        $branch = DB::table('branches')->where('is_main', true)->value('id');
        DB::table('transactions')->insert(['branch_id' => $branch, 'type' => 'expense', 'txn_date' => '2026-11-20', 'amount' => '1.00',
            'status' => 'pending', 'approval_status' => 'pending', 'created_by' => DB::table('users')->value('id'), 'created_at' => $now, 'updated_at' => $now]);
        $this->expectException(RuntimeException::class);
        try {
            (require base_path(self::FILE))->down();
        } finally {
            $this->assertTrue(Schema::hasColumn('branches', 'expense_approval_threshold_cents'));
            $this->assertTrue(DB::table('permissions')->where('name', 'expense.approve')->exists());
        }
    }
}
