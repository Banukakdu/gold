<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\Money;

/** AG-082 Income & Expense by Category > Sub-category. */
class IncomeExpenseReportTest extends ReportTestCase
{
    /** AC 1: E-1 (All) exactly. */
    public function test_ac1_october_all_branches_equals_e1(): void
    {
        $r = $this->report('income-expense', $this->october())->assertOk()->json();

        $this->assertSame('91500.00', $r['income']['total']);
        $this->assertSame([
            ['Sales', '79000.00', '86.3', [['Retail', '79000.00', '86.3']]],
            ['Service income', '12500.00', '13.7', [['(no sub-category)', '12500.00', '13.7']]],
        ], $this->tree($r['income']));
        $this->assertNull($r['income']['categories'][1]['subs'][0]['id']);

        $this->assertSame('10000.00', $r['expense']['total']);
        $this->assertSame([
            ['Utilities', '10000.00', '100.0', [['Electricity', '8200.00', '82.0'], ['Water', '1800.00', '18.0']]],
        ], $this->tree($r['expense']));
        $this->assertSame('81500.00', $r['net']);
        $this->assertTrue($r['all_branches']);
        $this->assertCount(3, $r['branches']);
    }

    /** AC 2 */
    public function test_ac2_single_branch_filters(): void
    {
        $kdy = $this->report('income-expense', $this->october(['branches' => [$this->kdy->id]]))->assertOk()->json();
        $this->assertSame('62500.00', $kdy['income']['total']);
        $this->assertSame('8200.00', $kdy['expense']['total']);
        $this->assertSame('54300.00', $kdy['net']);
        $this->assertSame(['Retail', '50000.00', '80.0'], $this->tree($kdy['income'])[0][3][0]);
        $this->assertSame('20.0', $kdy['income']['categories'][1]['percent']);
        $this->assertFalse($kdy['all_branches']);
        $this->assertTrue($kdy['subset']);

        $gal = $this->report('income-expense', $this->october(['branches' => [$this->gal->id]]))->assertOk()->json();
        $this->assertSame('29000.00', $gal['income']['total']);
        $this->assertSame('1800.00', $gal['expense']['total']);
        $this->assertSame('27200.00', $gal['net']);
    }

