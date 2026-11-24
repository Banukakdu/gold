<?php

namespace Tests\Feature\ReconciliationStatus;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ReconciliationChecks\ChecksTestCase;

/**
 * docs/specs/reconciliation-overdue.md section 7 setup (AG-033e), on the AG-033c / AG-033d setup (ChecksTestCase):
 *  - KDY Bank: ST1 (2026-10-31, completed by ACC, checked by VW on 2026-12-10), ST3 (2026-11-30, completed by BA, not checked);
 *  - GAL Bank: ST4 (2026-10-31, completed by GACC, not checked);
 *  - MAIN Main Account: first line a capital line dated 2026-08-01, no statement;
 *  - KDY Bank 2: first line 2026-11-05, no statement; KDY Bank 3: active, no lines;
 *  - KDY Bank 4: lines in June 2026, nil since 2026-06-30, statement 2026-06-30 completed by ACC and checked by VW, closed;
 *  - KDY Bank 5: active, lines; last completed statement 2026-09-15 by ACC, not checked.
 * BusinessDate is frozen at 2026-12-15 (L = E = 2026-11-30).
 */
abstract class StatusTestCase extends ChecksTestCase
{
    protected Account $bank2;

    protected Account $bank3;

    protected Account $bank4;

    protected Account $bank5;

    protected BankStatement $st6;

    protected BankStatement $st7;

    protected const URL = '/api/v1/reports/reconciliation-status';

    protected function setUp(): void
    {
        parent::setUp();
        // ST1 checked by VW on 2026-12-10 (it has one late entry, so the check carries a note).
        $this->check($this->st1, $this->vw, ['note' => 'The late cheque 001300 was issued after the statement.'])->assertCreated();
        // MAIN Main Account: a capital line dated 2026-08-01 is its first line.
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '1000.00', 'txn_date' => '2026-08-01'])->assertCreated();

        $this->bank2 = $this->bankIn($this->kdy, 'KDY Bank 2');
        $this->bank3 = $this->bankIn($this->kdy, 'KDY Bank 3');
        $this->bank4 = $this->bankIn($this->kdy, 'KDY Bank 4');
        $this->bank5 = $this->bankIn($this->kdy, 'KDY Bank 5');
        $this->allocate($this->bank2, '5000.00', '2026-11-05');

        // KDY Bank 4: received and paid out in June, reconciled to 30 Jun 2026 (nil), checked by VW, then closed.
        $in4 = $this->income($this->acc, '5000.00', '2026-06-05', $this->bank4);
        $out4 = $this->expense($this->acc, '5000.00', '2026-06-20', '004001', $this->bank4);
        $this->tick([[$in4, '2026-06-06'], [$out4, '2026-06-22']], $this->acc, [], $this->bank4)->assertOk();
        $this->st6 = $this->statement($this->complete('2026-06-30', '0.00', $this->acc, $this->bank4));
        $this->check($this->st6, $this->vw)->assertCreated();
        $this->sa($this->kdy)->putJson("/api/v1/accounts/{$this->bank4->id}", ['status' => 'closed'])->assertOk();

        // KDY Bank 5: reconciled mid-month to 15 Sep 2026 by ACC, not checked.
        $in5 = $this->income($this->acc, '8000.00', '2026-09-10', $this->bank5);
        $this->tick([[$in5, '2026-09-12']], $this->acc, [], $this->bank5)->assertOk();
        $this->st7 = $this->statement($this->complete('2026-09-15', '8000.00', $this->acc, $this->bank5));

        Carbon::setTestNow('2026-12-15 10:00:00');
    }

    protected function statusReport(array $q = [], ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->getJson(self::URL.'?'.http_build_query($q));
    }

    protected function exportStatus(array $q, string $format, ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->postJson(self::URL.'/export', $q + ['format' => $format]);
    }

    /** The row of an account, or null. */
    protected function rowOf(TestResponse $r, Account $a): ?array
    {
        return collect($r->assertOk()->json('data'))->firstWhere('account.id', $a->id);
    }

    /** @return int[] account ids in report order */
    protected function accountIds(TestResponse $r): array
    {
        return array_column(array_column($r->assertOk()->json('data'), 'account'), 'id');
    }

    /** [status, months_overdue] per account id, in report order. */
    protected function brief(TestResponse $r): array
    {
        return array_map(fn ($row) => [$row['account']['id'], $row['status'], $row['months_overdue']], $r->assertOk()->json('data'));
    }

    /** Account ids named in S6's default coverage sentence (To = today). */
    protected function coverageIds(User $by, Branch|string $branch, array $q = []): array
    {
        $r = $this->as($by, $branch)->getJson('/api/v1/reports/reconciliation-statements?'.http_build_query($q))->assertOk();

        return array_column(array_column($r->json('coverage.not_reconciled'), 'account'), 'id');
    }

    protected function transferOut(Account $from, Account $to, string $amount, string $date): Transaction
    {
        $id = $this->sa(Branch::find($from->branch_id))->postJson('/api/v1/transfers', ['from_account_id' => $from->id, 'to_account_id' => $to->id,
            'amount' => $amount, 'txn_date' => $date, 'note' => 'Closing the account'])->assertCreated()->json('id');

        return Transaction::withoutGlobalScopes()->findOrFail($id);
    }
}
