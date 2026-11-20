<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\BusinessDate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AC 26 and section 5: the permission key on a fresh seed and on an existing install, and a clean, resumable rollback.
 * No RefreshDatabase: the test changes the schema and MySQL commits DDL. It starts from migrate:fresh and, on MySQL,
 * leaves an empty migrated database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_11_26_000000_transfer_receipts.php';

    private const COLUMNS = ['receipt_status', 'received_on', 'receipt_note', 'receipt_by', 'receipt_at'];

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

    /** @return array<string, string[]> template name => keys */
    private function templates(): array
    {
        return Role::whereNull('branch_id')->get()->mapWithKeys(fn (Role $r) => [$r->name => $r->permissionKeys()])->all();
    }

    private function rollback(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::FILE, '--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_ac26_fresh_seed_gives_the_key_to_branch_admin_accountant_and_cashier(): void
    {
        $this->assertTrue(DB::table('permissions')->where('name', 'transfer.acknowledge')->exists());
        $t = $this->templates();
        foreach (['Branch Admin', 'Accountant', 'Cashier'] as $name) {
            $this->assertContains('transfer.acknowledge', $t[$name], $name);
        }
        $this->assertNotContains('transfer.acknowledge', $t['Viewer']);
        $this->assertSame('0', DB::table('settings')->where('key', 'transfer_receipt_required')->value('value'));
    }

    public function test_ac26_existing_install_gets_the_key_on_star_templates_only_and_rollback_is_clean(): void
    {
        $indexes = array_column(Schema::getIndexes('transactions'), 'name');
        $this->assertContains('transactions_receipt_by_fk', $indexes);
        $this->assertContains('transactions_related_branch_receipt_index', $indexes);

        // The database as before this release.
        $this->rollback();
        foreach (self::COLUMNS as $c) {
            $this->assertFalse(Schema::hasColumn('transactions', $c), $c);
        }
        $this->assertFalse(DB::table('permissions')->where('name', 'transfer.acknowledge')->exists());
        $this->assertFalse(DB::table('settings')->where('key', 'transfer_receipt_required')->exists());
        $left = array_column(Schema::getIndexes('transactions'), 'name');
        $this->assertNotContains('transactions_receipt_by_fk', $left);
        $this->assertNotContains('transactions_related_branch_receipt_index', $left);
        $this->assertSame([], array_filter(Schema::getForeignKeys('transactions'), fn ($fk) => $fk['columns'] === ['receipt_by']));

        // Upgrade: the migration adds the key to the "*" templates (Branch Admin) only, as a re-seed would.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::COLUMNS as $c) {
            $this->assertTrue(Schema::hasColumn('transactions', $c), $c);
        }
        $t = $this->templates();
        $this->assertContains('transfer.acknowledge', $t['Branch Admin']);
        $this->assertNotContains('transfer.acknowledge', $t['Accountant']);
        $this->assertNotContains('transfer.acknowledge', $t['Cashier']);
        $this->assertSame('0', DB::table('settings')->where('key', 'transfer_receipt_required')->value('value'));
        $this->assertSame(1, DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'transfer.acknowledge')->pluck('id'))->count());

        // Running again (a partial run, or the seeder afterwards) adds nothing twice and changes no template.
        (require base_path(self::FILE))->up();
        $this->seed(PermissionSeeder::class);
        $this->assertSame($t, $this->templates());
        $this->assertSame(1, DB::table('permissions')->where('name', 'transfer.acknowledge')->count());
    }

    public function test_rollback_stops_while_receipts_exist(): void
    {
        $admin = User::where('is_super_admin', true)->firstOrFail();
        Auth::setUser($admin);
        $acc = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();
        $t = app(LedgerService::class)->capital($acc->id, '100.00', BusinessDate::today());
        DB::table('transactions')->where('id', $t->id)->update(['receipt_status' => 'received']);

        try {
            (require base_path(self::FILE))->down();
            $this->fail('rollback did not stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        foreach (self::COLUMNS as $c) {
            $this->assertTrue(Schema::hasColumn('transactions', $c), $c);
        }
        $this->assertTrue(DB::table('permissions')->where('name', 'transfer.acknowledge')->exists());
    }
}
