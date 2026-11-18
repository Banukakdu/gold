<?php

namespace Tests\Feature\Reports;

/** AC 28 (accounting review finding 3): the dashboard uses the report basis (R3). */
class DashboardBasisTest extends ReportTestCase
{
    public function test_ac28_dashboard_follows_r3(): void
    {
        $this->travelTo('2026-11-18 10:00:00'); // the trend covers the last 12 months
        $oct = $this->asAdmin()->getJson('/api/v1/dashboard?from=2026-10-01&to=2026-10-31')->assertOk()->json();
        $this->assertSame(['91500.00', '10000.00', '81500.00'], [$oct['income'], $oct['expense'], $oct['net']]);
        $this->assertSame([['category' => 'Utilities', 'total' => '10000.00']], $oct['top_expense_categories']);

        $nov = $this->asAdmin($this->gal->id)->getJson('/api/v1/dashboard?from=2026-11-01&to=2026-11-30')->assertOk()->json();
        $this->assertSame('-4000.00', $nov['income']);
        $this->assertSame('-4000.00', $nov['net']);

        // Equal to the Income & Expense report for the same filters.
        $report = $this->report('income-expense', $this->october())->json();
        $this->assertSame([$report['income']['total'], $report['expense']['total']], [$oct['income'], $oct['expense']]);

        // Branch mode: only the branch's own figures.
        $kdy = $this->userIn($this->kdy, 'Cashier');
        $d = $this->actingAs($kdy)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/dashboard?from=2026-10-01&to=2026-10-31')->json();
        $this->assertSame(['62500.00', '8200.00'], [$d['income'], $d['expense']]);

        // Trend: October and November months follow the same basis.
        $trend = collect($oct['trend'])->keyBy('month');
        $this->assertSame(['91500.00', '10000.00'], [$trend['2026-10']['income'], $trend['2026-10']['expense']]);
        $this->assertSame('-4000.00', $trend['2026-11']['income']);
        $this->assertSame(['0.00', '30000.00'], [$trend['2026-09']['income'], $trend['2026-09']['expense']]);
    }
}
