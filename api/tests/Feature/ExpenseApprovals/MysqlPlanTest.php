<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Services\ExpenseApprovals;
use Illuminate\Support\Facades\DB;

/**
 * Accountant sign-off, release condition 2 (review 3): on MySQL the R2b same-day locking read can use
 * transactions_created_by_category_txn_date_index. Runs only on MySQL (CI api-mysql job); the chosen key is printed.
 * The assertion is on possible_keys only: with test-sized data the optimizer may still pick another key.
 */
class MysqlPlanTest extends ExpenseApprovalTestCase
{
    private const INDEX = 'transactions_created_by_category_txn_date_index';

    public function test_release2_same_day_locking_read_can_use_its_index_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only: EXPLAIN of the R2b same-day locking read (runs in the CI api-mysql job on MySQL 8.4).');
        }
        // Some posted expenses by two users, two categories and several days, plus a request.
        foreach (['2026-11-16', '2026-11-17', '2026-11-18'] as $date) {
            $this->record('1000.00', $date)->assertCreated();
            $this->record('1000.00', $date, cat: $this->water)->assertCreated();
            $this->record('1000.00', $date, $this->acc)->assertCreated();
        }
        $this->e1();
        DB::statement('ANALYZE TABLE transactions');

        $q = app(ExpenseApprovals::class)->sameDayQuery((int) $this->csh->id, (int) $this->kdy->id, (int) $this->electricity->id, '2026-11-18');
        $plan = DB::select('EXPLAIN '.$q->toSql(), $q->getBindings());
        $row = collect($plan)->first(fn ($r) => ($r->table ?? null) === 'transactions');
        $this->assertNotNull($row, 'EXPLAIN has a row for transactions');
        fwrite(STDERR, sprintf("\n[R2b plan] type=%s possible_keys=%s key=%s rows=%s\n", $row->type, $row->possible_keys, $row->key, $row->rows));
        $this->assertContains(self::INDEX, explode(',', (string) $row->possible_keys));
        // The query sums exactly the user's posted same-day expenses in that category.
        $this->assertSame(100000, (int) (clone $q)->value('c'));
    }
}
