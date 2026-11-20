<?php

namespace Tests\Feature\YearEnd;

use App\Models\Category;
use App\Services\ReportService;
use App\Support\Money;

/** Settings spec ACs 19, 20, 26, 27, 41, 42 and review AC 53 (worked example Y-1, Y-2, Y-3). */
class TrialBalanceTest extends YearEndTestCase
{
    private const FY = ['from' => '2026-04-01', 'to' => '2027-03-31'];

    private const APRIL = ['from' => '2027-04-01', 'to' => '2027-04-30'];

    /** A section's rows keyed by name, plus its totals. */
    private function section(array $tb, string $code): array
    {
        $s = collect($tb['sections'])->firstWhere('branch.code', $code);

        return [collect($s['rows'])->keyBy('account.name')->all(), $s['totals'], $s['balanced']];
    }

    private function strip(array $r): array
    {
        unset($r['fiscal_year'], $r['closing_entries'], $r['basis'], $r['generated_at']);

        return $r;
    }

    public function test_ac19_the_trial_balance_stays_in_one_fiscal_year(): void
    {
        $this->sa()->getJson('/api/v1/reports/trial-balance?from=2027-03-01&to=2027-04-30')->assertStatus(422)
            ->assertJsonPath('message', 'From and To must be in the same fiscal year (FY 2026-27: 2026-04-01 to 2027-03-31).');
        $this->sa()->getJson('/api/v1/reports/income-expense?from=2027-03-01&to=2027-04-30')->assertOk();
    }

    public function test_ac20_y3_before_the_close_has_the_earlier_result_row(): void
    {
        $this->sa($this->kdy)->postJson('/api/v1/incomes', ['account_id' => $this->acc($this->kdy, 'Cash')->id,
            'category_id' => Category::where('name', 'Service income')->value('id'), 'txn_date' => '2027-04-05', 'amount' => '400.00'])->assertCreated();
        $tb = $this->tb(self::APRIL + ['branches' => [$this->kdy->id]]);
        [$rows, $tot, $balanced] = $this->section($tb, 'KDY');
        $this->assertTrue($balanced);
        $ie = collect($rows)->filter(fn ($r) => in_array($r['account']['class'], ['income', 'expense'], true));
        $this->assertNotEmpty($ie);
        $this->assertTrue($ie->every(fn ($r) => $r['opening_dr'] === '0.00' && $r['opening_cr'] === '0.00'));
        $this->assertSame(['0.00', '400.00'], [$rows['Service income']['opening_cr'], $rows['Service income']['closing_cr']]);
        $earlier = $rows[ReportService::EARLIER_RESULT];
        $this->assertSame(['24300.00', '24300.00', '0.00'], [$earlier['opening_cr'], $earlier['closing_cr'], $earlier['closing_dr']]);
        $this->assertSame($tot['closing_dr'], $tot['closing_cr']);
        $this->assertTrue($tb['earlier_unclosed']);
        $this->assertSame('FY 2026-27', $tb['earlier_unclosed_label']);

        $all = $this->tb(self::APRIL);
        foreach (['GAL' => '6800.00', 'MAIN' => '10000.00'] as $code => $v) {
            $this->assertSame($v, $this->section($all, $code)[0][ReportService::EARLIER_RESULT]['closing_dr']);
        }
        $c = collect($this->tb(self::APRIL + ['view' => 'consolidated'])['rows'])->keyBy('key');
        $this->assertSame(['0.00', '7500.00'], [$c['earlier_result']['dr'], $c['earlier_result']['cr']]);
    }

