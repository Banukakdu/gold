<?php

namespace Tests\Feature\Transactions;

use App\Models\Transaction;
use App\Services\LedgerService;

/** docs/specs/transaction-search.md ACs 18-20 (R11, R12). */
class SortPaginationTest extends TransactionSearchTestCase
{
    public function test_ac18_pages_are_stable_and_complete(): void
    {
        $ledger = app(LedgerService::class);
        // 52 more KDY entries on 4 dates (13 per date) -> 60 KDY rows in scope.
        $this->as($this->kamal, function () use ($ledger) {
            for ($i = 0; $i < 52; $i++) {
                $ledger->incomeOrExpense(Transaction::INCOME, ['account_id' => $this->kdyCash->id, 'category_id' => $this->walkIn->id,
                    'txn_date' => '2026-11-0'.(1 + $i % 4), 'amount' => '10.00']);
            }
        });
        $seen = [];
        $total = null;
        foreach ([1, 2, 3] as $page) {
            $r = $this->kdyList(['page' => $page, 'per_page' => 25]);
            $total = $r->json('total');
            $seen = array_merge($seen, $this->rowIds($r));
        }
        $this->assertSame(60, $total);
        $this->assertCount(60, $seen);
        $this->assertCount(60, array_unique($seen));
        $this->assertSame($this->rowIds($this->kdyList(['page' => 2])), $this->rowIds($this->kdyList(['page' => 2])));

        // Same date: id breaks the tie in the requested direction.
        $asc = $this->rowIds($this->kdyList(['dir' => 'asc', 'per_page' => 100]));
        $this->assertSame(array_reverse($seen), $asc);
    }

    public function test_ac19_sort_by_amount_keeps_closing_entries_last(): void
    {
        $desc = $this->rowIds($this->kdyList(['sort' => 'amount', 'dir' => 'desc']));
        $this->assertSame($this->e['E5']->id, $desc[0]);
        $this->assertSame($this->e['E9']->id, end($desc));
        // E6 and E7 share 3,000.00: id desc.
        $this->assertSame($this->ids('E1', 'E2', 'E7', 'E6', 'E4', 'E3'), array_slice($desc, 1, 6));

        $asc = $this->rowIds($this->kdyList(['sort' => 'amount', 'dir' => 'asc']));
        $this->assertSame($this->e['E3']->id, $asc[0]);
        $this->assertSame($this->e['E9']->id, end($asc));

        $this->kdyList(['sort' => 'name'])->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->kdyList(['dir' => 'up'])->assertStatus(422)->assertJsonValidationErrors('dir');
    }

    public function test_ac20_rows_per_page(): void
    {
        $this->kdyList(['per_page' => 30])->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->kdyList(['per_page' => 200])->assertOk()->assertJsonPath('per_page', 200);
        $this->kdyList(['per_page' => 50])->assertOk()->assertJsonPath('per_page', 50);
        $this->kdyList()->assertOk()->assertJsonPath('per_page', 25);
    }
}
