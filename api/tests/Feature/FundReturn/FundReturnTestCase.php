<?php

namespace Tests\Feature\FundReturn;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\DoubleEntry\DoubleEntryTestCase;

/**
 * docs/specs/fund-return.md section 7 (AG-042), on the controls setup (MAIN, KDY, GAL; "today" 2026-11-18).
 * GAL Cash is brought to 40,000.00 by a 10,000.00 expense on 2026-11-02. "MAIN Bank" is MAIN's main account.
 * Users: GAL Accountant and GAL Cashier (templates), KDY Accountant ($this->acc), MAIN Accountant (non-super).
 */
abstract class FundReturnTestCase extends DoubleEntryTestCase
{
    protected User $galAcc;

    protected User $galCsh;

    protected User $mainAccUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->expense('2026-11-02', '10000.00', $this->admin, $this->galCash);
        $this->assertSame('40000.00', $this->bal($this->galCash));
        $this->galAcc = $this->userIn($this->gal, 'Accountant');
        $this->galCsh = $this->userIn($this->gal, 'Cashier');
        $this->mainAccUser = $this->userIn($this->main, 'Accountant');
    }

    protected function galAccountant()
    {
        return $this->as($this->galAcc, $this->gal);
    }

    /** POST /transfers GAL Cash -> MAIN Bank (AC 1 by default). */
    protected function fundReturn(string $amount = '20000.00', string $date = '2026-11-18', ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->galAcc, $branch ?? $this->gal)->postJson('/api/v1/transfers', [
            'from_account_id' => $this->galCash->id, 'to_account_id' => $this->mainAcc->id,
            'amount' => $amount, 'txn_date' => $date, 'note' => 'Surplus',
        ]);
    }

    protected function reverse(int $id, ?User $by = null, Branch|string $branch = 'all', ?string $date = null): TestResponse
    {
        return $this->as($by ?? $this->admin, $branch)->postJson("/api/v1/transactions/{$id}/reverse",
            array_filter(['reason' => 'Sent to the wrong account', 'txn_date' => $date]));
    }

    /** Ledger, transactions, balances and audit rows: everything a refused request must leave unchanged. */
    protected function state(): array
    {
        return $this->snapshot() + ['audit' => AuditLog::count()];
    }

    /** A MAIN money account other than the main account. */
    protected function mainCash(): Account
    {
        $a = $this->sa()->postJson('/api/v1/accounts', ['branch_id' => $this->main->id, 'name' => 'Cash', 'type' => 'cash'])->assertCreated()->json();

        return Account::withoutGlobalScopes()->findOrFail($a['id']);
    }

    /** JSON values with their keys sorted (MySQL does not keep JSON key order). */
    protected function sorted(?array $v): ?array
    {
        if ($v !== null) {
            ksort($v);
        }

        return $v;
    }
}
