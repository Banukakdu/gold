<?php

namespace Tests\Feature\DoubleEntry;

/**
 * Figures of docs/specs/double-entry.md section 7 (E-6, E-7, E-8) on the reports E-dataset (ReportExampleSeeder).
 * Asserted by account name, not by code number. "Main Account" is the E-dataset's MAIN Bank.
 */
trait WorkedExample
{
    /** E-6 closing balances at 2026-10-31: [account name => [Dr, Cr]] per branch, and the branch totals. */
    protected const E6 = [
        'MAIN' => ['Main Account' => ['620000.00', '0.00'], 'Branch current account – KDY' => ['270000.00', '0.00'],
            'Branch current account – GAL' => ['110000.00', '0.00'], "Owner's capital" => ['0.00', '1000000.00']],
        'KDY' => ['Cash' => ['254300.00', '0.00'], 'Bank' => ['40000.00', '0.00'], 'Rent' => ['30000.00', '0.00'],
            'Utilities > Electricity' => ['8200.00', '0.00'], 'Head office current account' => ['0.00', '270000.00'],
            'Sales > Retail' => ['0.00', '50000.00'], 'Service income' => ['0.00', '12500.00']],
        'GAL' => ['Cash' => ['137200.00', '0.00'], 'Utilities > Water' => ['1800.00', '0.00'],
            'Head office current account' => ['0.00', '110000.00'], 'Sales > Retail' => ['0.00', '29000.00']],
    ];

    protected const E6_TOTALS = ['MAIN' => '1000000.00', 'KDY' => '332500.00', 'GAL' => '139000.00'];

    /** Asserts a by-branch trial balance for 2026-10-01..31 equals E-6, including the KDY notes. */
    protected function assertE6(array $tb): void
    {
        $this->assertTrue($tb['balanced']);
        $sections = collect($tb['sections'])->keyBy('branch.code');
        $this->assertSame(['MAIN', 'GAL', 'KDY'], $sections->keys()->all(), 'Main first, then by code');
        foreach (self::E6 as $code => $rows) {
            $s = $sections[$code];
            $closing = collect($s['rows'])->mapWithKeys(fn ($r) => [$r['account']['name'] => [$r['closing_dr'], $r['closing_cr']]])->all();
            $this->assertEquals($rows, $closing, "{$code} closing balances");
            $this->assertSame([self::E6_TOTALS[$code], self::E6_TOTALS[$code]], [$s['totals']['closing_dr'], $s['totals']['closing_cr']], "{$code} totals");
            $this->assertSame($s['totals']['opening_dr'], $s['totals']['opening_cr']);
            $this->assertSame($s['totals']['period_dr'], $s['totals']['period_cr']);
            $this->assertTrue($s['balanced']);
        }
        $this->assertNull(collect($sections['GAL']['rows'])->firstWhere('account.name', 'Rent'), 'GAL Rent nets to 0.00 (void) and is not listed');

        $kdy = collect($sections['KDY']['rows'])->keyBy('account.name');
        $this->assertSame(['13200.00', '5000.00'], [$kdy['Utilities > Electricity']['period_dr'], $kdy['Utilities > Electricity']['period_cr']]);
        $this->assertSame(['0.00', '50000.00'], [$kdy['Sales > Retail']['period_dr'], $kdy['Sales > Retail']['period_cr']]);
        $ho = $kdy['Head office current account'];
        $this->assertSame(['40000.00', '110000.00'], [$ho['period_dr'], $ho['period_cr']]);
        $this->assertSame(['0.00', '200000.00'], [$ho['opening_dr'], $ho['opening_cr']]);
        $this->assertSame(['170000.00', '0.00'], [$kdy['Cash']['opening_dr'], $kdy['Cash']['opening_cr']]);
        $this->assertSame(['30000.00', '0.00'], [$kdy['Rent']['opening_dr'], $kdy['Rent']['opening_cr']]);
    }

    /** Asserts a consolidated trial balance (All, 2026-10-01..31) equals E-7. */
    protected function assertE7(array $tb): void
    {
        $this->assertTrue($tb['balanced']);
        $this->assertFalse($tb['subset']);
        $rows = collect($tb['rows'])->keyBy('label');
        $this->assertSame(['0.00'], array_values(array_unique($tb['totals']['by_branch'])), 'every branch column totals 0.00');
        $this->assertSame('0.00', $tb['totals']['elimination']);
        $this->assertSame(['1091500.00', '1091500.00'], [$tb['totals']['dr'], $tb['totals']['cr']]);

        $ids = collect($tb['branches'])->pluck('id', 'code');
        foreach (['KDY' => '270000.00', 'GAL' => '110000.00'] as $code => $amount) {
            $pair = $rows["MAIN ↔ {$code}"];
            $this->assertSame([$amount, "-{$amount}"], [$pair['by_branch'][$ids['MAIN']], $pair['by_branch'][$ids[$code]]]);
            $this->assertSame(['0.00', '0.00', '0.00'], [$pair['elimination'], $pair['dr'], $pair['cr']]);
        }
        $this->assertFalse($rows->has('KDY ↔ GAL'));
        $this->assertSame(2, $rows->where('group', 'interbranch')->count());

        $dr = ['MAIN · Main Account' => '620000.00', 'KDY · Cash' => '254300.00', 'KDY · Bank' => '40000.00', 'GAL · Cash' => '137200.00',
            'Rent' => '30000.00', 'Utilities > Electricity' => '8200.00', 'Utilities > Water' => '1800.00'];
        $cr = ["Owner's capital" => '1000000.00', 'Sales > Retail' => '79000.00', 'Service income' => '12500.00'];
        $this->assertEquals($dr, $rows->filter(fn ($r) => $r['dr'] !== '0.00')->map(fn ($r) => $r['dr'])->all());
        $this->assertEquals($cr, $rows->filter(fn ($r) => $r['cr'] !== '0.00')->map(fn ($r) => $r['cr'])->all());
    }

    /** E-8: GAL 2026-11-01..30, Retail opening 29,000.00 Cr, period debits 4,000.00, closing 25,000.00 Cr; Cash 133,200.00. */
    protected function assertE8(array $tb): void
    {
        $gal = collect(collect($tb['sections'])->firstWhere('branch.code', 'GAL')['rows'])->keyBy('account.name');
        $r = $gal['Sales > Retail'];
        $this->assertSame(['0.00', '29000.00', '4000.00', '0.00', '0.00', '25000.00'],
            [$r['opening_dr'], $r['opening_cr'], $r['period_dr'], $r['period_cr'], $r['closing_dr'], $r['closing_cr']]);
        $this->assertSame('133200.00', $gal['Cash']['closing_dr']);
    }
}
