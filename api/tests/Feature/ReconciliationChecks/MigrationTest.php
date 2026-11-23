<?php

namespace Tests\Feature\ReconciliationChecks;

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
 * AC 1 on an existing database and section 5 "Schema": the key reaches the "*" templates and the full Branch Admin
 * copies (audited), the Viewer and Accountant roles are unchanged, the table, its one-active-check index and triggers
 * exist, a rollback refuses while a check exists and otherwise removes the key with audited role rows.
 * No RefreshDatabase: the test changes the schema and MySQL commits DDL. It starts from migrate:fresh and, on MySQL,
 * leaves an empty migrated database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_07_000000_reconciliation_checks.php';

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

    public function test_ac1_existing_install_gets_the_key_on_star_templates_and_full_branch_admin_copies(): void
    {
        $this->rollback();
        $this->assertFalse(Schema::hasTable('bank_statement_checks'));
        $this->assertFalse(DB::table('permissions')->where('name', 'account.reconcile_check')->exists());
        // The Viewer template of an old install does not hold the key (the seeder gives it to new installs only).
        $viewerTpl = Role::whereNull('branch_id')->where('name', 'Viewer')->firstOrFail();
        $this->assertNotContains('account.reconcile_check', $viewerTpl->permissionKeys());

        $now = now();
        $kdy = DB::table('branches')->insertGetId(['code' => 'KDY', 'name' => 'Kandy', 'is_main' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $copy = function (string $name, array $keys) use ($kdy, $now): int {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'branch_id' => $kdy, 'created_at' => $now, 'updated_at' => $now]);
            foreach (DB::table('permissions')->whereIn('name', $keys)->pluck('id') as $pid) {
                DB::table('role_has_permissions')->insert(['permission_id' => $pid, 'role_id' => $id]);
            }

            return $id;
        };
        $all = DB::table('permissions')->pluck('name')->all();
        $admin = $copy('Branch Admin', array_diff($all, ['transfer.approve']));
        $narrowed = $copy('Branch Admin 2', array_diff($all, ['transfer.approve', 'audit.view']));
        $viewer = $copy('Viewer', array_diff(PermissionSeeder::TEMPLATES['Viewer'], ['account.reconcile_check']));
        $accountant = $copy('Accountant', PermissionSeeder::TEMPLATES['Accountant']);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Schema::hasTable('bank_statement_checks'));
        $t = $this->templates();
        $this->assertContains('account.reconcile_check', $t['Branch Admin']);
        foreach (['Accountant', 'Cashier', 'Viewer'] as $name) {
            $this->assertNotContains('account.reconcile_check', $t[$name], $name);
        }
        $this->assertContains('account.reconcile_check', Role::find($admin)->permissionKeys());
        foreach ([$narrowed, $viewer, $accountant] as $id) {
            $this->assertNotContains('account.reconcile_check', Role::find($id)->permissionKeys());
        }
        $audit = AuditLog::where('event', 'role.updated')->where('new_values->source', 'migration reconciliation_checks (AG-033d)')->sole();
        $this->assertSame([$admin, $kdy], [(int) $audit->auditable_id, (int) $audit->branch_id]);
        $this->assertSame(['account.reconcile_check'], array_values(array_diff($audit->new_values['permissions'], $audit->old_values['permissions'])));

        // Running again (a partial run, or the seeder afterwards) adds nothing twice and changes no template.
        (require base_path(self::FILE))->up();
        $this->seed(PermissionSeeder::class);
        $this->assertSame($t, $this->templates());
        $this->assertSame(1, DB::table('permissions')->where('name', 'account.reconcile_check')->count());
        $this->assertSame(1, AuditLog::where('new_values->source', 'migration reconciliation_checks (AG-033d)')->count());
    }

    public function test_schema_triggers_and_rollback(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $this->assertTrue(Schema::hasIndex('bank_statement_checks', $mysql ? 'bank_statement_checks_active_key_unique' : 'bank_statement_checks_active_unique'));
        $triggers = $mysql
            ? array_column(DB::select('SHOW TRIGGERS WHERE `Table` = ?', ['bank_statement_checks']), 'Trigger')
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'bank_statement_checks')->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['bank_statement_checks_no_update', 'bank_statement_checks_no_delete'], $triggers);

        // Rollback refuses while a check exists (its rows cannot be deleted either, so the refusal is final).
        $admin = DB::table('users')->where('is_super_admin', true)->value('id');
        $acc = DB::table('accounts')->where('is_main_account', true)->first();
        $sid = DB::table('bank_statements')->insertGetId(['branch_id' => $acc->branch_id, 'account_id' => $acc->id, 'statement_date' => '2026-10-31',
            'statement_balance_cents' => 0, 'reconciled_cents' => 0, 'book_balance_cents' => 0, 'uncleared_receipts_cents' => 0, 'uncleared_payments_cents' => 0,
            'cleared_booked_later_cents' => 0, 'self_count' => 0, 'lines_count' => 0, 'status' => 'completed', 'completed_by' => $admin,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bank_statement_checks')->insert(['branch_id' => $acc->branch_id, 'bank_statement_id' => $sid, 'account_id' => $acc->id, 'checked_by' => $admin,
            'checked_at' => now(), 'compared_with' => 'internet_banking', 'created_at' => now(), 'updated_at' => now()]);
        try {
            (require base_path(self::FILE))->down();
            $this->fail('rollback did not stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('bank_statement_checks'));
        $this->assertTrue(DB::table('permissions')->where('name', 'account.reconcile_check')->exists());
        if ($mysql) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            $this->seed(DatabaseSeeder::class);
        } else {
            // SQLite: drop the delete trigger for the clean-up only.
            DB::unprepared('DROP TRIGGER bank_statement_checks_no_delete');
            DB::table('bank_statement_checks')->delete();
            DB::table('bank_statements')->delete();
        }

        $holders = DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'account.reconcile_check')->pluck('id'))->count();
        $this->rollback();
        $this->assertFalse(Schema::hasTable('bank_statement_checks'));
        $this->assertFalse(DB::table('permissions')->where('name', 'account.reconcile_check')->exists());
        $this->assertSame($holders, AuditLog::where('event', 'role.updated')->where('new_values->source', 'rollback reconciliation_checks (AG-033d)')->count());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('bank_statement_checks'));
    }
}
