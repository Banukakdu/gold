<?php

namespace Tests\Feature\Reports;

use Tests\Feature\AccountingTestCase;

/**
 * The dashboard's default period and trend months are Asia/Colombo business dates, not the server's UTC date.
 * After 18:30 UTC it is already the next day in Colombo, and entries dated that day must count.
 */
class DashboardBusinessDateTest extends AccountingTestCase
{
    private function entry(string $type, string $date, string $amount): void
    {
        $this->asAdmin($this->main->id)->postJson("/api/v1/{$type}s", [
            'account_id' => $this->mainAcc->id, 'category_id' => $this->category($type)->id, 'txn_date' => $date, 'amount' => $amount,
        ] + ($type === 'expense' ? ['payee_payer' => 'CEB'] : []))->assertCreated();
    }

    public function test_default_to_is_the_colombo_day_after_1830_utc(): void
    {
        $this->travelTo('2026-11-20 20:00:00'); // 2026-11-21 01:30 Asia/Colombo
        $this->entry('income', '2026-11-21', '1000');
        $this->entry('expense', '2026-11-21', '250.50');
        $this->entry('income', '2026-11-20', '100');

        $d = $this->asAdmin()->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame(['2026-11-01', '2026-11-21'], [$d['from'], $d['to']]);
        $this->assertSame(['1100.00', '250.50', '849.50'], [$d['income'], $d['expense'], $d['net']]);
        $trend = collect($d['trend'])->keyBy('month');
        $this->assertSame('2026-11', $d['trend'][11]['month']);
        $this->assertSame(['1100.00', '250.50'], [$trend['2026-11']['income'], $trend['2026-11']['expense']]);

        // Branch mode uses the same defaults.
        $b = $this->asAdmin($this->main->id)->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame(['2026-11-21', '1100.00'], [$b['to'], $b['income']]);
    }

    public function test_default_month_is_the_colombo_month_at_a_month_boundary(): void
    {
        $this->travelTo('2026-11-30 20:00:00'); // 2026-12-01 01:30 Asia/Colombo
        $this->entry('income', '2026-11-30', '400');
        $this->entry('income', '2026-12-01', '700');

        $d = $this->asAdmin()->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame(['2026-12-01', '2026-12-01'], [$d['from'], $d['to']]);
        $this->assertSame('700.00', $d['income']);

        // The trend runs Jan 2026 .. Dec 2026 (12 Colombo months ending with the current one).
        $this->assertSame('2026-01', $d['trend'][0]['month']);
        $this->assertSame('2026-12', $d['trend'][11]['month']);
        $trend = collect($d['trend'])->keyBy('month');
        $this->assertSame(['400.00', '700.00'], [$trend['2026-11']['income'], $trend['2026-12']['income']]);
    }

    public function test_explicit_period_is_unchanged(): void
    {
        $this->travelTo('2026-11-20 20:00:00');
        $this->entry('income', '2026-11-21', '1000');
        $this->entry('income', '2026-11-20', '100');

        $d = $this->asAdmin()->getJson('/api/v1/dashboard?from=2026-11-01&to=2026-11-20')->assertOk()->json();
        $this->assertSame(['2026-11-20', '100.00'], [$d['to'], $d['income']]);
    }
}
