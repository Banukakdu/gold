<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Models\Account;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AC 17 and section 5 "Schema": the migration on a database with AG-062 entry attachments keeps their rows and their
 * triggers' behaviour, rollback + migrate is clean while only entry files exist, a rollback is refused once a statement
 * file exists, and a partial run is resumed. No RefreshDatabase: the test changes the schema and MySQL commits DDL. It
 * starts from migrate:fresh and, on MySQL, leaves an empty migrated database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_06_000000_bank_statement_attachments.php';

    private User $admin;

    private int $txnId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('is_super_admin', true)->firstOrFail();
        $main = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();
        $this->txnId = $this->actingAs($this->admin)->withHeader('X-Branch-Id', 'all')
            ->postJson('/api/v1/capital', ['account_id' => $main->id, 'amount' => '1000.00', 'txn_date' => '2026-10-01'])->assertCreated()->json('id');
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

    private function entryRow(string $path): array
    {
        return ['branch_id' => Branch::main()->id, 'transaction_id' => $this->txnId, 'disk' => 'attachments', 'path' => $path,
            'original_name' => 'bill.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64),
            'uploaded_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()];
    }

    private function statementId(): int
    {
        $acc = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();

        return DB::table('bank_statements')->insertGetId(['branch_id' => Branch::main()->id, 'account_id' => $acc->id, 'statement_date' => '2026-10-31',
            'statement_balance_cents' => 0, 'reconciled_cents' => 0, 'book_balance_cents' => 0, 'uncleared_receipts_cents' => 0, 'uncleared_payments_cents' => 0,
            'cleared_booked_later_cents' => 0, 'self_count' => 0, 'lines_count' => 0, 'status' => 'completed', 'completed_by' => $this->admin->id,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assertQueryFails(callable $call, string $label): void
    {
        try {
            $call();
            $this->fail("'{$label}' did not throw");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** The AG-062 trigger behaviour on an entry row: no delete, no change, the one-time removal only. */
    private function assertEntryTriggers(int $id): void
    {
        $this->assertQueryFails(fn () => DB::table('attachments')->where('id', $id)->delete(), 'delete');
        $this->assertQueryFails(fn () => DB::table('attachments')->where('id', $id)->update(['sha256' => str_repeat('0', 64)]), 'sha256');
        $this->assertQueryFails(fn () => DB::table('attachments')->where('id', $id)->update(['removal_reason' => 'x']), 'not a removal');
    }

    public function test_ac17_entry_files_survive_and_rollback_is_refused_once_a_statement_file_exists(): void
    {
        // An AG-062 database: this migration rolled back, one entry file and one removed entry file.
        $this->rollback();
        $this->assertFalse(Schema::hasColumn('attachments', 'bank_statement_id'));
        $id = DB::table('attachments')->insertGetId($this->entryRow('1/1/a.pdf'));
        $gone = DB::table('attachments')->insertGetId($this->entryRow('1/1/b.pdf'));
        DB::table('attachments')->where('id', $gone)->update(['removed_at' => now(), 'removed_by' => $this->admin->id, 'removal_reason' => 'Wrong bill']);
        $before = DB::table('attachments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('attachments', 'bank_statement_id'));
        $this->assertTrue(Schema::hasColumn('attachments', 'replaced_by_id'));
        $after = DB::table('attachments')->orderBy('id')->get()->map(fn ($r) => array_diff_key((array) $r, ['bank_statement_id' => 1, 'replaced_by_id' => 1]))->all();
        $this->assertEquals($before, $after, 'existing rows unchanged');
        $this->assertEntryTriggers($id);
        $this->assertQueryFails(fn () => DB::table('attachments')->where('id', $gone)->update(['removal_reason' => 'Rewritten']), 'second update');
        // Exactly one parent.
        $this->assertQueryFails(fn () => DB::table('attachments')->insert(array_merge($this->entryRow('1/1/c.pdf'), ['transaction_id' => null])), 'no parent');
        // The one-time removal still works on an entry row.
        DB::table('attachments')->where('id', $id)->update(['removed_at' => now(), 'removed_by' => $this->admin->id, 'removal_reason' => 'Duplicate']);

        // Rollback + migrate is clean while only entry files exist.
        $this->rollback();
        $this->assertFalse(Schema::hasColumn('attachments', 'bank_statement_id'));
        $this->assertSame(2, DB::table('attachments')->count());
        $this->assertEntryTriggers($gone);
        $this->assertQueryFails(fn () => DB::table('attachments')->insert(array_merge($this->entryRow('1/1/d.pdf'), ['transaction_id' => null])), 'NOT NULL again');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        // A statement file: rollback refused before any change.
        $sid = $this->statementId();
        DB::table('attachments')->insert(array_merge($this->entryRow("1/bank-statements/{$sid}/e.pdf"), ['transaction_id' => null, 'bank_statement_id' => $sid]));
        try {
            (require base_path(self::FILE))->down();
            $this->fail('rollback did not stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('attachments', 'bank_statement_id'));
        $this->assertSame(3, DB::table('attachments')->count());
        $this->assertQueryFails(fn () => DB::table('attachments')->insert($this->entryRow('1/1/f.pdf') + ['bank_statement_id' => $sid]), 'both parents (triggers kept)');
        $this->assertQueryFails(fn () => DB::table('bank_statements')->where('id', $sid)->delete(), 'FK restrict');
    }

    public function test_resumes_a_partial_run(): void
    {
        $m = require base_path(self::FILE);
        $mysql = DB::connection()->getDriverName() === 'mysql';
        // As if the run had stopped after the columns: the triggers, the second foreign key and its index are missing.
        DB::unprepared('DROP TRIGGER IF EXISTS attachments_one_parent');
        DB::unprepared('DROP TRIGGER IF EXISTS attachments_no_update');
        if ($mysql) {
            DB::statement('ALTER TABLE attachments DROP FOREIGN KEY attachments_replaced_by_id_foreign');
        }
        DB::statement($mysql ? 'ALTER TABLE attachments DROP INDEX attachments_replaced_by_id_fk' : 'DROP INDEX attachments_replaced_by_id_fk');
        $m->up();
        $m->up(); // and once more: nothing to do
        $this->assertTrue(Schema::hasIndex('attachments', 'attachments_replaced_by_id_fk'));
        $this->assertTrue(Schema::hasIndex('attachments', 'attachments_bank_statement_id_removed_at_index'));
        if ($mysql) {
            $fks = collect(Schema::getForeignKeys('attachments'))->pluck('columns')->map(fn ($c) => implode(',', $c))->all();
            $this->assertContains('replaced_by_id', $fks);
            $this->assertContains('bank_statement_id', $fks);
        }
        $id = DB::table('attachments')->insertGetId($this->entryRow('1/1/a.pdf'));
        $this->assertEntryTriggers($id);
        $this->assertQueryFails(fn () => DB::table('attachments')->insert(array_merge($this->entryRow('1/1/b.pdf'), ['transaction_id' => null])), 'no parent');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }
}
