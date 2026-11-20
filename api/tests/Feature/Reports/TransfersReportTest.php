<?php

namespace Tests\Feature\Reports;

use App\Support\Money;

/** AG-084 Transfers & Allocations (R6). */
class TransfersReportTest extends ReportTestCase
{
    /** AC 15 + AC 31: E-3 movements, net moved and matrix. */
    public function test_ac15_ac31_october_equals_e3(): void
    {
        $r = $this->report('transfers', $this->october())->assertOk()->json();
        $m = $r['movements'];

        $this->assertSame(5, $m['total']);
        $this->assertSame([
            ['2026-10-16', 'reversal', 'branch_transfer', 'KDY', 'GAL', '-10000.00', 'reversal'],
            ['2026-10-15', 'branch_transfer', 'branch_transfer', 'GAL', 'KDY', '10000.00', 'reversed'],
            ['2026-10-10', 'fund_return', 'fund_return', 'GAL', 'MAIN', '20000.00', 'posted'],
            ['2026-10-07', 'branch_transfer', 'branch_transfer', 'KDY', 'GAL', '30000.00', 'posted'],
            ['2026-10-06', 'fund_allocation', 'fund_allocation', 'MAIN', 'KDY', '100000.00', 'posted'],
        ], array_map(fn ($x) => [$x['txn_date'], $x['type'], $x['movement_type'], $x['from']['branch_code'], $x['to']['branch_code'], $x['amount'], $x['status']], $m['data']));
        $this->assertSame($this->txn(11)->ref_no, $m['data'][0]['reversal_of_ref']);
        $this->assertStringStartsWith('GAL-TRF-', $m['data'][0]['reversal_of_ref']);
        $this->assertSame('Super Admin', $m['data'][0]['created_by']);

        $this->assertSame('150000.00', $r['net_moved']);
        // AC 31: the signed Amount column foots to Net moved.
        $this->assertSame(Money::toCents($r['net_moved']), array_sum(array_map(fn ($x) => Money::toCents($x['amount']), $m['data'])));

        $mx = $this->matrix($r);
        $this->assertSame([
            'MAIN>GAL' => '0.00', 'MAIN>KDY' => '100000.00',
            'GAL>MAIN' => '20000.00', 'GAL>KDY' => '0.00',
            'KDY>MAIN' => '0.00', 'KDY>GAL' => '30000.00',
        ], $mx['cells']);
        $this->assertSame(['MAIN' => '100000.00', 'GAL' => '20000.00', 'KDY' => '30000.00'], $mx['sent']);
        $this->assertSame(['MAIN' => '20000.00', 'GAL' => '30000.00', 'KDY' => '100000.00'], $mx['received']);
        $this->assertSame(['MAIN' => '-80000.00', 'GAL' => '10000.00', 'KDY' => '70000.00'], $mx['net']);
        $this->assertSame(0, array_sum(array_map([Money::class, 'toCents'], $mx['net'])));

        // Net = Funds received + Transfers in - Transfers out of E-2.
        $cmp = collect($this->report('branch-comparison', $this->october())->json('rows'))->keyBy('branch.code');
        foreach ($mx['net'] as $code => $net) {
            $c = $cmp[$code];
            $this->assertSame($net, Money::fromCents(Money::toCents($c['funds_received']) + Money::toCents($c['transfers_in']) - Money::toCents($c['transfers_out'])));
        }
    }

    /** AC 16: KDY user sees 4 movements and only the KDY row and column. */
    public function test_ac16_branch_user(): void
    {
        $acct = $this->userIn($this->kdy, 'Accountant');
        $r = $this->actingAs($acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/transfers?'.http_build_query($this->october()))->assertOk()->json();

        $this->assertSame(['2026-10-16', '2026-10-15', '2026-10-07', '2026-10-06'], array_column($r['movements']['data'], 'txn_date'));
        $this->assertSame('130000.00', $r['net_moved']);
        $mx = $this->matrix($r);
        foreach (array_keys($mx['cells']) as $pair) {
            $this->assertStringContainsString('KDY', $pair);
        }
        $this->assertSame(['MAIN>KDY' => '100000.00', 'GAL>KDY' => '0.00', 'KDY>MAIN' => '0.00', 'KDY>GAL' => '30000.00'], $mx['cells']);
        $this->assertSame(['KDY' => '70000.00'], array_intersect_key($mx['net'], ['KDY' => 1]));
        $this->assertSame(['MAIN', 'GAL', 'KDY'], array_column($r['matrix']['branches'], 'code'));
        $this->assertSame([false, false, true], array_column($r['matrix']['branches'], 'in_scope'));
    }

    /** AC 17: internal transfers and capital never appear. */
    public function test_ac17_internal_and_capital_excluded(): void
    {
        $r = $this->report('transfers', ['from' => '2026-09-01', 'to' => '2026-11-30'])->assertOk()->json();
        $types = array_column($r['movements']['data'], 'movement_type');
        $this->assertNotContains('internal_transfer', $types);
        $this->assertNotContains('capital', $types);
        $this->assertSame(7, $r['movements']['total']); // 2 opening allocations + 5 October movements
        $this->assertNotContains($this->txn(8)->ref_no, array_column($r['movements']['data'], 'ref_no'));
        $this->assertSame('450000.00', $r['net_moved']);
    }

    public function test_movement_type_filter_and_paging(): void
    {
        $r = $this->report('transfers', $this->october(['types' => ['branch_transfer']]))->assertOk()->json();
        $this->assertSame(3, $r['movements']['total']); // (7), (11) and its reversal
        $this->assertSame('30000.00', $r['net_moved']);
        $this->assertSame(1, $r['movements']['last_page']);
        $this->report('transfers', $this->october(['types' => ['internal_transfer']]))->assertStatus(422);
    }

    private function matrix(array $r): array
    {
        $code = collect($r['matrix']['branches'])->pluck('code', 'id');
        $keyed = fn (array $m) => collect($m)->mapWithKeys(fn ($v, $id) => [$code[$id] => $v])->all();

        return [
            'cells' => collect($r['matrix']['cells'])->mapWithKeys(fn ($c) => [$code[$c['from_id']].'>'.$code[$c['to_id']] => $c['amount']])->all(),
            'sent' => $keyed($r['matrix']['sent']),
            'received' => $keyed($r['matrix']['received']),
            'net' => $keyed($r['matrix']['net']),
        ];
    }
}
