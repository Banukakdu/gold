<?php

namespace Tests\Feature\ExpenseTemplates;

use App\Models\AuditLog;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AC 32 and section 5 "Schema": the table and the column exist, no permission or role changes, rollback refuses while a
 * template exists, and rollback + migrate is clean (on MySQL the composite index backs the foreign key, so the key is
 * dropped first). No RefreshDatabase: the test changes the schema and MySQL commits DDL. It starts from migrate:fresh
 * and, on MySQL, leaves an empty migrated database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_02_000000_recurring_templates.php';

    private const INDEX = 'transactions_expense_template_txn_date_index';

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
    }

    private function grants(): array
    {
        return DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_ac32_schema_exists_and_no_permission_or_role_changes(): void
    {
        $this->assertTrue(Schema::hasTable('recurring_templates'));
        foreach (['branch_id', 'type', 'name', 'account_id', 'category_id', 'amount_cents', 'payee_payer', 'payment_method', 'description',
            'frequency', 'version', 'created_by', 'updated_by', 'archived_at', 'archived_by', 'archive_reason', 'created_at', 'updated_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('recurring_templates', $c), $c);
        }
        $this->assertTrue(Schema::hasColumn('transactions', 'expense_template_id'));
        $this->assertTrue(Schema::hasIndex('transactions', self::INDEX));
        $this->assertTrue(Schema::hasIndex('recurring_templates', ['branch_id', 'archived_at']));
        $this->assertSame([], array_values(array_filter(PermissionSeeder::PERMISSIONS, fn ($p) => str_contains($p, 'template'))));
        $this->assertSame(count(PermissionSeeder::PERMISSIONS), DB::table('permissions')->count());

        $permissions = DB::table('permissions')->orderBy('id')->pluck('name')->all();
        $grants = $this->grants();
        $roles = DB::table('roles')->count();
        $this->rollback();
        $this->assertFalse(Schema::hasTable('recurring_templates'));
        $this->assertFalse(Schema::hasColumn('transactions', 'expense_template_id'));
        $this->assertFalse(Schema::hasIndex('transactions', self::INDEX));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('transactions', 'expense_template_id'));
        $this->assertSame($permissions, DB::table('permissions')->orderBy('id')->pluck('name')->all());
        $this->assertSame($grants, $this->grants());
        $this->assertSame($roles, DB::table('roles')->count());
        $this->assertSame(0, AuditLog::where('event', 'role.updated')->count());
        if (DB::connection()->getDriverName() === 'mysql') {
            $fks = array_filter(Schema::getForeignKeys('transactions'), fn ($fk) => $fk['columns'] === ['expense_template_id']);
            $this->assertCount(1, $fks);
        }
    }

    public function test_ac32_rollback_refuses_while_a_template_exists(): void
    {
        $now = now();
        $branch = DB::table('branches')->where('is_main', true)->value('id');
        $account = DB::table('accounts')->where('branch_id', $branch)->whereIn('type', ['cash', 'bank', 'wallet'])->value('id');
        $category = DB::table('categories')->where('type', 'expense')->whereNotNull('parent_id')->value('id');
        DB::table('recurring_templates')->insert(['branch_id' => $branch, 'type' => 'expense', 'name' => 'Rent', 'account_id' => $account,
            'category_id' => $category, 'frequency' => 'monthly', 'version' => 1, 'created_by' => DB::table('users')->value('id'),
            'created_at' => $now, 'updated_at' => $now]);
        try {
            (require base_path(self::FILE))->down();
            $this->fail('Rollback did not refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Expense templates exist; rollback would lose them.', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('recurring_templates'));
        $this->assertTrue(Schema::hasColumn('transactions', 'expense_template_id'));
        $this->assertTrue(Schema::hasIndex('transactions', self::INDEX));
    }
}
