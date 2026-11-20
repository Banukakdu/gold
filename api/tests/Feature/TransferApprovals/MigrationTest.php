<?php

namespace Tests\Feature\TransferApprovals;

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
    private const FILE = 'database/migrations/2026_11_28_000000_transfer_approvals.php';

    private const COLUMNS = ['approval_status', 'requested_date', 'requested_on', 'approval_threshold', 'approval_note', 'decided_by', 'decided_at'];

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

    public function test_ac26_fresh_seed_gives_the_key_to_branch_admin_only(): void
    {
        $this->assertTrue(DB::table('permissions')->where('name', 'transfer.approve')->exists());
        $t = $this->templates();
        $this->assertContains('transfer.approve', $t['Branch Admin']);
        foreach (['Accountant', 'Cashier', 'Viewer'] as $name) {
            $this->assertNotContains('transfer.approve', $t[$name], $name);
        }
        $this->assertTrue(DB::table('settings')->where('key', 'transfer_approval_threshold')->exists());
        $this->assertNull(DB::table('settings')->where('key', 'transfer_approval_threshold')->value('value'));
        // CI rolls back after migrate:fresh --seed; down() refuses while requests exist.
        $this->assertFalse(DB::table('transactions')->whereNotNull('approval_status')->exists());
    }

    public function test_ac26_existing_install_gets_the_key_and_rollback_is_clean(): void
    {
        $indexes = array_column(Schema::getIndexes('transactions'), 'name');
        $this->assertContains('transactions_decided_by_fk', $indexes);
        $this->assertContains('transactions_approval_status_branch_index', $indexes);
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertCount(1, array_filter(Schema::getForeignKeys('transactions'), fn ($fk) => $fk['columns'] === ['decided_by']));
        }

        // The database as before this release.
        $this->rollback();
        foreach (self::COLUMNS as $c) {
            $this->assertFalse(Schema::hasColumn('transactions', $c), $c);
        }
        $this->assertTrue(Schema::hasColumn('transactions', 'approved_by'));
        $this->assertFalse(DB::table('permissions')->where('name', 'transfer.approve')->exists());
        $this->assertFalse(DB::table('settings')->where('key', 'transfer_approval_threshold')->exists());
        $left = array_column(Schema::getIndexes('transactions'), 'name');
        $this->assertNotContains('transactions_decided_by_fk', $left);
        $this->assertNotContains('transactions_approval_status_branch_index', $left);
        $this->assertSame([], array_filter(Schema::getForeignKeys('transactions'), fn ($fk) => $fk['columns'] === ['decided_by']));
        $indexesBefore = $left;

        // Upgrade: the migration adds the key to the "*" templates (Branch Admin) only, as a re-seed would.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::COLUMNS as $c) {
            $this->assertTrue(Schema::hasColumn('transactions', $c), $c);
        }
        $t = $this->templates();
        $this->assertContains('transfer.approve', $t['Branch Admin']);
        $this->assertNotContains('transfer.approve', $t['Accountant']);
        $this->assertSame(1, DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'transfer.approve')->pluck('id'))->count());

        // Running again (a partial run, or the seeder afterwards) adds nothing twice and changes no template.
        (require base_path(self::FILE))->up();
        $this->seed(PermissionSeeder::class);
        $this->assertSame($t, $this->templates());
        $this->assertSame(1, DB::table('permissions')->where('name', 'transfer.approve')->count());

        // A second rollback leaves the same indexes as the first (no index left behind).
        $this->rollback();
        $this->assertEqualsCanonicalizing($indexesBefore, array_column(Schema::getIndexes('transactions'), 'name'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_rollback_stops_while_requests_exist(): void
    {
        $admin = User::where('is_super_admin', true)->firstOrFail();
        Auth::setUser($admin);
        $acc = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();
        $t = app(LedgerService::class)->capital($acc->id, '100.00', BusinessDate::today());
        DB::table('transactions')->where('id', $t->id)->update(['approval_status' => 'approved']);

        try {
            (require base_path(self::FILE))->down();
            $this->fail('rollback did not stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        foreach (self::COLUMNS as $c) {
            $this->assertTrue(Schema::hasColumn('transactions', $c), $c);
        }
        $this->assertTrue(DB::table('permissions')->where('name', 'transfer.approve')->exists());
    }
}
