<?php

namespace Tests\Feature\DoubleEntry;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Services\PostingRules;
use App\Support\Money;

/** Double-entry spec ACs 1-12, 16, 17, 19, 52: posting rules, balance enforcement and period locks per branch. */
class PostingTest extends DoubleEntryTestCase
{
    private function retail()
    {
        $this->sa()->postJson('/api/v1/categories', ['name' => 'Retail', 'parent_id' => $this->cat('income', 'Sales')->id])->assertCreated();

        return $this->cat('income', 'Retail');
    }

    public function test_ac1_income_posts_cash_and_category_lines(): void
    {
        $retail = $this->retail();
        $before = $this->bal($this->kdyCash);
        $t = $this->accKdy()->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id, 'category_id' => $retail->id,
            'txn_date' => '2026-11-05', 'amount' => '50000.00'])->assertCreated()->json();

        $this->assertSame(['KDY Dr Cash 50000.00', 'KDY Cr Sales > Retail 50000.00'], $this->sides($t['id']));
        foreach ($this->lines($t['id']) as $l) {
            $this->assertSame('posting', $l->origin);
            $this->assertSame('2026-11-05', $l->entry_date->format('Y-m-d'));
        }
        $this->assertSame(Money::add($before, '50000.00'), $this->bal($this->kdyCash));
        $this->assertSame('-50000.00', $this->bal($this->catAcc($retail, $this->kdy)), 'credit-normal account holds a negative signed balance');
    }

    public function test_ac2_ac3_expense_and_parent_category_lines(): void
    {
        $e = $this->accKdy()->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $this->cat('expense', 'Electricity')->id,
            'txn_date' => '2026-11-05', 'amount' => '8200.00'])->assertCreated()->json();
        $this->assertSame(['KDY Cr Cash 8200.00', 'KDY Dr Utilities > Electricity 8200.00'], $this->sides($e['id']));

        $service = $this->cat('income', 'Service income');
        $i = $this->accKdy()->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id, 'category_id' => $service->id,
            'txn_date' => '2026-11-05', 'amount' => '12500.00'])->assertCreated()->json();
        $credit = $this->lines($i['id'])->firstWhere('credit', '12500.00');
        $this->assertSame('Service income', $credit->account->name);
        $this->assertSame($service->ledger_code, $credit->account->code);
        $this->assertStringEndsWith('00', $credit->account->code, "the parent node's own account (4PP00)");
    }

    public function test_ac4_capital_into_main(): void
    {
        $t = Transaction::withoutGlobalScopes()->where('type', 'capital')->sole();
        $this->assertSame(['MAIN Dr Main Account 1000000.00', "MAIN Cr Owner's capital 1000000.00"], $this->sides($t->id));
        $this->assertSame(1, Account::withoutGlobalScopes()->where('type', 'owner_capital')->count());
        $this->assertSame($this->main->id, $this->ownerCapital()->branch_id);
        $this->assertSame('31000', $this->ownerCapital()->code);
    }

    public function test_ac5_capital_into_a_non_main_branch_posts_four_lines_and_needs_main_open(): void
    {
        $a = $this->sa()->postJson('/api/v1/accounts', ['branch_id' => $this->kdy->id, 'name' => 'KDY Main', 'type' => 'bank', 'is_main_account' => true])->assertCreated()->json();
        $t = $this->sa()->postJson('/api/v1/capital', ['account_id' => $a['id'], 'amount' => '50000.00', 'txn_date' => '2026-11-05'])->assertCreated()->json();
        $this->assertSame(['MAIN Dr Branch current account – KDY 50000.00', "MAIN Cr Owner's capital 50000.00",
            'KDY Dr KDY Main 50000.00', 'KDY Cr Head office current account 50000.00'], $this->sides($t['id']));
        $this->assertBalancedPerBranch($t['id']);
        $this->assertSame(0, Account::withoutGlobalScopes()->where('type', 'owner_capital')->where('branch_id', '!=', $this->main->id)->count());

        $this->lock('2026-11', $this->main)->assertCreated();
        $before = $this->snapshot();
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $a['id'], 'amount' => '1.00', 'txn_date' => '2026-11-06'])
            ->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked.');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ac6_top_up_and_opening_allocation_post_four_lines(): void
    {
        $t = $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '100000.00', 'txn_date' => '2026-11-05', 'kind' => 'top_up'])->assertCreated()->json();
        $this->assertSame(['MAIN Cr Main Account 100000.00', 'MAIN Dr Branch current account – KDY 100000.00',
            'KDY Dr Cash 100000.00', 'KDY Cr Head office current account 100000.00'], $this->sides($t['id']));
        $this->assertBalancedPerBranch($t['id']);

        $opening = Transaction::withoutGlobalScopes()->where('type', 'opening_allocation')->where('to_account_id', $this->kdyCash->id)->sole();
        $this->assertSame(['MAIN Cr Main Account 100000.00', 'MAIN Dr Branch current account – KDY 100000.00',
            'KDY Dr Cash 100000.00', 'KDY Cr Head office current account 100000.00'], $this->sides($opening->id));
    }

    public function test_ac7_fund_return(): void
    {
        $t = $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id, 'to_account_id' => $this->mainAcc->id,
            'amount' => '20000.00', 'txn_date' => '2026-11-05', 'note' => 'Return'])->assertCreated()->json();
        $this->assertSame('fund_return', $t['type']);
        $this->assertSame(['GAL Cr Cash 20000.00', 'GAL Dr Head office current account 20000.00',
            'MAIN Dr Main Account 20000.00', 'MAIN Cr Branch current account – GAL 20000.00'], $this->sides($t['id']));
        $this->assertBalancedPerBranch($t['id']);
    }

    private function branchTransfer(string $date = '2026-11-05'): array
    {
        return $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '30000.00', 'txn_date' => $date, 'note' => 'Support'])->assertCreated()->json();
    }

    public function test_ac8_branch_transfer_goes_through_head_office_six_lines(): void
    {
        $t = $this->branchTransfer();
        $this->assertSame('branch_transfer', $t['type']);
        $this->assertSame(['KDY Cr Cash 30000.00', 'KDY Dr Head office current account 30000.00',
            'MAIN Dr Branch current account – GAL 30000.00', 'MAIN Cr Branch current account – KDY 30000.00',
            'GAL Dr Cash 30000.00', 'GAL Cr Head office current account 30000.00'], $this->sides($t['id']));
        $this->assertBalancedPerBranch($t['id']);
        $this->assertSame(['2026-11-05'], $this->lines($t['id'])->map(fn ($l) => $l->entry_date->format('Y-m-d'))->unique()->values()->all());
        $this->assertSame('90000.00', Money::fromCents((int) $this->lines($t['id'])->sum(fn ($l) => Money::toCents($l->debit))));
    }

    public function test_ac9_internal_transfer_two_lines(): void
    {
        $bank = $this->sa()->postJson('/api/v1/accounts', ['branch_id' => $this->kdy->id, 'name' => 'Bank', 'type' => 'bank'])->assertCreated()->json();
        $this->assertSame('11002', $bank['code']);
        $t = $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $bank['id'],
            'amount' => '40000.00', 'txn_date' => '2026-11-05', 'note' => 'Banking'])->assertCreated()->json();
        $this->assertSame(['KDY Cr Cash 40000.00', 'KDY Dr Bank 40000.00'], $this->sides($t['id']));
    }

    public function test_ac10_void_posts_a_contra_for_every_line(): void
    {
        $e = $this->expense('2026-11-10', '5000.00');
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $lines = $this->lines($e['id']);
        $this->assertCount(4, $lines);
        foreach ($lines->whereNotNull('contra_of_id') as $c) {
            $o = $lines->firstWhere('id', $c->contra_of_id);
            $this->assertSame([$o->account_id, $o->branch_id, $o->debit, $o->credit, '2026-11-10'],
                [$c->account_id, $c->branch_id, $c->credit, $c->debit, $c->entry_date->format('Y-m-d')]);
        }
        foreach ($lines->groupBy('account_id') as $per) {
            $this->assertSame(0, $per->sum(fn ($l) => Money::toCents($l->debit) - Money::toCents($l->credit)));
        }
    }

    public function test_ac11_branch_transfer_reversal_mirrors_six_lines(): void
    {
        $t = $this->branchTransfer('2026-11-05');
        $r = $this->accKdy()->postJson("/api/v1/transactions/{$t['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-12'])->assertCreated()->json();
        $orig = $this->lines($t['id']);
        $rev = $this->lines($r['id']);
        $this->assertCount(6, $rev);
        foreach ($rev->values() as $i => $l) {
            $o = $orig->values()[$i];
            $this->assertSame([$o->account_id, $o->debit, $o->credit], [$l->account_id, $l->credit, $l->debit]);
            $this->assertSame('2026-11-12', $l->entry_date->format('Y-m-d'));
        }
        $this->assertBalancedPerBranch($r['id']);
        $this->assertSame(['GAL', 'KDY', 'MAIN'], $rev->pluck('branch.code')->unique()->sort()->values()->all());
    }

    public function test_ac12_branch_transfer_and_its_reversal_need_main_open(): void
    {
        $old = $this->branchTransfer('2026-10-20');
        $this->lock('2026-11', $this->main)->assertCreated();
        $before = $this->snapshot();
        $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '30000.00', 'txn_date' => '2026-11-05', 'note' => 'Support'])
            ->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked for MAIN (head office side of this transfer).');
        $this->accKdy()->postJson("/api/v1/transactions/{$old['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-12'])
            ->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked for MAIN (head office side of this transfer).');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ac16_chart_accounts_cannot_be_picked(): void
    {
        $msg = 'Choose a cash, bank or wallet account.';
        $catAcc = $this->catAcc($this->category('income'), $this->kdy);
        $before = $this->snapshot();
        $this->accKdy()->postJson('/api/v1/incomes', ['account_id' => $catAcc->id, 'category_id' => $this->category('income')->id,
            'txn_date' => '2026-11-05', 'amount' => '10.00'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson('/api/v1/expenses', ['account_id' => $this->ho($this->kdy)->id, 'category_id' => $this->category('expense')->id,
            'txn_date' => '2026-11-05', 'amount' => '10.00'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->ownerCapital()->id, 'amount' => '10.00', 'txn_date' => '2026-11-05'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->bca($this->kdy)->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '10.00', 'txn_date' => '2026-11-05', 'kind' => 'top_up'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->ho($this->gal)->id,
            'amount' => '10.00', 'txn_date' => '2026-11-05', 'note' => 'x'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->ho($this->kdy)->id, 'to_account_id' => $this->galCash->id,
            'amount' => '10.00', 'txn_date' => '2026-11-05', 'note' => 'x'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ac17_an_unbalanced_set_is_refused_and_rolled_back(): void
    {
        $this->app->instance(PostingRules::class, new class extends PostingRules
        {
            public function incomeExpense(string $type, int $accountId, int $branchId, int $categoryId, int $cents): array
            {
                $lines = parent::incomeExpense($type, $accountId, $branchId, $categoryId, $cents);
                $lines[1]['credit'] -= 1; // one cent short

                return $lines;
            }
        });
        $before = $this->snapshot();
        $this->accKdy()->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id, 'category_id' => $this->category('income')->id,
            'txn_date' => '2026-11-05', 'amount' => '100.00'])->assertStatus(422)->assertJsonPath('message', 'Posting is not balanced.');
        $this->assertSame($before, $this->snapshot());

        // A set that balances in total but not inside each branch is refused too.
        $this->app->instance(PostingRules::class, new class extends PostingRules
        {
            public function transfer(int $fromId, int $fromBranch, int $toId, int $toBranch, int $mainId, int $cents): array
            {
                return [self::line([self::MONEY, $fromId], $fromBranch, 0, $cents), self::line([self::MONEY, $toId], $toBranch, $cents, 0)];
            }
        });
        $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '100.00', 'txn_date' => '2026-11-05', 'note' => 'x'])->assertStatus(422)->assertJsonPath('message', 'Posting is not balanced.');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ac19_postings_to_the_same_category_keep_its_cached_balance(): void
    {
        $this->income('2026-11-05', '100.00');
        $this->income('2026-11-05', '250.50', $this->csh);
        $acc = $this->catAcc($this->category('income'), $this->kdy);
        $this->assertSame('-350.50', $this->bal($acc));
        $this->assertSame(0, Money::cmp($acc->ledgerBalance(), '-350.50'));
        $this->assertSame(2, LedgerEntry::where('account_id', $acc->id)->count());
    }

    public function test_ac52_top_up_into_a_locked_receiving_branch_is_refused(): void
    {
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $before = $this->snapshot();
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '100.00', 'txn_date' => '2026-10-15', 'kind' => 'top_up'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        $this->assertSame($before, $this->snapshot());
    }
}
