<?php

namespace Tests\Feature\FundReturn;

use App\Support\Money;

/** Fund return AC 13 with the consolidation check of accounting review finding 5. */
class ReportsTest extends FundReturnTestCase
{
    private const NOV = ['from' => '2026-11-01', 'to' => '2026-11-18'];

    private const COLS = ['opening', 'capital', 'funds_received', 'income', 'expenses', 'transfers_in', 'transfers_out', 'closing'];

    private function report(string $name, array $q = []): array
    {
        return $this->sa()->getJson("/api/v1/reports/{$name}?".http_build_query(self::NOV + $q))->assertOk()->json();
    }

    public function test_ac13_reports_trial_balance_and_verify(): void
    {
        $ie = $this->report('income-expense');
        $t = $this->fundReturn()->assertCreated()->json();

        // Transfers report: "Fund return" GAL -> MAIN 20,000.00, in the matrix too.
        $r = $this->report('transfers');
        $row = collect($r['movements']['data'])->firstWhere('id', $t['id']);
        $this->assertSame(['fund_return', 'GAL', 'MAIN', '20000.00', 'posted'],
            [$row['movement_type'], $row['from']['branch_code'], $row['to']['branch_code'], $row['amount'], $row['status']]);
        $cell = collect($r['matrix']['cells'])->first(fn ($c) => $c['from_id'] === $this->gal->id && $c['to_id'] === $this->main->id);
        $this->assertSame('20000.00', $cell['amount']);

        // Branch Comparison: GAL transfers out, MAIN transfers in; every row and the total reconcile.
        $bc = $this->report('branch-comparison');
        $rows = collect($bc['rows'])->keyBy('branch.code');
        $this->assertSame('20000.00', $rows['GAL']['transfers_out']);
        $this->assertSame('20000.00', $rows['MAIN']['transfers_in']);
        foreach ([...$bc['rows'], $bc['total']] as $x) {
            $c = array_map([Money::class, 'toCents'], array_intersect_key($x, array_flip(self::COLS)));
            $this->assertSame($c['closing'], $c['opening'] + $c['capital'] + $c['funds_received'] + $c['income'] - $c['expenses'] + $c['transfers_in'] - $c['transfers_out']);
        }
        foreach (self::COLS as $col) {
            $this->assertSame(Money::toCents($bc['total'][$col]), $rows->sum(fn ($x) => Money::toCents($x[$col])), "sum of branches = consolidated ({$col})");
        }

        // Income & Expense unchanged.
        $this->assertEquals($ie, $this->report('income-expense'));

        // Consolidated trial balance: the MAIN <-> GAL pair (GAL HO current account, MAIN BCA(GAL)) eliminates to 0.
        $tb = $this->report('trial-balance', ['view' => 'consolidated']);
        $this->assertTrue($tb['balanced']);
        $pair = collect($tb['rows'])->firstWhere('label', 'MAIN ↔ GAL');
        $ho = Money::toCents($this->ho($this->gal)->ledgerBalance());
        $bca = Money::toCents($this->bca($this->gal)->ledgerBalance());
        $this->assertNotSame(0, $ho);
        $this->assertSame(0, $ho + $bca);
        $this->assertSame([Money::fromCents($bca), Money::fromCents($ho)], [$pair['by_branch'][$this->main->id], $pair['by_branch'][$this->gal->id]]);
        $this->assertSame(['0.00', '0.00'], [$pair['dr'], $pair['cr']]);
        $this->assertSame('0.00', $tb['totals']['elimination']);

        $this->artisan('ledger:verify')->assertExitCode(0);
    }
}
