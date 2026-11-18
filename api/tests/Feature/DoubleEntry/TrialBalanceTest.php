<?php

namespace Tests\Feature\DoubleEntry;

use App\Models\AuditLog;
use App\Models\PeriodLock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportTestCase;

/** Double-entry spec ACs 41-48 (S2 trial balance) and E-6..E-8 on the E-dataset posted with double entry. */
class TrialBalanceTest extends ReportTestCase
{
    use WorkedExample;

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-11-18 10:00:00');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tb(array $q, string|int $branch = 'all'): array
    {
        return $this->report('trial-balance', $q, $branch)->assertOk()->json();
    }

    public function test_ac41_by_branch_october_equals_e6(): void
    {
        $tb = $this->tb($this->october());
        $this->assertSame('branch', $tb['view']);
        $this->assertE6($tb);
        $this->assertTrue($tb['provisional']);
        $this->assertStringStartsWith('All posted lines; period columns exclude voided entries', $tb['basis']);
    }

    public function test_ac42_consolidated_october_equals_e7(): void
    {
        $this->assertE7($this->tb($this->october(['view' => 'consolidated'])));
    }

    public function test_ac43_consolidated_subset_is_not_eliminated(): void
    {
        $tb = $this->tb($this->october(['view' => 'consolidated', 'branches' => [$this->kdy->id, $this->gal->id]]));
        $this->assertTrue($tb['subset']);
        $this->assertTrue($tb['balanced']);
        $this->assertSame($tb['totals']['dr'], $tb['totals']['cr']);
        $pairs = collect($tb['rows'])->where('group', 'interbranch')->keyBy('label');
        $this->assertSame(['MAIN ↔ GAL', 'MAIN ↔ KDY'], $pairs->keys()->sort()->values()->all());
        $this->assertNull($pairs['MAIN ↔ KDY']['elimination']);
        $this->assertSame(['-270000.00', '270000.00'], [$pairs['MAIN ↔ KDY']['by_branch'][$this->kdy->id], $pairs['MAIN ↔ KDY']['cr']]);
        $this->assertSame('110000.00', $pairs['MAIN ↔ GAL']['cr']);
        $this->assertSame('0.00', $tb['totals']['elimination']);
    }

    public function test_ac44_identity_holds_for_every_row_and_total(): void
    {
        $periods = [['2026-09-01', '2026-11-30'], ['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30'], ['2026-10-20', '2026-10-20'],
            ['2026-10-12', '2026-10-16'], ['2026-10-30', '2026-11-02']];
        $c = fn ($v) => Money::toCents($v);
        foreach ($periods as [$from, $to]) {
            $tb = $this->tb(['from' => $from, 'to' => $to]);
            $this->assertTrue($tb['balanced'], "{$from}..{$to}");
            foreach ($tb['sections'] as $s) {
                foreach (array_merge($s['rows'], [$s['totals']]) as $r) {
                    $this->assertSame($c($r['closing_dr']) - $c($r['closing_cr']),
                        $c($r['opening_dr']) - $c($r['opening_cr']) + $c($r['period_dr']) - $c($r['period_cr']), "{$from}..{$to} identity");
                }
            }
            $cons = $this->tb(['from' => $from, 'to' => $to, 'view' => 'consolidated']);
            $this->assertTrue($cons['balanced']);
            foreach ($cons['rows'] as $r) {
                $this->assertSame($c($r['closing']), $c($r['opening']) + $c($r['period_dr']) - $c($r['period_cr']));
                $this->assertSame($c($r['closing']) + $c($r['elimination'] ?? '0'), $c($r['dr']) - $c($r['cr']));
            }
        }
    }

    public function test_ac45_gal_november_equals_e8(): void
    {
        $this->assertE8($this->tb(['from' => '2026-11-01', 'to' => '2026-11-30']));
    }

    public function test_ac46_branch_user_scope(): void
    {
        $u = $this->userIn($this->kdy, 'Accountant');
        $as = fn () => $this->flushHeaders()->actingAs($u)->withHeader('X-Branch-Id', (string) $this->kdy->id);
        $tb = $as()->getJson('/api/v1/reports/trial-balance?'.http_build_query($this->october()))->assertOk()->json();
        $this->assertSame(['KDY'], array_column(array_column($tb['sections'], 'branch'), 'code'));
        $as()->getJson('/api/v1/reports/trial-balance?'.http_build_query($this->october(['view' => 'consolidated'])))
            ->assertStatus(422)->assertJsonPath('message', 'Select at least two branches in All branches mode');
        $as()->getJson('/api/v1/reports/trial-balance?'.http_build_query($this->october(['branches' => [$this->gal->id]])))->assertForbidden();
        $this->report('trial-balance', $this->october(['view' => 'consolidated', 'branches' => [$this->kdy->id]]))->assertStatus(422);
        $cashier = $this->userIn($this->kdy, 'Cashier');
        $this->flushHeaders()->actingAs($cashier)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/trial-balance?'.http_build_query($this->october()))->assertForbidden();
    }

