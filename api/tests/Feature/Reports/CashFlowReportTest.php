<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** AG-086 Cash Flow Summary, screen API: worked examples CF-1..CF-4, R1-R7, ACs 1-14, 17-23. */
class CashFlowReportTest extends CashFlowTestCase
{
    private const CF1 = [
        '2026-09' => ['0.00', '0.00', '30000.00', '-30000.00', '1000000.00', '0.00', '0.00', '0.00', '1000000.00', '1000000.00', '30000.00', '970000.00', '970000.00', '300000.00'],
        '2026-10' => ['970000.00', '91500.00', '10000.00', '81500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '91500.00', '10000.00', '81500.00', '1051500.00', '150000.00'],
        '2026-11' => ['1051500.00', '-4000.00', '0.00', '-4000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '-4000.00', '0.00', '-4000.00', '1047500.00', '0.00'],
    ];

    private const CF1_TOTAL = ['0.00', '87500.00', '40000.00', '47500.00', '1000000.00', '0.00', '0.00', '0.00', '1000000.00', '1087500.00', '40000.00', '1047500.00', '1047500.00', '450000.00'];

    /** CF-2: October per branch (Eliminated is 0.00 for a single branch). */
    private const CF2 = [
        'MAIN' => ['700000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '20000.00', '100000.00', '-80000.00', '20000.00', '100000.00', '-80000.00', '620000.00', '0.00'],
        'KDY' => ['170000.00', '62500.00', '8200.00', '54300.00', '0.00', '100000.00', '0.00', '30000.00', '70000.00', '162500.00', '38200.00', '124300.00', '294300.00', '0.00'],
        'GAL' => ['100000.00', '29000.00', '1800.00', '27200.00', '0.00', '0.00', '30000.00', '20000.00', '10000.00', '59000.00', '21800.00', '37200.00', '137200.00', '0.00'],
    ];

    private const CF3 = ['270000.00', '91500.00', '10000.00', '81500.00', '0.00', '100000.00', '0.00', '20000.00', '80000.00', '191500.00', '30000.00', '161500.00', '431500.00', '30000.00'];

    /** AC 1 + AC 20: CF-1 exactly; consolidated; Funding net; nothing in the transfer columns. */
    public function test_ac1_ac20_consolidated_equals_cf1(): void
    {
        $r = $this->cf('2026-09-01', '2026-11-30');

        $this->assertTrue($r['consolidated']);
        $this->assertTrue($r['all_branches']);
        $this->assertSame(array_keys(self::CF1), array_column($r['months'], 'month'));
        foreach ($r['months'] as $m) {
            $this->assertSame(self::CF1[$m['month']], $this->vals($m), $m['month']);
            $this->assertSame(['0.00', '0.00', '0.00'], [$m['funds_received'], $m['transfers_in'], $m['transfers_out']]);
        }
        $this->assertSame(self::CF1_TOTAL, $this->vals($r['total']));
        $this->assertSame('1000000.00', $r['months'][0]['funding_net']);
        $this->assertRowsReconcile($r);

        // Notes and basis (finding 7): eliminations happened; November income is negative (finding 5).
        $this->assertSame(ReportService::CASH_FLOW_BASIS.'; inter-branch movements within the selection eliminated', $r['basis']);
        $this->assertContains('Inter-branch movements within the selection eliminated: 450,000.00', $r['notes']);
        $this->assertContains(ReportService::CASH_FLOW_NEGATIVE_NOTE, $r['notes']);
        $this->assertFalse($r['empty']);
        $this->assertSame('1 Sep 2026 to 30 Nov 2026', $r['period_label']);
    }

    /** AC 2 + AC 19: CF-2 per branch; MAIN and KDY September. */
    public function test_ac2_ac19_single_branch_equals_cf2(): void
    {
        foreach (self::CF2 as $code => $expected) {
            $r = $this->cf('2026-10-01', '2026-10-31', [$code]);
            $this->assertFalse($r['consolidated']);
            $this->assertCount(1, $r['months']);
            $this->assertSame($expected, $this->vals($r['months'][0]), $code);
            $this->assertSame($expected, $this->vals($r['total']), "$code total");
            // One branch: nothing eliminated, so the plain basis and no elimination note.
            $this->assertSame(ReportService::CASH_FLOW_BASIS, $r['basis']);
            $this->assertCount(2, $r['notes']);
            $this->assertRowsReconcile($r);
        }

        $main = $this->cf('2026-09-01', '2026-09-30', ['MAIN'])['months'][0];
        $this->assertSame(['1000000.00', '300000.00', '700000.00'], [$main['capital'], $main['transfers_out'], $main['closing']]);
        $kdy = $this->cf('2026-09-01', '2026-09-30', ['KDY'])['months'][0];
        $this->assertSame(['200000.00', '0.00', '30000.00', '170000.00'], [$kdy['funds_received'], $kdy['transfers_in'], $kdy['expenses'], $kdy['closing']]);
    }

    /** AC 3 + AC 19: subset KDY + GAL equals CF-3; only movements between them are eliminated. */
    public function test_ac3_subset_equals_cf3(): void
    {
        $r = $this->cf('2026-10-01', '2026-10-31', ['KDY', 'GAL']);
        $this->assertFalse($r['consolidated']);
        $this->assertTrue($r['subset']);
        $this->assertSame(self::CF3, $this->vals($r['months'][0]));
        $this->assertContains('Inter-branch movements within the selection eliminated: 30,000.00', $r['notes']);
        // CF-3 closing = KDY + GAL closings of CF-2.
        $this->assertSame(Money::add(self::CF2['KDY'][12], self::CF2['GAL'][12]), $r['months'][0]['closing']);
    }

    /** AC 4: (8) internal and (12) void never change a figure (removing their lines changes nothing); identities hold. */
    public function test_ac4_internal_transfer_and_void_never_count(): void
    {
        $runs = [['2026-09-01', '2026-11-30', []], ['2026-10-01', '2026-10-31', ['KDY']], ['2026-10-01', '2026-10-31', ['GAL']],
            ['2026-10-01', '2026-11-30', ['KDY', 'GAL']], ['2026-09-01', '2026-10-31', ['MAIN', 'KDY']]];
        $before = [];
        foreach ($runs as $i => [$from, $to, $codes]) {
            $before[$i] = $this->cf($from, $to, $codes);
            $this->assertRowsReconcile($before[$i]);
        }

        // (8) and (12) are already in the data (CF-1..CF-3 exclude them). Posting another internal transfer and another
        // voided expense, in a month of every run, must change nothing either (ledger lines are immutable, so add, not remove).
        $this->actingAs($this->admin);
        $ledger = app(LedgerService::class);
        foreach (['2026-10-25', '2026-11-05'] as $date) {
            $ledger->transfer($this->acc($this->kdy, 'Cash')->id, $this->acc($this->kdy, 'Bank')->id, '7000.00', $date, 'Banking');
            $rent = $ledger->incomeOrExpense(Transaction::EXPENSE, ['account_id' => $this->acc($this->gal, 'Cash')->id,
                'category_id' => Category::where('name', 'Rent')->value('id'), 'txn_date' => $date, 'amount' => '900.00']);
            $ledger->void($rent, 'Duplicate');
        }

        foreach ($runs as $i => [$from, $to, $codes]) {
            $after = $this->cf($from, $to, $codes);
            $this->assertSame($before[$i]['months'], $after['months'], "run $i");
            $this->assertSame($before[$i]['total'], $after['total'], "run $i");
        }
    }

    /** AC 5: reversals in the original's column, negated, on the reversal date. */
    public function test_ac5_reversals(): void
    {
        $kdy = $this->cf('2026-10-01', '2026-10-31', ['KDY'])['months'][0];
        $this->assertSame(['8200.00', '100000.00', '0.00'], [$kdy['expenses'], $kdy['funds_received'], $kdy['transfers_in']]);

        $gal = $this->cf('2026-11-01', '2026-11-30', ['GAL']);
        $this->assertSame(['-4000.00', '-4000.00', '-4000.00', '133200.00'],
            [$gal['months'][0]['income'], $gal['months'][0]['inflow'], $gal['months'][0]['net'], $gal['months'][0]['closing']]);
        $this->assertContains(ReportService::CASH_FLOW_NEGATIVE_NOTE, $gal['notes']);

        // The reversal day on its own (10-16): (11) reversed, +10,000 and -10,000 on different days.
        $kdyOct = $this->cf('2026-10-01', '2026-10-31', ['KDY']);
        $this->assertNotContains(ReportService::CASH_FLOW_NEGATIVE_NOTE, $kdyOct['notes']);
    }

    /** AC 6: All mode eliminates every inter-branch movement; October Eliminated = Transfers & Allocations "Net moved". */
    public function test_ac6_all_mode_eliminates_transfers(): void
    {
        $r = $this->cf('2025-12-01', '2026-11-30');
        $this->assertCount(12, $r['months']);
        foreach ($r['months'] as $m) {
            $this->assertSame(['0.00', '0.00', '0.00'], [$m['funds_received'], $m['transfers_in'], $m['transfers_out']], $m['month']);
        }
        $oct = collect($r['months'])->firstWhere('month', '2026-10');
        $netMoved = $this->report('transfers', $this->october())->assertOk()->json('net_moved');
        $this->assertSame('150000.00', $oct['eliminated']);
        $this->assertSame($netMoved, $oct['eliminated']);
    }

    /** AC 7 (R6 test, finding 1/3): per branch and month the figures equal Branch Comparison. */
    public function test_ac7_agrees_with_branch_comparison(): void
    {
        foreach (['MAIN', 'KDY', 'GAL'] as $code) {
            $id = Branch::where('code', $code)->value('id');
            foreach ([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30']] as [$from, $to]) {
                $cf = $this->cf($from, $to, [$code])['months'][0];
                $bc = $this->report('branch-comparison', ['from' => $from, 'to' => $to, 'branches' => [$id]])->assertOk()->json('rows.0');
                foreach (['opening' => 'opening', 'income' => 'income', 'expenses' => 'expenses', 'capital' => 'capital', 'funds_received' => 'funds_received',
                    'transfers_in' => 'transfers_in', 'transfers_out' => 'transfers_out', 'closing' => 'closing'] as $mine => $theirs) {
                    $this->assertSame($bc[$theirs], $cf[$mine], "$code $from $mine");
                }
            }
        }
    }

    /** AC 8 + R6 (a): a money line of a type the report does not classify -> 500, the plain message, a log entry. */
    public function test_ac8_unreconciled_month_is_a_500_without_figures(): void
    {
        $cash = $this->acc($this->kdy, 'Cash');
        $id = DB::table('transactions')->insertGetId(['branch_id' => $this->kdy->id, 'type' => 'adjustment', 'txn_date' => '2026-10-09',
            'amount' => '1.00', 'status' => 'posted', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_entries')->insert(['transaction_id' => $id, 'account_id' => $cash->id, 'branch_id' => $this->kdy->id,
            'entry_date' => '2026-10-09', 'debit' => '1.00', 'credit' => '0.00']);
        Log::spy();

        $res = $this->report('cash-flow', ['from' => '2026-09-01', 'to' => '2026-11-30'])->assertStatus(500);
        $this->assertSame(['message' => ReportService::CASH_FLOW_UNRECONCILED], $res->json());

        Log::shouldHaveReceived('error')->once()->withArgs(function ($msg, $ctx) {
            return $msg === 'Cash flow does not reconcile' && $ctx['check'] === 'closing = ledger' && $ctx['month'] === '2026-10'
                && $ctx['computed'] === '1051500.00' && $ctx['ledger'] === '1051501.00' && $ctx['from'] === '2026-09-01' && $ctx['to'] === '2026-11-30'
                && $ctx['branches'] === ['MAIN', 'GAL', 'KDY'];
        });
        // The export stops the same way (no file, no audit row).
        $this->asAdmin()->postJson('/api/v1/reports/cash-flow/export', ['from' => '2026-09-01', 'to' => '2026-11-30', 'format' => 'xlsx'])
            ->assertStatus(500)->assertExactJson(['message' => ReportService::CASH_FLOW_UNRECONCILED]);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'report.exported')->count());
    }

    /** AC 21 + R6 (b): a one-sided elimination (broken branch pair) -> 500, no figures, logged. */
    public function test_ac21_one_sided_elimination_is_a_500(): void
    {
        $cash = $this->acc($this->kdy, 'Cash');
        $ho = Account::withoutGlobalScopes()->where('branch_id', $this->kdy->id)->where('type', 'head_office')->firstOrFail();
        $id = DB::table('transactions')->insertGetId(['branch_id' => $this->kdy->id, 'related_branch_id' => $this->gal->id, 'type' => Transaction::BRANCH_TRANSFER,
            'txn_date' => '2026-10-21', 'amount' => '500.00', 'status' => 'posted', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        // KDY Cash is credited; the matching GAL money line is missing (the debit sits on a non-money account).
        DB::table('ledger_entries')->insert([
            ['transaction_id' => $id, 'account_id' => $cash->id, 'branch_id' => $this->kdy->id, 'entry_date' => '2026-10-21', 'debit' => '0.00', 'credit' => '500.00'],
            ['transaction_id' => $id, 'account_id' => $ho->id, 'branch_id' => $this->kdy->id, 'entry_date' => '2026-10-21', 'debit' => '500.00', 'credit' => '0.00'],
        ]);
        Log::spy();

        $this->report('cash-flow', ['from' => '2026-09-01', 'to' => '2026-11-30'])->assertStatus(500)
            ->assertExactJson(['message' => ReportService::CASH_FLOW_UNRECONCILED]);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($msg, $ctx) => $ctx['check'] === 'eliminated debits = eliminated credits'
            && $ctx['month'] === '2026-10' && $ctx['computed'] === '150000.00' && $ctx['ledger'] === '150500.00');

        // For KDY alone the same transfer is a plain Transfers out and the report still ties to the ledger.
        $this->assertSame('30500.00', $this->cf('2026-10-01', '2026-10-31', ['KDY'])['months'][0]['transfers_out']);
    }

    /** AC 9: the last closing equals the money accounts' current balances. */
    public function test_ac9_closing_equals_current_balances(): void
    {
        $r = $this->cf('2026-09-01', '2026-11-30');
        $money = Account::withoutGlobalScopes()->whereIn('type', Account::MONEY_TYPES)->get();
        $this->assertSame('1047500.00', $r['total']['closing']);
        $this->assertSame($r['total']['closing'], Money::add(...$money->pluck('current_balance')->all()));
        $this->assertSame(['620000.00', '254300.00', '40000.00', '133200.00'],
            [$this->balance($this->mainAcc), $this->balance($this->acc($this->kdy, 'Cash')), $this->balance($this->acc($this->kdy, 'Bank')), $this->balance($this->acc($this->gal, 'Cash'))]);

        $kdy = $this->cf('2026-10-01', '2026-10-31', ['KDY'])['months'][0]['closing'];
        $this->assertSame('294300.00', $kdy);
        $this->assertSame($kdy, Money::add($this->balance($this->acc($this->kdy, 'Cash')), $this->balance($this->acc($this->kdy, 'Bank'))));
    }

    /** AC 12 + AC 22 (finding 6a): whole months, no future months, at most 24 months. */
    public function test_ac12_ac22_range_rules(): void
    {
        $this->report('cash-flow', ['from' => '2026-10-02', 'to' => '2026-10-31'])->assertStatus(422)
            ->assertJsonPath('message', ReportService::CASH_FLOW_WHOLE_MONTHS);
        $this->report('cash-flow', ['from' => '2026-10-01', 'to' => '2026-10-30'])->assertStatus(422)
            ->assertJsonPath('message', ReportService::CASH_FLOW_WHOLE_MONTHS);
        $this->report('cash-flow', ['from' => '2026-12-01', 'to' => '2026-12-31'])->assertStatus(422)
            ->assertJsonPath('message', 'The report cannot cover future months.');
        $this->report('cash-flow', ['from' => '2026-10-01', 'to' => '2026-12-31'])->assertStatus(422)
            ->assertJsonPath('message', 'The report cannot cover future months.');
        $this->report('cash-flow', ['from' => '2024-11-01', 'to' => '2026-11-30'])->assertStatus(422)
            ->assertJsonPath('message', 'The range may not be longer than 24 months.');
        $this->report('cash-flow', ['from' => '2026-11-01', 'to' => '2026-10-31'])->assertStatus(422);
        // February in a leap year: the 29th is its last day.
        $this->report('cash-flow', ['from' => '2024-02-01', 'to' => '2024-02-29'])->assertOk();

        $r = $this->cf('2024-12-01', '2026-11-30');
        $this->assertCount(24, $r['months']);
        $this->assertSame(['2024-12', '2026-11'], [$r['months'][0]['month'], $r['months'][23]['month']]);
        $this->assertSame('1047500.00', $r['total']['closing']);
        // Finding 6c: a range over several fiscal years names them.
        $this->assertSame('Dec 2024 - Nov 2026 (FY 2024-25, FY 2025-26 and FY 2026-27 to date)', $r['period_label']);
    }

    /** CF-4 / AC 11 (server part): the default range lists every month, empty months as zero; a full FY is labelled. */
    public function test_cf4_default_range_lists_every_month(): void
    {
        $r = $this->cf('2026-04-01', '2026-11-30');
        $this->assertSame(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_column($r['months'], 'month'));
        foreach (array_slice($r['months'], 0, 5) as $m) {
            $this->assertSame(array_fill(0, 14, '0.00'), $this->vals($m), $m['month']);
        }
        foreach (array_slice($r['months'], 5) as $m) {
            $this->assertSame(self::CF1[$m['month']], $this->vals($m));
        }
        $this->assertFalse($r['empty']);

        $empty = $this->cf('2026-04-01', '2026-08-31');
        $this->assertTrue($empty['empty']);
        $this->assertSame(['0.00', '0.00'], [$empty['total']['opening'], $empty['total']['closing']]);

        $this->assertSame('FY 2025-26, 1 Apr 2025 to 31 Mar 2026', $this->cf('2025-04-01', '2026-03-31')['period_label']);
    }

    /** AC 23 (finding 6b): the cumulative closing runs on across a fiscal-year start. */
    public function test_ac23_no_reset_at_fiscal_year_start(): void
    {
        $r = $this->cf('2026-03-01', '2026-05-31');
        $this->assertSame($r['months'][0]['closing'], $r['months'][1]['opening']);

        // With the fiscal year starting in October the boundary falls inside the E-dataset.
        Setting::put('fiscal_year_start_month', '10');
        $r = $this->cf('2026-09-01', '2026-11-30');
        $this->assertSame(['970000.00', '970000.00'], [$r['months'][0]['closing'], $r['months'][1]['opening']]);
        $this->assertSame(self::CF1_TOTAL, $this->vals($r['total']));
        $this->assertSame('Sep 2026 - Nov 2026 (FY 2025-26 and FY 2026-27 to date)', $r['period_label']);
    }

    /** AC 13: a branch user sees the active branch only and may not ask for another. */
    public function test_ac13_branch_user(): void
    {
        $acct = $this->userIn($this->kdy, 'Accountant');
        $r = $this->actingAs($acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/cash-flow?from=2026-10-01&to=2026-10-31')->assertOk()->json();
        $this->assertSame(['KDY'], array_column($r['branches'], 'code'));
        $this->assertSame(self::CF2['KDY'], $this->vals($r['months'][0]));

        $this->actingAs($acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/cash-flow?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31', 'branches' => [$this->gal->id]]))
            ->assertStatus(403)->assertJsonPath('message', 'You do not have access to branch GAL.');
    }

    /** AC 14: Cashier has no access; Viewer can view but not export. */
    public function test_ac14_permissions(): void
    {
        $q = 'from=2026-10-01&to=2026-10-31';
        $cashier = $this->userIn($this->kdy, 'Cashier');
        $this->actingAs($cashier)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson("/api/v1/reports/cash-flow?$q")->assertForbidden();

        $viewer = $this->userIn($this->kdy, 'Viewer');
        $this->actingAs($viewer)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson("/api/v1/reports/cash-flow?$q")->assertOk();
        $this->actingAs($viewer)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->postJson('/api/v1/reports/cash-flow/export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'pdf'])->assertForbidden();
    }

    /** AC 18: an inactive branch keeps its history in All mode. */
    public function test_ac18_inactive_branch_included(): void
    {
        $this->asAdmin()->putJson("/api/v1/branches/{$this->gal->id}", ['status' => 'inactive'])->assertOk();
        $r = $this->cf('2026-09-01', '2026-11-30');
        $this->assertSame('inactive', collect($r['branches'])->firstWhere('code', 'GAL')['status']);
        $this->assertSame(self::CF1_TOTAL, $this->vals($r['total']));
        $this->assertSame(self::CF2['GAL'], $this->vals($this->cf('2026-10-01', '2026-10-31', ['GAL'])['months'][0]));
    }

    /** R8: read-only - nothing posts and no balance changes. */
    public function test_report_is_read_only(): void
    {
        $before = [DB::table('ledger_entries')->count(), DB::table('transactions')->count(), DB::table('accounts')->orderBy('id')->pluck('current_balance')->all()];
        $this->cf('2026-09-01', '2026-11-30');
        $this->cf('2026-10-01', '2026-10-31', ['KDY', 'GAL']);
        $this->assertSame($before, [DB::table('ledger_entries')->count(), DB::table('transactions')->count(), DB::table('accounts')->orderBy('id')->pluck('current_balance')->all()]);
    }
}
