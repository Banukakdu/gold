<?php

namespace Tests\Feature\DoubleEntry;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Support\Collection;
use Tests\Feature\Controls\ControlsTestCase;

/**
 * docs/specs/double-entry.md. Setup of the controls tests (MAIN, KDY, GAL; capital 1,000,000.00 on 2026-09-01;
 * opening allocations KDY 100,000.00 and GAL 50,000.00 on 2026-10-01; Accountant / Viewer / Cashier KDY, Branch Admin GAL;
 * "today" 2026-11-18), plus helpers to read ledger lines by account.
 */
abstract class DoubleEntryTestCase extends ControlsTestCase
{
    /** @return Collection<int, LedgerEntry> lines of a transaction by id, with account and branch */
    protected function lines(int $txnId): Collection
    {
        return LedgerEntry::with(['account', 'branch'])->where('transaction_id', $txnId)->orderBy('id')->get();
    }

    /** Compact view of lines: "BRANCH Dr|Cr <account name> <amount>". */
    protected function sides(int $txnId): array
    {
        return $this->lines($txnId)->map(fn (LedgerEntry $l) => $l->branch->code.' '.(Money::cmp($l->debit, 0) > 0 ? 'Dr' : 'Cr').' '.$l->account->name.' '
            .(Money::cmp($l->debit, 0) > 0 ? $l->debit : $l->credit))->all();
    }

    protected function assertBalancedPerBranch(int $txnId): void
    {
        $net = [];
        foreach ($this->lines($txnId) as $l) {
            $net[$l->branch_id] = ($net[$l->branch_id] ?? 0) + Money::toCents($l->debit) - Money::toCents($l->credit);
        }
        $this->assertNotEmpty($net);
        $this->assertSame([], array_filter($net), 'debits = credits in every branch');
    }

    protected function ho(Branch $b): Account
    {
        return Account::withoutGlobalScopes()->where('branch_id', $b->id)->where('type', 'head_office')->sole();
    }

    protected function bca(Branch $b): Account
    {
        return Account::withoutGlobalScopes()->where('branch_id', $this->main->id)->where('type', 'branch_current')->where('counterparty_branch_id', $b->id)->sole();
    }

    protected function ownerCapital(): Account
    {
        return Account::withoutGlobalScopes()->where('type', 'owner_capital')->sole();
    }

    protected function catAcc(Category $c, Branch $b): Account
    {
        return Account::withoutGlobalScopes()->where('branch_id', $b->id)->where('category_id', $c->id)->sole();
    }

    protected function cat(string $type, string $name): Category
    {
        return Category::where('type', $type)->where('name', $name)->firstOrFail();
    }

    protected function bal(Account $a): string
    {
        return (string) Account::withoutGlobalScopes()->find($a->id)->current_balance;
    }
}
