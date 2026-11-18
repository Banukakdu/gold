<?php

namespace Tests\Feature\Reports;

use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** AG-083 Branch Comparison (R5 + accounting review finding 1 and 2). */
class BranchComparisonReportTest extends ReportTestCase
{
    private const COLS = ['opening', 'capital', 'funds_received', 'income', 'expenses', 'transfers_in', 'transfers_out', 'closing'];

    /** AC 11: E-2 exactly, Capital column 0.00, each row reconciles. */
    public function test_ac11_october_equals_e2(): void
    {
        $r = $this->report('branch-comparison', $this->october())->assertOk()->json();

        $this->assertSame([
            'MAIN' => ['700000.00', '0.00', '0.00', '0.00', '0.00', '20000.00', '100000.00', '620000.00'],
            'GAL' => ['100000.00', '0.00', '0.00', '29000.00', '1800.00', '30000.00', '20000.00', '137200.00'],
            'KDY' => ['170000.00', '0.00', '100000.00', '62500.00', '8200.00', '0.00', '30000.00', '294300.00'],
        ], $this->rows($r));
        $this->assertSame(['970000.00', '0.00', '100000.00', '91500.00', '10000.00', '50000.00', '150000.00', '1051500.00'], $this->vals($r['total']));
        // Main first, then by code (GAL before KDY).
        $this->assertSame(['MAIN', 'GAL', 'KDY'], array_column(array_column($r['rows'], 'branch'), 'code'));

        foreach ([...$r['rows'], $r['total']] as $row) {
            $c = array_map([Money::class, 'toCents'], array_intersect_key($row, array_flip(self::COLS)));
            $this->assertSame($c['closing'], $c['opening'] + $c['capital'] + $c['funds_received'] + $c['income'] - $c['expenses'] + $c['transfers_in'] - $c['transfers_out']);
        }
        // Finding 2: inter-branch movements eliminate in the consolidated total.
        $t = array_map([Money::class, 'toCents'], $r['total']);
        $this->assertSame(0, $t['funds_received'] + $t['transfers_in'] - $t['transfers_out']);
    }

    /** AC 12: closings equal the sum of the branch's account statements. */
    public function test_ac12_closing_matches_account_statements(): void
    {
        $r = $this->report('branch-comparison', $this->october())->json();
        $closing = collect($r['rows'])->mapWithKeys(fn ($row) => [$row['branch']['code'] => $row['closing']]);
        $statement = fn ($acc) => $this->asAdmin($acc->branch_id)->getJson("/api/v1/accounts/{$acc->id}/statement?from=2026-10-01&to=2026-10-31")->assertOk()->json('closing_balance');

        $this->assertSame('620000.00', $statement($this->mainAcc));
        $this->assertSame('254300.00', $statement($this->acc($this->kdy, 'Cash')));
        $this->assertSame('40000.00', $statement($this->acc($this->kdy, 'Bank')));
        $this->assertSame('137200.00', $statement($this->acc($this->gal, 'Cash')));
        $this->assertSame($closing['MAIN'], $statement($this->mainAcc));
        $this->assertSame($closing['KDY'], Money::add($statement($this->acc($this->kdy, 'Cash')), $statement($this->acc($this->kdy, 'Bank'))));
        $this->assertSame($closing['GAL'], $statement($this->acc($this->gal, 'Cash')));
    }

    /** AC 14: deactivated branches stay in the report with their figures. */
    public function test_ac14_inactive_branch_is_listed(): void
    {
        $this->asAdmin()->putJson("/api/v1/branches/{$this->gal->id}", ['status' => 'inactive'])->assertOk();
        $r = $this->report('branch-comparison', $this->october())->json();
        $gal = collect($r['rows'])->firstWhere('branch.code', 'GAL');
        $this->assertSame('inactive', $gal['branch']['status']);
        $this->assertSame('137200.00', $gal['closing']);
    }

