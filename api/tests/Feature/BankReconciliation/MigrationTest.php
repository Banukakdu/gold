<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AC 1 on an existing database and section 5 "Schema": the key reaches the "*" templates only, the tables and their
 * one-active-row indexes exist, a rollback refuses while reconciliation rows exist, and rollback + migrate is clean.
 * No RefreshDatabase: the test changes the schema and MySQL commits DDL. It starts from migrate:fresh and, on MySQL,
 * leaves an empty migrated database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_01_000000_bank_reconciliation.php';

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

    private function rollback(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::FILE, '--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function templates(): array
    {
        return Role::whereNull('branch_id')->get()->mapWithKeys(fn (Role $r) => [$r->name => $r->permissionKeys()])->all();
    }

    public function test_ac1_existing_install_gets_the_key_on_star_templates_and_full_branch_copies(): void
    {
        $this->rollback();
        $this->assertFalse(Schema::hasTable('bank_statements'));
        $this->assertFalse(Schema::hasTable('bank_reconciliation_marks'));
        $this->assertFalse(DB::table('permissions')->where('name', 'account.reconcile')->exists());
        // A KDY Branch Admin copy holding every key and an Accountant copy, as an old install has them.
        $now = now();
        $kdy = DB::table('branches')->insertGetId(['code' => 'KDY', 'name' => 'Kandy', 'is_main' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $copy = function (string $name, array $keys) use ($kdy, $now): int {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'branch_id' => $kdy, 'created_at' => $now, 'updated_at' => $now]);
            foreach (DB::table('permissions')->whereIn('name', $keys)->pluck('id') as $pid) {
                DB::table('role_has_permissions')->insert(['permission_id' => $pid, 'role_id' => $id]);
            }

            return $id;
        };
        $admin = $copy('Branch Admin', DB::table('permissions')->pluck('name')->all());
        // Review finding 4: a non-MAIN full copy lacks the MAIN-only transfer.approve and still qualifies; a narrowed one does not.
        $kdyAdmin = $copy('Branch Admin 2', array_diff(DB::table('permissions')->pluck('name')->all(), ['transfer.approve']));
        $narrowed = $copy('Branch Admin 3', array_diff(DB::table('permissions')->pluck('name')->all(), ['audit.view']));
        $accountant = $copy('Accountant', array_diff(PermissionSeeder::TEMPLATES['Accountant'], ['account.reconcile']));
        // The Accountant template of an old install does not hold the key either.
        $tpl = Role::whereNull('branch_id')->where('name', 'Accountant')->firstOrFail();
        $before = DB::table('role_has_permissions')->whereNotIn('role_id', Role::whereNull('branch_id')->where('name', 'Branch Admin')->pluck('id'))->count();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Schema::hasTable('bank_statements'));
        $this->assertTrue(Schema::hasTable('bank_reconciliation_marks'));
        $t = $this->templates();
        $this->assertContains('account.reconcile', $t['Branch Admin']);
        foreach (['Accountant', 'Cashier', 'Viewer'] as $name) {
            $this->assertNotContains('account.reconcile', $t[$name], $name);
        }
        $this->assertContains('account.reconcile', Role::find($admin)->permissionKeys());
        $this->assertContains('account.reconcile', Role::find($kdyAdmin)->permissionKeys());
        $this->assertNotContains('account.reconcile', Role::find($narrowed)->permissionKeys());
        $this->assertNotContains('account.reconcile', Role::find($accountant)->permissionKeys());
        $this->assertNotContains('account.reconcile', $tpl->fresh()->permissionKeys());
        // No other role gained anything: the two full copies gained one key each.
        $this->assertSame($before + 2, DB::table('role_has_permissions')->whereNotIn('role_id', Role::whereNull('branch_id')->where('name', 'Branch Admin')->pluck('id'))->count());
        // Each grant is audited as a Roles-screen change would be.
        $audits = DB::table('audit_logs')->where('event', 'role.updated')->where('auditable_type', Role::class)->whereIn('auditable_id', [$admin, $kdyAdmin])->get();
        $this->assertCount(2, $audits);
        foreach ($audits as $a) {
            $this->assertSame($kdy, (int) $a->branch_id);
            $new = json_decode($a->new_values, true);
            $old = json_decode($a->old_values, true);
            $this->assertSame('migration bank_reconciliation (AG-033)', $new['source']);
            $this->assertSame(['account.reconcile'], array_values(array_diff($new['permissions'], $old['permissions'])));
        }

        // Running again (a partial run, or the seeder afterwards) adds nothing twice and changes no template.
        (require base_path(self::FILE))->up();
        $this->seed(PermissionSeeder::class);
        $this->assertSame($t, $this->templates());
        $this->assertSame(1, DB::table('permissions')->where('name', 'account.reconcile')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'role.updated')->whereIn('auditable_id', [$admin, $kdyAdmin])->count());
    }

    /** Review finding 5: a run that stopped after the tables were created adds the one-active-row constraints next time. */
    public function test_resumes_after_tables_without_active_constraints(): void
    {
        $this->rollback();
        $m = require base_path(self::FILE);
        $m->up();
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('ALTER TABLE bank_reconciliation_marks DROP INDEX bank_reconciliation_marks_active_key_unique');
            DB::statement('ALTER TABLE bank_reconciliation_marks DROP COLUMN active_key');
            DB::statement('ALTER TABLE bank_statements DROP INDEX bank_statements_active_key_unique');
        } else {
            DB::statement('DROP INDEX bank_reconciliation_marks_active_unique');
            DB::statement('DROP INDEX bank_statements_active_unique');
        }
        $m->up();
        $this->assertTrue(Schema::hasIndex('bank_reconciliation_marks', $mysql ? 'bank_reconciliation_marks_active_key_unique' : 'bank_reconciliation_marks_active_unique'));
        $this->assertTrue(Schema::hasIndex('bank_statements', $mysql ? 'bank_statements_active_key_unique' : 'bank_statements_active_unique'));
        $m->up(); // and once more: nothing to do
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_one_active_row_indexes(): void
    {
        $admin = User::where('is_super_admin', true)->firstOrFail();
        $acc = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();
        $main = Branch::main();
        $row = ['branch_id' => $main->id, 'account_id' => $acc->id, 'statement_date' => '2026-10-31', 'statement_balance_cents' => 0, 'reconciled_cents' => 0,
            'book_balance_cents' => 0, 'uncleared_receipts_cents' => 0, 'uncleared_payments_cents' => 0, 'cleared_booked_later_cents' => 0, 'self_count' => 0,
            'lines_count' => 0, 'status' => 'undone', 'completed_by' => $admin->id, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('bank_statements')->insert($row);
        DB::table('bank_statements')->insert(['status' => 'completed'] + $row);
        try {
            DB::table('bank_statements')->insert(['status' => 'completed'] + $row);
            $this->fail('two completed statements of one account and date');
        } catch (QueryException) {
            $this->assertSame(2, DB::table('bank_statements')->count());
        }
        DB::table('bank_statements')->delete();
    }

    public function test_rollback_stops_while_reconciliation_rows_exist_then_rolls_back_cleanly(): void
    {
        $admin = User::where('is_super_admin', true)->firstOrFail();
        $acc = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();
        $id = DB::table('bank_statements')->insertGetId(['branch_id' => $acc->branch_id, 'account_id' => $acc->id, 'statement_date' => '2026-10-31',
            'statement_balance_cents' => 0, 'reconciled_cents' => 0, 'book_balance_cents' => 0, 'uncleared_receipts_cents' => 0, 'uncleared_payments_cents' => 0,
            'cleared_booked_later_cents' => 0, 'self_count' => 0, 'lines_count' => 0, 'status' => 'completed', 'completed_by' => $admin->id,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        try {
            (require base_path(self::FILE))->down();
            $this->fail('rollback did not stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('bank_statements'));
        $this->assertTrue(DB::table('permissions')->where('name', 'account.reconcile')->exists());
        DB::table('bank_statements')->where('id', $id)->delete();

        $holders = DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'account.reconcile')->pluck('id'))->count();
        $this->rollback();
        $this->assertFalse(Schema::hasTable('bank_statements'));
        $this->assertFalse(DB::table('permissions')->where('name', 'account.reconcile')->exists());
        $this->assertSame($holders, AuditLog::where('event', 'role.updated')->where('new_values->source', 'rollback bank_reconciliation (AG-033)')->count());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('bank_reconciliation_marks'));
        // A second rollback and migrate is as clean as the first.
        $this->rollback();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('bank_statements'));
    }
}