    /** AC 3 + AC 5 (I&E part): transfers, capital and voided entries never appear. */
    public function test_ac3_ac5_only_income_and_expense_count(): void
    {
        $json = $this->report('income-expense', $this->october())->assertOk()->getContent();
        foreach (['100000.00', '30000.00', '40000.00', '20000.00', '15000.00', '"Rent"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $sept = $this->report('income-expense', ['from' => '2026-09-01', 'to' => '2026-09-30'])->json();
        $this->assertSame('0.00', $sept['income']['total']); // capital + allocations are not income
        $this->assertSame([['Rent', '30000.00', '100.0', [['(no sub-category)', '30000.00', '100.0']]]], $this->tree($sept['expense']));
    }

    /** AC 4: reversal counts on its own date in the original's line, negative. */
    public function test_ac4_reversal_by_period(): void
    {
        $elec = fn (array $q) => collect($this->report('income-expense', $q)->assertOk()->json('expense.categories'))
            ->flatMap(fn ($c) => $c['subs'])->firstWhere('name', 'Electricity')['amount'] ?? null;

        $this->assertSame('8200.00', $elec($this->october()));
        $this->assertSame('5000.00', $elec(['from' => '2026-10-12', 'to' => '2026-10-13']));
        $this->assertSame('-5000.00', $elec(['from' => '2026-10-14', 'to' => '2026-10-14']));
    }

    /** AC 6 + E-1 November: negative total -> percent null. A line that nets to 0.00 still shows. */
    public function test_ac6_cross_period_reversal_gives_negative_total(): void
    {
        $r = $this->report('income-expense', ['from' => '2026-11-01', 'to' => '2026-11-30', 'branches' => [$this->gal->id]])->assertOk()->json();
        $this->assertSame('-4000.00', $r['income']['total']);
        $this->assertSame([['Sales', '-4000.00', null, [['Retail', '-4000.00', null]]]], $this->tree($r['income']));

        $both = $this->report('income-expense', ['from' => '2026-10-30', 'to' => '2026-11-02', 'branches' => [$this->gal->id]])->json();
        $this->assertSame([['Sales', '0.00', null, [['Retail', '0.00', null]]]], $this->tree($both['income']));
    }

    /** AC 34 + finding 9: negative lines with a positive total, rounded half away from zero. */
    public function test_ac34_negative_line_percent(): void
    {
        $this->actingAs($this->admin);
        $service = Category::whereNull('parent_id')->where('name', 'Service income')->first();
        app(LedgerService::class)->incomeOrExpense(Transaction::INCOME, [
            'account_id' => $this->acc($this->gal, 'Cash')->id, 'category_id' => $service->id, 'txn_date' => '2026-11-05', 'amount' => '12500.00']);

        $r = $this->report('income-expense', ['from' => '2026-11-01', 'to' => '2026-11-30', 'branches' => [$this->gal->id]])->json();
        $this->assertSame('8500.00', $r['income']['total']);
        $this->assertSame([
            ['Service income', '12500.00', '147.1', [['(no sub-category)', '12500.00', '147.1']]],
            ['Sales', '-4000.00', '-47.1', [['Retail', '-4000.00', '-47.1']]],
        ], $this->tree($r['income']));
    }

    public function test_percent_rounding_is_half_away_from_zero(): void
    {
        $this->assertSame('-0.1', ReportService::percent(-5, 10000));   // -0.05 %
        $this->assertSame('0.1', ReportService::percent(5, 10000));     // 0.05 %
        $this->assertSame('0.0', ReportService::percent(4, 10000));
        $this->assertSame('33.3', ReportService::percent(1, 3));
        $this->assertSame('66.7', ReportService::percent(2, 3));
        $this->assertNull(ReportService::percent(5, 0));
        $this->assertNull(ReportService::percent(5, -1));
    }

    /** AC 25: 0.10 + 0.20 is exactly 0.30 (screen; exports in ReportExportTest). */
    public function test_ac25_cents_are_exact(): void
    {
        $this->actingAs($this->admin);
        $retail = Category::where('name', 'Retail')->first();
        foreach (['0.10', '0.20'] as $amt) {
            app(LedgerService::class)->incomeOrExpense(Transaction::INCOME, [
                'account_id' => $this->acc($this->kdy, 'Cash')->id, 'category_id' => $retail->id, 'txn_date' => '2026-11-10', 'amount' => $amt]);
        }
        $r = $this->report('income-expense', ['from' => '2026-11-10', 'to' => '2026-11-10'])->json();
        $this->assertSame('0.30', $r['income']['total']);
        $this->assertSame('0.30', $r['income']['categories'][0]['subs'][0]['amount']);
    }

    public function test_type_filter_and_inactive_category_tag(): void
    {
        Category::where('name', 'Water')->update(['is_active' => false]);
        $r = $this->report('income-expense', $this->october(['type' => 'expense']))->assertOk()->json();
        $this->assertNull($r['income']);
        $this->assertNull($r['net']);
        $this->assertFalse($r['expense']['categories'][0]['subs'][1]['is_active']);
        $this->report('income-expense', $this->october(['type' => 'transfers']))->assertStatus(422);
    }

    /** AC 7: branch user sees own branch only; naming another branch is 403. */
    public function test_ac7_branch_user_is_limited_to_active_branch(): void
    {
        $acct = $this->userIn($this->kdy, 'Accountant');
        $h = ['X-Branch-Id' => (string) $this->kdy->id];
        $r = $this->actingAs($acct)->withHeaders($h)->getJson('/api/v1/reports/income-expense?'.http_build_query($this->october()))->assertOk()->json();
        $this->assertSame(['62500.00', '8200.00', '54300.00'], [$r['income']['total'], $r['expense']['total'], $r['net']]);
        $this->actingAs($acct)->withHeaders($h)->getJson('/api/v1/reports/income-expense?'.http_build_query($this->october(['branches' => [$this->kdy->id]])))->assertOk();

        $this->actingAs($acct)->withHeaders($h)->getJson('/api/v1/reports/income-expense?'.http_build_query($this->october(['branches' => [$this->gal->id]])))
            ->assertForbidden()->assertJsonPath('message', 'You do not have access to branch GAL.');
        // Super Admin in branch mode follows the same rule.
        $this->report('income-expense', $this->october(['branches' => [$this->gal->id]]), $this->kdy->id)
            ->assertForbidden()->assertJsonPath('message', 'You do not have access to branch GAL.');
        // No branch header for a normal user: 400.
        $this->flushHeaders()->actingAs($acct)->getJson('/api/v1/reports/income-expense?'.http_build_query($this->october()))->assertStatus(400);
    }

    /** AC 10 (API part) + spec E1: drill-down into /transactions. */
    public function test_ac10_drill_down_lists_the_entries_behind_an_amount(): void
    {
        $retail = Category::where('name', 'Retail')->first();
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'income,reversal', 'category_id' => $retail->id, 'category_only' => 1,
            'branches' => [$this->main->id, $this->kdy->id, $this->gal->id], 'per_page' => 100];
        $rows = collect($this->asAdmin()->getJson('/api/v1/transactions?'.http_build_query($q))->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$this->txn(1)->id, $this->txn(5)->id, $this->txn(13)->id], $rows->pluck('id')->all());
        $this->assertSame(7900000, $rows->sum(fn ($t) => Money::toCents($t['amount'])));

        $service = Category::whereNull('parent_id')->where('name', 'Service income')->first();
        $rows = $this->asAdmin()->getJson('/api/v1/transactions?'.http_build_query(['category_id' => $service->id, 'category_only' => 1, 'type' => 'income,reversal'] + $q))->json('data');
        $this->assertSame([$this->txn(2)->id], array_column($rows, 'id'));

        // Without category_only the parent still includes its children (unchanged behaviour).
        $sales = Category::whereNull('parent_id')->where('name', 'Sales')->first();
        $this->assertCount(3, $this->asAdmin()->getJson('/api/v1/transactions?'.http_build_query(['category_id' => $sales->id, 'category_only' => 0] + $q))->json('data'));

        // Reversal rows carry "Reversal of <ref>"; branches[] narrows; branch mode may not name another branch.
        $nov = ['from' => '2026-11-01', 'to' => '2026-11-30', 'type' => 'income,reversal', 'category_id' => $retail->id, 'category_only' => 1, 'branches' => [$this->gal->id]];
        $rev = $this->asAdmin()->getJson('/api/v1/transactions?'.http_build_query($nov))->json('data');
        $this->assertSame('Reversal of '.$this->txn(13)->ref_no, $rev[0]['description']);
        $this->assertSame(1, $this->asAdmin()->getJson('/api/v1/transactions?'.http_build_query(['branches' => [$this->kdy->id]] + array_diff_key($q, ['branches' => 1])))->json('total'));
        $acct = $this->userIn($this->kdy, 'Accountant');
        $this->actingAs($acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions?'.http_build_query(['branches' => [$this->gal->id]]))
            ->assertForbidden()->assertJsonPath('message', 'You do not have access to branch GAL.');
    }

    /** @return array<int, array{0:string,1:string,2:?string,3:array}> */
    private function tree(array $section): array
    {
        return array_map(fn ($c) => [$c['name'], $c['amount'], $c['percent'],
            array_map(fn ($s) => [$s['name'], $s['amount'], $s['percent']], $c['subs'])], $section['categories']);
    }
}
