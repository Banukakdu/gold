<?php

namespace Tests\Feature\YearEnd;

use App\Models\Category;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\YearEndClose;
use App\Services\YearEnd;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Settings fix round: review S1, S2 and QA Q4. */
class ReviewFixesTest extends YearEndTestCase
{
    /** Every branch row in id order (`... for update` on MySQL; SQLite drops the clause). */
    private const BRANCHES = '/^select [`"]id[`"] from [`"]branches[`"] order by [`"]id[`"] asc/';

    /** The queries run by $fn, lower-cased. */
    private function queries(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        DB::disableQueryLog();

        return array_map(fn ($q) => strtolower($q['query']), DB::getQueryLog());
    }

    private function locksAllBranches(array $queries): bool
    {
        return (bool) array_filter($queries, fn ($q) => preg_match(self::BRANCHES, $q));
    }

    /**
     * S1: lock, unlock, close and reopen all start by taking every branch row FOR UPDATE in id order (the shared statement is
     * checked; MySQL adds FOR UPDATE), so they run one at a time on MySQL.
     */
    public function test_s1_lock_unlock_close_and_reopen_take_the_branch_row_locks(): void
    {
        $this->assertTrue($this->locksAllBranches($this->queries(fn () => $this->lock('2027-02', $this->kdy))));
        $lock = PeriodLock::active()->where('branch_id', $this->kdy->id)->sole();
        $this->assertTrue($this->locksAllBranches($this->queries(fn () => $this->sa()->postJson("/api/v1/period-locks/{$lock->id}/unlock",
            ['reason' => 'Correct a February entry'])->assertOk())));
        $this->lock('2027-03');
        $this->assertTrue($this->locksAllBranches($q = $this->queries(fn () => $this->close()->assertCreated())));
        $first = fn (string $pattern) => array_key_first(array_filter($q, fn ($x) => preg_match($pattern, $x)));
        // Right after the branch rows, the active period locks are read (FOR UPDATE on MySQL), before the plan.
        $branches = $first(self::BRANCHES);
        $this->assertSame($branches + 1, $first('/from [`"]period_locks[`"] where [`"]unlocked_at[`"] is null order by [`"]id[`"] asc/'));
        $this->assertTrue($this->locksAllBranches($this->queries(fn () => $this->reopen()->assertOk())));
    }

    /** S2: only the active-close unique index means "already closed"; any other integrity error is not. */
    public function test_s2_only_the_active_key_violation_is_already_closed(): void
    {
        $this->closeY();
        $close = YearEndClose::sole();
        try {
            DB::table('year_end_closes')->insert(collect($close->getAttributes())->except(['id', 'active_key'])->all());
            $this->fail('a second active close was stored');
        } catch (QueryException $e) {
            $this->assertTrue(YearEnd::isActiveKeyViolation($e));
        }
        $other = Transaction::withoutGlobalScopes()->first();
        try {
            DB::table('transactions')->insert(['ref_no' => $other->ref_no] + collect($other->getAttributes())->except(['id', 'ref_no'])->all());
            $this->fail('a duplicate ref_no was stored');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
            $this->assertFalse(YearEnd::isActiveKeyViolation($e));
        }
        try {
            DB::table('ledger_entries')->insert(['transaction_id' => 999999, 'account_id' => 1, 'branch_id' => 1, 'entry_date' => '2027-04-01',
                'debit' => '1.00', 'credit' => '0', 'origin' => 'posting']);
        } catch (QueryException $e) {
            $this->assertFalse(YearEnd::isActiveKeyViolation($e));
        }
    }

    /** Q4: changing an entry of a closed year says the year is closed, not only that the month is locked. */
    public function test_q4_reclassify_in_a_closed_year_names_the_year(): void
    {
        $this->closeY();
        $rent = Transaction::withoutGlobalScopes()->where('branch_id', $this->kdy->id)->where('type', 'expense')->whereDate('txn_date', '<=', '2027-03-31')->first();
        $this->sa($this->kdy)->putJson("/api/v1/transactions/{$rent->id}", ['category_id' => Category::where('name', 'Utilities > Electricity')->value('id')])
            ->assertStatus(422)->assertJsonPath('message', 'FY 2026-27 is closed.');
        $this->sa($this->kdy)->putJson("/api/v1/transactions/{$rent->id}", ['description' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'FY 2026-27 is closed.');
    }
}