    public function test_ac47_provisional_and_exports_match_the_screen(): void
    {
        PeriodLock::create(['branch_id' => null, 'period' => '2026-10', 'locked_by' => $this->admin->id]);
        $this->assertFalse($this->tb($this->october())['provisional']);
        $this->assertTrue($this->tb(['from' => '2026-11-01', 'to' => '2026-11-30'])['provisional']);

        $q = $this->october();
        $pdf = $this->asAdmin()->post('/api/v1/reports/trial-balance/export', $q + ['format' => 'pdf'])->assertOk();
        $this->assertSame('attachment; filename="trial-balance_2026-10-01_2026-10-31.pdf"', $pdf->headers->get('Content-Disposition'));
        $pages = $this->pdfPages($pdf->getContent());
        $all = implode(' ', $pages);
        foreach (['Trial Balance', 'Amounts in LKR', 'All posted lines; period columns exclude voided entries', '1,000,000.00', '332,500.00', '139,000.00',
            'Head office current account', 'Prepared by', 'Checked by', 'Approved by'] as $text) {
            $this->assertStringContainsString($text, $all);
        }
        $this->assertStringContainsString('Approved by', end($pages));
        $this->assertStringNotContainsString('PROVISIONAL', $all);

        $xlsx = $this->asAdmin()->post('/api/v1/reports/trial-balance/export', $q + ['format' => 'xlsx'])->assertOk();
        $book = $this->xlsx($xlsx);
        $this->assertSame(['MAIN Main Branch', 'GAL Galle', 'KDY Kandy'], array_slice($book->getSheetNames(), 0, 3));
        $ws = $book->getSheet(2);
        $rows = $ws->toArray(null, true, false);
        $this->assertSame('Trial Balance', $rows[1][0]);
        $total = collect($rows)->first(fn ($r) => ($r[1] ?? null) === 'Total');
        $this->assertEquals([332500, 332500], [$total[7], $total[8]]);

        $cons = $this->asAdmin()->post('/api/v1/reports/trial-balance/export', $q + ['format' => 'xlsx', 'view' => 'consolidated'])->assertOk();
        $rows = $this->xlsx($cons)->getSheet(0)->toArray(null, true, false);
        $total = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Total');
        $this->assertEquals([1091500, 1091500], array_slice($total, -2));

        $logs = AuditLog::where('event', 'report.exported')->get();
        $this->assertCount(3, $logs);
        $this->assertSame('trial-balance', $logs[0]->new_values['name']);
    }

    public function test_ac48_period_movement_on_category_accounts_equals_income_and_expense(): void
    {
        foreach ([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30'], ['2026-10-01', '2026-11-30']] as [$from, $to]) {
            foreach ([$this->kdy, $this->gal] as $b) {
                $q = ['from' => $from, 'to' => $to, 'branches' => [$b->id]];
                $ie = $this->report('income-expense', $q)->assertOk()->json();
                $expected = [];
                foreach (['income', 'expense'] as $type) {
                    foreach ($ie[$type]['categories'] as $c) {
                        foreach ($c['subs'] as $s) {
                            $name = $s['id'] ? "{$c['name']} > {$s['name']}" : $c['name'];
                            $expected[$name] = $s['amount'];
                        }
                    }
                }
                $rows = collect($this->tb($q)['sections'][0]['rows'])->filter(fn ($r) => in_array($r['account']['class'], ['income', 'expense'], true));
                $actual = $rows->mapWithKeys(fn ($r) => [$r['account']['name'] => Money::fromCents($r['account']['class'] === 'income'
                    ? Money::toCents($r['period_cr']) - Money::toCents($r['period_dr'])
                    : Money::toCents($r['period_dr']) - Money::toCents($r['period_cr']))])
                    ->reject(fn ($v) => $v === '0.00')->all();
                $this->assertEquals($expected, $actual, "{$b->code} {$from}..{$to}");
            }
        }
    }
}
