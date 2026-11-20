<?php

namespace Tests\Feature\Transactions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * docs/specs/transaction-search.md AC 38 (R13). No RefreshDatabase: the test changes the schema and MySQL commits
 * DDL. It starts from migrate:fresh and, on MySQL, leaves an empty migrated database for the tests that follow.
 */
class IndexMigrationTest extends TestCase
{
    private const DATE_ID = 'transactions_txn_date_id_index';

    private const RELATED = 'transactions_related_branch_txn_date_index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_24_000000_transaction_search_indexes.php');
    }

    /** @return string[] names of the indexes on transactions */
    private function indexes(): array
    {
        return array_column(Schema::getIndexes('transactions'), 'name');
    }

    public function test_ac38_indexes_resumable_and_rollback_drops_only_these(): void
    {
        $this->assertContains(self::DATE_ID, $this->indexes());
        $this->assertContains(self::RELATED, $this->indexes());
        $before = $this->indexes();

        // Running again (or after a partial run) does not fail.
        $this->migration()->up();
        $this->assertEqualsCanonicalizing($before, $this->indexes());
        Schema::table('transactions', fn ($t) => $t->dropIndex(self::DATE_ID));
        $this->migration()->up();
        $this->assertEqualsCanonicalizing($before, $this->indexes());

        // Only this migration (later migrations, e.g. AG-093 backups, are not rolled back).
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_24_000000_transaction_search_indexes.php', '--force' => true])->assertExitCode(0);
        $after = $this->indexes();
        $this->assertNotContains(self::DATE_ID, $after);
        $this->assertNotContains(self::RELATED, $after);
        // Only these two: every other index is still there (MySQL may add the related_branch_id foreign key's own index).
        $this->assertSame([], array_values(array_diff($before, $after, [self::DATE_ID, self::RELATED])));
        // Every foreign key column still has an index whose first column it is (MySQL error 1553 otherwise).
        foreach (['branch_id', 'account_id', 'from_account_id', 'to_account_id', 'related_branch_id', 'category_id', 'created_by', 'reversal_of_id'] as $col) {
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->assertTrue(collect(Schema::getIndexes('transactions'))->contains(fn ($i) => $i['columns'][0] === $col), $col);
            }
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertContains(self::DATE_ID, $this->indexes());
        $this->assertContains(self::RELATED, $this->indexes());
    }
}
