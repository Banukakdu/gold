<?php

namespace Tests\Feature\Categories;

use PhpOffice\PhpSpreadsheet\IOFactory;

/** Categories review finding 8 (SHOULD): the I&E report shows the ledger code and a "<code> only" tag on branch-only subs. */
class ReportCodeTest extends CategoriesTestCase
{
    public function test_ie_shows_codes_and_branch_tags_when_several_branches_are_selected(): void
    {
        $kdy = $this->tukTuk();
        $gal = $this->tukTuk($this->gal);
        $this->spend($kdy, '2026-11-05', '100.00');
        $this->spend($gal, '2026-11-06', '70.00', $this->gal);
        $q = 'from=2026-11-01&to=2026-11-30';

        $transport = collect($this->sa()->getJson("/api/v1/reports/income-expense?{$q}")->assertOk()->json('expense.categories'))->firstWhere('name', 'Transport');
        $this->assertSame($this->cat('Transport')->ledger_code, $transport['code']);
        $subs = collect($transport['subs'])->keyBy('code');
        $this->assertSame(['Tuk-tuk hire', 'KDY'], [$subs[$kdy->ledger_code]['name'], $subs[$kdy->ledger_code]['branch_only']]);
        $this->assertSame(['Tuk-tuk hire', 'GAL'], [$subs[$gal->ledger_code]['name'], $subs[$gal->ledger_code]['branch_only']]);

        $one = collect($this->sa($this->kdy)->getJson("/api/v1/reports/income-expense?{$q}")->json('expense.categories'))->firstWhere('name', 'Transport');
        $this->assertNull($one['subs'][0]['branch_only']);

        $res = $this->sa()->postJson('/api/v1/reports/income-expense/export', ['from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'xlsx'])->assertOk();
        $ws = IOFactory::createReader('Xlsx')->load($res->getFile()->getPathname())->getSheetByName('Expenses');
        $rows = [];
        foreach ($ws->getRowIterator() as $row) {
            $rows[] = [$ws->getCell([2, $row->getRowIndex()])->getValue(), $ws->getCell([5, $row->getRowIndex()])->getValue()];
        }
        $this->assertContains(['Tuk-tuk hire (KDY only)', $kdy->ledger_code], $rows);
        $this->assertContains(['Tuk-tuk hire (GAL only)', $gal->ledger_code], $rows);
    }
}