    /** AC 26 (finding 1): a reversal entry stays in the original's column, negated. */
    public function test_ac26_reversal_is_classified_by_the_original(): void
    {
        $d15 = $this->rows($this->report('branch-comparison', ['from' => '2026-10-15', 'to' => '2026-10-15'])->json());
        $this->assertSame('10000.00', $d15['KDY'][5]);
        $this->assertSame('10000.00', $d15['GAL'][6]);

        $d16 = $this->rows($this->report('branch-comparison', ['from' => '2026-10-16', 'to' => '2026-10-16'])->json());
        $this->assertSame(['-10000.00', '0.00'], [$d16['KDY'][5], $d16['KDY'][6]]);
        $this->assertSame(['0.00', '-10000.00'], [$d16['GAL'][5], $d16['GAL'][6]]);

        // Expense reversal (10) on 10-14 is negative expense, not income.
        $d14 = $this->rows($this->report('branch-comparison', ['from' => '2026-10-14', 'to' => '2026-10-14'])->json());
        $this->assertSame(['0.00', '-5000.00'], [$d14['KDY'][3], $d14['KDY'][4]]);
        // Income reversal (13) on 11-02 is negative income.
        $nov = $this->rows($this->report('branch-comparison', ['from' => '2026-11-01', 'to' => '2026-11-30'])->json());
        $this->assertSame(['-4000.00', '0.00'], [$nov['GAL'][3], $nov['GAL'][4]]);
    }

    /** AC 27 (finding 2): Capital introduced is its own column. */
    public function test_ac27_september_with_capital(): void
    {
        $r = $this->report('branch-comparison', ['from' => '2026-09-01', 'to' => '2026-09-30'])->json();
        $rows = $this->rows($r);
        $this->assertSame(['0.00', '1000000.00', '0.00', '0.00', '0.00', '0.00', '300000.00', '700000.00'], $rows['MAIN']);
        $this->assertSame(['0.00', '0.00', '200000.00', '0.00', '30000.00', '0.00', '0.00', '170000.00'], $rows['KDY']);
        $this->assertSame(['0.00', '0.00', '100000.00', '0.00', '0.00', '0.00', '0.00', '100000.00'], $rows['GAL']);
        $this->assertSame('1000000.00', $r['total']['capital']);
        $this->assertSame('970000.00', $r['total']['closing']);
        $t = array_map([Money::class, 'toCents'], $r['total']);
        $this->assertSame(0, $t['funds_received'] + $t['transfers_in'] - $t['transfers_out']);
    }

    /** AC 5 (comparison part): voided (12) does not count; internal transfer (8) is not shown. */
    public function test_ac5_void_and_internal_transfer_are_not_counted(): void
    {
        $rows = $this->rows($this->report('branch-comparison', ['from' => '2026-10-08', 'to' => '2026-10-20'])->json());
        $this->assertSame('0.00', $rows['GAL'][4]); // no 15,000 rent
        $this->assertSame(['0.00', '0.00'], [$rows['KDY'][5], $rows['KDY'][6]]); // internal 40,000 not a transfer; (11) nets to 0
    }

    public function test_branch_user_and_subset_note(): void
    {
        $viewer = $this->userIn($this->kdy, 'Viewer');
        $r = $this->actingAs($viewer)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/branch-comparison?'.http_build_query($this->october()))->assertOk()->json();
        $this->assertSame(['KDY'], array_column(array_column($r['rows'], 'branch'), 'code'));
        $this->assertFalse($r['subset']);

        $sub = $this->report('branch-comparison', $this->october(['branches' => [$this->kdy->id, $this->gal->id]]))->json();
        $this->assertTrue($sub['subset']);
        $this->assertSame(['GAL', 'KDY'], array_column(array_column($sub['rows'], 'branch'), 'code'));
        $this->report('branch-comparison', $this->october(['branches' => [99999]]))->assertStatus(422);
    }

    private function rows(array $r): array
    {
        return collect($r['rows'])->mapWithKeys(fn ($row) => [$row['branch']['code'] => $this->vals($row)])->all();
    }

    private function vals(array $row): array
    {
        return array_map(fn ($k) => $row[$k], self::COLS);
    }

    /** R5: the server asserts Closing = ledger balance; a mismatch is a 500 and is logged. */
    public function test_unreconciled_ledger_is_a_server_error(): void
    {
        $cash = $this->acc($this->kdy, 'Cash');
        $id = DB::table('transactions')->insertGetId(['branch_id' => $this->kdy->id, 'type' => 'adjustment', 'txn_date' => '2026-10-09',
            'amount' => '1.00', 'status' => 'posted', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_entries')->insert(['transaction_id' => $id, 'account_id' => $cash->id, 'branch_id' => $this->kdy->id,
            'entry_date' => '2026-10-09', 'debit' => '1.00', 'credit' => '0.00']);
        Log::spy();

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        try {
            $this->report('branch-comparison', $this->october());
        } finally {
            Log::shouldHaveReceived('error')->once();
        }
    }
}
