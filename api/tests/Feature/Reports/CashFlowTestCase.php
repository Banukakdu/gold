<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/** AG-086 (docs/specs/cash-flow.md): the E-dataset with "today" 2026-11-20 (AC 11, AC 22). */
abstract class CashFlowTestCase extends ReportTestCase
{
    protected const KEYS = ReportService::CASH_FLOW_KEYS;

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** GET cash-flow as Super Admin; $branches = codes (empty = All mode, no branches ticked). */
    protected function cf(string $from, string $to, array $branches = []): array
    {
        $ids = array_map(fn ($c) => Branch::where('code', $c)->value('id'), $branches);

        return $this->report('cash-flow', ['from' => $from, 'to' => $to, 'branches' => $ids])->assertOk()->json();
    }

    /** A month or total row as the values of KEYS, in order. */
    protected function vals(array $row): array
    {
        return array_map(fn ($k) => $row[$k], self::KEYS);
    }

    /** Integer cents of a row, keyed. */
    protected function cents(array $row): array
    {
        return array_map(fn ($k) => Money::toCents($row[$k]), array_combine(self::KEYS, self::KEYS));
    }

    /** R6 (c) and R5 on every row and the total: Op + Funding = Net = Inflow - Outflow; Opening + Net = Closing. */
    protected function assertRowsReconcile(array $r): void
    {
        $prev = null;
        foreach ([...$r['months'], $r['total']] as $i => $row) {
            $c = $this->cents($row);
            $this->assertSame($c['net'], $c['inflow'] - $c['outflow'], "row $i");
            $this->assertSame($c['net'], $c['operating_net'] + $c['funding_net'], "row $i");
            $this->assertSame($c['inflow'], $c['income'] + $c['capital'] + $c['funds_received'] + $c['transfers_in'], "row $i");
            $this->assertSame($c['outflow'], $c['expenses'] + $c['transfers_out'], "row $i");
            $this->assertSame($c['closing'], $c['opening'] + $c['net'], "row $i");
        }
        foreach ($r['months'] as $row) {
            if ($prev !== null) {
                $this->assertSame($prev, $row['opening'], "opening of {$row['month']}");
            }
            $prev = $row['closing'];
        }
    }
}
