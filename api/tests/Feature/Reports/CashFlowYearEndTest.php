<?php

namespace Tests\Feature\Reports;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use Tests\Feature\YearEnd\YearEndTestCase;

/** AG-086 AC 10: the year-end close (AG-092) and its reopening never change the cash flow ("today" 2027-04-10). */
class CashFlowYearEndTest extends YearEndTestCase
{
    private function runs(): array
    {
        $out = [];
        foreach ([['2026-04-01', '2027-03-31', []], ['2026-09-01', '2027-04-30', []], ['2026-04-01', '2027-03-31', [$this->kdy->id]],
            ['2026-04-01', '2027-03-31', [$this->kdy->id, $this->gal->id]]] as [$from, $to, $branches]) {
            $r = $this->sa()->getJson('/api/v1/reports/cash-flow?'.http_build_query(['from' => $from, 'to' => $to, 'branches' => $branches]))->assertOk()->json();
            $out[] = [$r['months'], $r['total'], $r['notes'], $r['basis']];
        }

        return $out;
    }

    public function test_ac10_close_and_reopen_change_nothing(): void
    {
        $before = $this->runs();
        // The Y-dataset FY 2026-27: closing 1,047,500.00 - 30,000.00 (GAL rent) - 10,000.00 (MAIN rent).
        $this->assertSame('1007500.00', $before[0][1]['closing']);
        $this->assertSame('FY 2026-27, 1 Apr 2026 to 31 Mar 2027', $this->sa()->getJson('/api/v1/reports/cash-flow?from=2026-04-01&to=2027-03-31')->json('period_label'));

        $this->closeY();
        $this->assertCount(3, $this->closeTxns());
        $this->assertSame($before, $this->runs());
        // No year_end_close transaction has a line on a money account.
        $this->assertFalse(LedgerEntry::with('account')->whereIn('transaction_id', Transaction::withoutGlobalScopes()->where('type', Transaction::YEAR_END_CLOSE)->pluck('id'))
            ->get()->contains(fn ($l) => $l->account->isMoney()));

        $this->reopen()->assertOk();
        $this->assertSame($before, $this->runs());
    }
}
