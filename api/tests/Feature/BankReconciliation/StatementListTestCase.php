<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\BankStatement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * docs/specs/reconciliation-statements-list.md section 7 setup (AG-033c), on top of the AG-033 section 8 setup:
 *  - ST4: GAL Bank 2026-10-31 completed by GACC;
 *  - ST5: KDY Bank 2025-09-30 completed (older than 12 months);
 *  - ST1: KDY Bank 2026-10-31, 479,500.00, lines 4, self 2, completed by ACC on 2026-11-03;
 *  - LATE: on 2026-11-05 ACC records an expense of 1,000.00 from KDY Bank dated 2026-10-25, never ticked;
 *  - ST2: KDY Bank 2026-11-30 completed by ACC on 2026-12-02, undone by BA on 2026-12-03 ("Wrong closing balance typed");
 *  - ST3: KDY Bank 2026-11-30 completed by BA on 2026-12-03. Today 2026-12-10.
 */
abstract class StatementListTestCase extends BankReconciliationTestCase
{
    protected BankStatement $st1;

    protected BankStatement $st2;

    protected BankStatement $st3;

    protected BankStatement $st4;

    protected BankStatement $st5;

    protected Transaction $late;

    protected function scenario(): void
    {
        $gal = $this->allocate($this->galBank, '100000.00', '2026-10-01');
        $this->tick([[$gal, '2026-10-31']], $this->gacc, [], $this->galBank)->assertOk();
        $this->st4 = $this->statement($this->complete('2026-10-31', '100000.00', $this->gacc, $this->galBank));
        $this->st5 = $this->statement($this->complete('2025-09-30', '0.00'));

        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->st1 = $this->statementOne();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->late = $this->expense($this->acc, '1000.00', '2026-10-25', '001300');

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();
        $this->st2 = $this->statement($this->complete('2026-11-30', '469500.00'));
        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->undo($this->st2->id, 'Wrong closing balance typed', $this->ba)->assertOk();
        Carbon::setTestNow('2026-12-03 10:00:00');
        $this->st3 = $this->statement($this->complete('2026-11-30', '469500.00', $this->ba));

        Carbon::setTestNow('2026-12-10 10:00:00');
    }

    private function statement(TestResponse $r): BankStatement
    {
        return BankStatement::withoutGlobalScopes()->findOrFail($r->assertCreated()->json('id'));
    }

    protected function list(array $q = [], ?User $by = null, $branch = null): TestResponse
    {
        return $this->as($by ?? $this->vw, $branch ?? $this->kdy)->getJson('/api/v1/reports/reconciliation-statements?'.http_build_query($q));
    }

    protected function exportList(array $q, string $format, ?User $by = null, $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->postJson('/api/v1/reports/reconciliation-statements/export', $q + ['format' => $format]);
    }

    /** @return int[] statement ids in list order */
    protected function ids(TestResponse $r): array
    {
        return array_column($r->assertOk()->json('data'), 'id');
    }

    protected function row(TestResponse $r, BankStatement $s): array
    {
        return collect($r->assertOk()->json('data'))->firstWhere('id', $s->id);
    }
}