    public function test_ac26_y1_y2_pre_and_post_closing(): void
    {
        $this->lock('2027-03');
        $y1 = $this->tb(self::FY);
        $y1c = $this->tb(self::FY + ['view' => 'consolidated']);
        $this->close()->assertCreated();
        $this->assertSame($this->strip($y1), $this->strip($this->tb(self::FY)));
        $this->assertSame($this->strip($y1c), $this->strip($this->tb(self::FY + ['view' => 'consolidated'])));
        $this->assertFalse($this->tb(self::FY)['closing_entries']);

        $y2 = $this->tb(self::FY + ['closing_entries' => 1]);
        $this->assertTrue($y2['balanced'] && $y2['closing_entries']);
        foreach ($y2['sections'] as $s) {
            foreach ($s['rows'] as $r) {
                if (in_array($r['account']['class'], ['income', 'expense'], true)) {
                    $this->assertSame(['0.00', '0.00'], [$r['closing_dr'], $r['closing_cr']], "{$s['branch']['code']} {$r['account']['name']}");
                }
            }
        }
        $main = $this->section($y2, 'MAIN')[0];
        $this->assertSame('7500.00', $main['Retained earnings']['closing_cr']);
        $this->assertArrayNotHasKey('Retained earnings', $this->section($y1, 'MAIN')[0]);
        $hoNet = fn ($tb, $code) => Money::toCents($this->section($tb, $code)[0]['Head office current account']['closing_dr'])
            - Money::toCents($this->section($tb, $code)[0]['Head office current account']['closing_cr']);
        $this->assertSame(-2430000, $hoNet($y2, 'KDY') - $hoNet($y1, 'KDY'));
        $this->assertSame(680000, $hoNet($y2, 'GAL') - $hoNet($y1, 'GAL'));
        $c = $this->tb(self::FY + ['view' => 'consolidated', 'closing_entries' => 1]);
        $this->assertTrue($c['balanced']);
        $this->assertSame('0.00', $c['totals']['elimination']);
        $this->assertSame('7500.00', collect($c['rows'])->firstWhere('key', 'retained_earnings')['cr']);

        $this->sa()->getJson('/api/v1/reports/trial-balance?'.http_build_query(['from' => '2026-04-01', 'to' => '2027-03-30', 'closing_entries' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('closing_entries');
    }

    public function test_ac27_y3_after_the_close(): void
    {
        $this->lock('2027-03');
        $before = $this->tb(self::APRIL + ['view' => 'consolidated'])['totals'];
        $this->close()->assertCreated();
        $tb = $this->tb(self::APRIL);
        foreach ($tb['sections'] as $s) {
            $this->assertNotContains(ReportService::EARLIER_RESULT, array_column(array_column($s['rows'], 'account'), 'name'));
        }
        $this->assertTrue($tb['balanced']);
        $this->assertFalse($tb['earlier_unclosed']);
        $c = $this->tb(self::APRIL + ['view' => 'consolidated']);
        $rows = collect($c['rows'])->keyBy('key');
        $this->assertArrayNotHasKey('earlier_result', $rows->all());
        $this->assertSame('7500.00', $rows['retained_earnings']['cr']);
        $this->assertSame([$before['dr'], $before['cr']], [$c['totals']['dr'], $c['totals']['cr']]);
    }

    public function test_ac41_ac42_other_reports_and_the_dashboard_do_not_change(): void
    {
        $this->lock('2027-03');
        $ranges = [self::FY, ['from' => '2027-03-01', 'to' => '2027-03-31'], ['from' => '2027-03-31', 'to' => '2027-03-31']];
        $snap = function () use ($ranges) {
            $out = [];
            foreach ($ranges as $r) {
                foreach (['income-expense', 'branch-comparison', 'transfers'] as $name) {
                    $out[] = $this->sa()->getJson("/api/v1/reports/{$name}?".http_build_query($r))->assertOk()->json();
                }
                $out[] = $this->sa()->getJson('/api/v1/dashboard?'.http_build_query($r))->assertOk()->json();
            }
            foreach ([$this->kdy, $this->gal, $this->main] as $b) {
                $out[] = $this->sa()->getJson('/api/v1/reports/cash-book?'.http_build_query(['from' => '2027-03-01', 'to' => '2027-03-31', 'branches' => [$b->id]]))->assertOk()->json();
            }

            return $out;
        };
        $before = $snap();
        $this->close()->assertCreated();
        $this->assertSame($before, $snap());
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_ac53_pdf_titles_and_signatures(): void
    {
        $this->closeY();
        $text = fn (array $q) => implode("\n", $this->pdfPages($this->sa()->post('/api/v1/reports/trial-balance/export', self::FY + $q + ['format' => 'pdf'])->assertOk()->getContent()));
        $pre = $text([]);
        $this->assertStringContainsString('Trial balance - pre-closing', $pre);
        foreach (['Prepared by', 'Checked by', 'Approved by'] as $who) {
            $this->assertStringContainsString($who, $pre);
        }
        $this->assertStringContainsString('Trial balance - post-closing', $text(['closing_entries' => 1]));
    }
}
