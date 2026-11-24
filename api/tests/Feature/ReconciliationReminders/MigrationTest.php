<?php

namespace Tests\Feature\ReconciliationReminders;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AG-033f section 5 "New table": the table, its unique key and triggers exist; a rollback refuses while a reminder
 * exists and leaves the table unchanged; on an empty table rollback and migrate work, and up() can be re-run.
 * No RefreshDatabase: the test changes the schema and MySQL commits DDL. It starts from migrate:fresh and, on MySQL,
 * leaves an empty migrated database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_08_000000_bank_reconciliation_reminders.php';

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

    private function triggers(): array
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? array_column(DB::select('SHOW TRIGGERS WHERE `Table` = ?', ['bank_reconciliation_reminders']), 'Trigger')
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'bank_reconciliation_reminders')->pluck('name')->all();
    }

    private function assertInstalled(): void
    {
        $this->assertTrue(Schema::hasTable('bank_reconciliation_reminders'));
        $this->assertTrue(Schema::hasIndex('bank_reconciliation_reminders', 'bank_reconciliation_reminders_key_unique'));
        $this->assertEqualsCanonicalizing(['bank_reconciliation_reminders_no_update', 'bank_reconciliation_reminders_no_delete'], $this->triggers());
    }

    public function test_rollback_refuses_with_rows_and_works_on_an_empty_table(): void
    {
        $this->assertInstalled();
        $acc = DB::table('accounts')->where('is_main_account', true)->first();
        DB::table('bank_reconciliation_reminders')->insert(['branch_id' => $acc->branch_id, 'account_id' => $acc->id, 'overdue_through' => '2026-11-30',
            'round' => 1, 'months_overdue' => 4, 'last_statement_date' => null, 'recipient_ids' => '[1]', 'super_admin_fallback' => false, 'created_at' => now()]);
        $before = DB::table('bank_reconciliation_reminders')->get()->map(fn ($r) => (array) $r)->all();
        try {
            (require base_path(self::FILE))->down();
            $this->fail('rollback did not stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        $this->assertInstalled();
        $this->assertSame($before, DB::table('bank_reconciliation_reminders')->get()->map(fn ($r) => (array) $r)->all());

        // Empty the table for the clean-up only (the delete trigger refuses it otherwise).
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            $this->seed(DatabaseSeeder::class);
        } else {
            DB::unprepared('DROP TRIGGER bank_reconciliation_reminders_no_delete');
            DB::table('bank_reconciliation_reminders')->delete();
        }
        $this->artisan('migrate:rollback', ['--path' => self::FILE, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('bank_reconciliation_reminders'));
        $this->assertSame([], $this->triggers());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertInstalled();

        // Resumable: up() again on an installed table changes nothing and keeps both triggers.
        (require base_path(self::FILE))->up();
        $this->assertInstalled();
    }
}
