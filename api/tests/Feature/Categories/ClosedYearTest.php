<?php

namespace Tests\Feature\Categories;

use App\Models\Account;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Support\Money;
use Tests\Feature\YearEnd\YearEndTestCase;

/**
 * Categories review findings 4 and 5 on the year-end Y-dataset ("today" 2027-04-10): the year-end close and reopen post
 * to inactive category accounts (AC 48d), and a promote after the close changes no trial balance (pre- or post-closing,
 * consolidated or by branch), no I&E and passes ledger:verify (AC 49).
 */
class ClosedYearTest extends YearEndTestCase
{
    private const FY = ['from' => '2026-04-01', 'to' => '2027-03-31'];

    private function figures(): array
    {
        $get = function (string $report, array $q) {
            $r = $this->sa()->getJson("/api/v1/reports/{$report}?".http_build_query($q))->assertOk()->json();
            unset($r['generated_at']);

            return $r;
        };

        return [
            'tb_pre' => $get('trial-balance', self::FY),
            'tb_pre_c' => $get('trial-balance', self::FY + ['view' => 'consolidated']),
            'tb_post' => $get('trial-balance', self::FY + ['closing_entries' => 1]),
            'tb_post_c' => $get('trial-balance', self::FY + ['view' => 'consolidated', 'closing_entries' => 1]),
            'tb_april_c' => $get('trial-balance', ['from' => '2027-04-01', 'to' => '2027-04-10', 'view' => 'consolidated']),
            'ie_fy' => $get('income-expense', self::FY),
            'ie_kdy' => $this->sa($this->kdy)->getJson('/api/v1/reports/income-expense?'.http_build_query(self::FY))->assertOk()->json(),
        ];
    }

    private function net(Account $a, string $to): int
    {
        return (int) LedgerEntry::where('account_id', $a->id)->whereDate('entry_date', '<=', $to)->get()
            ->sum(fn ($l) => Money::toCents($l->debit) - Money::toCents($l->credit));
    }

    public function test_close_posts_to_inactive_accounts_and_a_later_promote_changes_no_figure(): void
    {
        $kba = $this->userIn($this->kdy, 'Branch Admin');
        $tuk = Category::find($this->as($kba, $this->kdy)->postJson('/api/v1/categories', ['name' => 'Tuk-tuk hire',
            'parent_id' => Category::where('name', 'Transport')->value('id')])->assertCreated()->json('id'));
        $this->expense($this->kdy, $this->acc($this->kdy, 'Cash'), 'Tuk-tuk hire', '2027-01-15', '1000.00');

        // AC 48d: Utilities (Electricity KDY 8,200.00, Water GAL 1,800.00) inactive before the close.
        $ut = Category::whereNull('parent_id')->where('name', 'Utilities')->sole();
        $this->sa()->putJson("/api/v1/categories/{$ut->id}", ['is_active' => false])->assertOk();
        $el = Account::withoutGlobalScopes()->where('branch_id', $this->kdy->id)->where('category_id', Category::where('name', 'Electricity')->value('id'))->sole();
        $this->assertSame(['inactive', 820000], [$el->status, $this->net($el, '2027-03-31')]);

        $this->closeY();
        $this->assertContains('KDY Cr Utilities > Electricity 8200.00', $this->closingLines());
        $this->assertContains('GAL Cr Utilities > Water 1800.00', $this->closingLines());
        $this->assertSame(0, $this->net($el, '2027-03-31'));
        $this->artisan('ledger:verify')->assertExitCode(0);

        // AC 49: promote after the close.
        $before = $this->figures();
        $this->assertCount(1, collect($before['tb_pre_c']['rows'])->where('key', 'cat:'.$tuk->ledger_code));
        $entries = LedgerEntry::count();
        $this->sa()->postJson("/api/v1/categories/{$tuk->id}/promote")->assertOk();
        $this->assertSame($entries, LedgerEntry::count());
        $this->assertSame($before, $this->figures());
        $this->artisan('ledger:verify')->assertExitCode(0);

        // The reopen also posts its contra lines on the inactive account; the books still verify.
        $this->reopen()->assertOk();
        $this->assertSame(820000, $this->net($el, '2027-03-31'));
        $this->artisan('ledger:verify')->assertExitCode(0);
    }
}
