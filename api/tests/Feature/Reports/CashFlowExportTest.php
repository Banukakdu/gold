<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Support\Arr;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** AG-086 PDF / Excel export: ACs 16, 17, 24 (review finding 7). */
class CashFlowExportTest extends CashFlowTestCase
{
    private User $acct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acct = $this->userIn($this->kdy, 'Accountant');
    }

    private function export(array $q, string $format, ?User $user = null, string|int|null $branch = null)
    {
        return $this->actingAs($user ?? $this->acct)->withHeader('X-Branch-Id', (string) ($branch ?? $this->kdy->id))
            ->postJson('/api/v1/reports/cash-flow/export', $q + ['format' => $format]);
    }

    /** Head row index of the "Cash flow" sheet and the data rows under it (Month label => [column label => formatted value]). */
    private function sheetRows(Worksheet $ws): array
    {
        $head = null;
        foreach ($ws->getRowIterator() as $row) {
            if ($ws->getCell([1, $row->getRowIndex()])->getValue() === 'Month') {
                $head = $row->getRowIndex();
                break;
            }
        }
        $this->assertNotNull($head);
        $labels = [];
        for ($c = 1; ($v = $ws->getCell([$c, $head])->getValue()) !== null && $v !== ''; $c++) {
            $labels[$c] = $v;
        }
        $rows = [];
        for ($r = $head + 1; ($first = $ws->getCell([1, $r])->getFormattedValue()) !== ''; $r++) {
            foreach ($labels as $c => $label) {
                $rows[$first][$label] = $ws->getCell([$c, $r])->getFormattedValue();
            }
        }

        return [$head, $labels, $rows];
    }

    /** AC 16: file names, the screen's rows and totals, the common header, the audit row. */
    public function test_ac16_accountant_exports_pdf_and_xlsx(): void
    {
        $q = ['from' => '2026-09-01', 'to' => '2026-11-30'];
        $screen = $this->actingAs($this->acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/cash-flow?'.http_build_query($q))->assertOk()->json();
        $header = ['Account-gold', 'Cash Flow Summary', 'Branches: KDY Kandy', 'Period: 1 Sep 2026 to 30 Nov 2026', 'Amounts in LKR',
            "Printed by {$this->acct->name} at ", 'PROVISIONAL - period not locked'];

        // Excel
        $res = $this->export($q, 'xlsx')->assertOk();
        $this->assertSame('attachment; filename="cash-flow_2026-09-01_2026-11-30.xlsx"', $res->headers->get('Content-Disposition'));
        $book = $this->xlsx($res);
        $this->assertSame(['Cash flow'], $book->getSheetNames());
        $ws = $book->getSheet(0);
        $top = implode("\n", array_map(fn ($r) => (string) $ws->getCell([1, $r])->getValue(), range(1, 9)));
        foreach ([...$header, ReportService::CASH_FLOW_BASIS] as $line) {
            $this->assertStringContainsString($line, $top);
        }
        [$head, $labels, $rows] = $this->sheetRows($ws);
        $this->assertSame('A'.($head + 1), $ws->getFreezePane());
        $this->assertSame(['Month', ...array_values(ReportExporter::CASH_FLOW_COLUMNS)], array_values($labels));
        $this->assertSame(['Sep 2026', 'Oct 2026', 'Nov 2026', 'Total'], array_keys($rows));
        foreach ([...$screen['months'], $screen['total']] as $i => $m) {
            $label = array_keys($rows)[$i];
            foreach (ReportExporter::CASH_FLOW_COLUMNS as $k => $col) {
                $this->assertSame(Money::format($m[$k]), $rows[$label][$col], "$label $col");
            }
        }

        // PDF
        $res = $this->export($q, 'pdf')->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="cash-flow_2026-09-01_2026-11-30.pdf"', $res->headers->get('Content-Disposition'));
        $pages = $this->pdfPages($res->getContent());
        $this->assertCount(1, $pages);
        foreach ([...$header, 'Page 1 of 1', 'Sep 2026', 'Nov 2026', 'ELIMINATED'] as $line) {
            $this->assertStringContainsString($line, $pages[0]);
        }
        foreach ([$screen['total'], ...$screen['months']] as $m) {
            foreach (['closing', 'net', 'operating_net', 'funding_net'] as $k) {
                $this->assertStringContainsString(Money::format($m[$k]), $pages[0]);
            }
        }
        $this->assertStringNotContainsString('Prepared by', $pages[0]); // finding 7: no signature block

        $logs = AuditLog::where('event', 'report.exported')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        // MySQL JSON columns do not keep key order: compare key-sorted arrays.
        $this->assertSame(Arr::sortRecursive(['name' => 'cash-flow', 'format' => 'pdf', 'rows' => 3]),
            Arr::sortRecursive(array_intersect_key($logs->last()->new_values, array_flip(['name', 'format', 'rows']))));
        $this->assertSame(Arr::sortRecursive(['from' => '2026-09-01', 'to' => '2026-11-30', 'branches' => [$this->kdy->id]]),
            Arr::sortRecursive($logs->last()->new_values['params']));
        $this->assertSame([$this->acct->id, $this->kdy->id], [$logs->last()->user_id, $logs->last()->branch_id]);
    }

    /** AC 24 (finding 7): CF-1 in both formats carries Eliminated and the extended basis line; Month is a date cell. */
    public function test_ac24_consolidated_export_has_eliminated_column(): void
    {
        $q = ['from' => '2026-09-01', 'to' => '2026-11-30'];
        $basis = ReportService::CASH_FLOW_BASIS.ReportService::CASH_FLOW_BASIS_ELIMINATED;

        $ws = $this->xlsx($this->export($q, 'xlsx', $this->admin, 'all')->assertOk())->getSheet(0);
        $this->assertStringContainsString($basis, implode("\n", array_map(fn ($r) => (string) $ws->getCell([1, $r])->getValue(), range(1, 9))));
        [$head, $labels, $rows] = $this->sheetRows($ws);
        $this->assertSame('Eliminated', end($labels));
        $this->assertSame(['300,000.00', '150,000.00', '0.00', '450,000.00'], array_column(array_values($rows), 'Eliminated'));
        $this->assertSame(['970,000.00', '1,051,500.00', '1,047,500.00', '1,047,500.00'], array_column(array_values($rows), 'Closing (cumulative)'));
        $this->assertSame('-4,000.00', $rows['Nov 2026']['Income received']); // signed numeric
        // Month: a real date cell (1st of the month) formatted mmm yyyy; amounts numeric #,##0.00.
        $this->assertSame('n', $ws->getCell([1, $head + 1])->getDataType());
        $this->assertSame('mmm yyyy', $ws->getCell([1, $head + 1])->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('2026-09-01', Date::excelToDateTimeObject($ws->getCell([1, $head + 1])->getValue())->format('Y-m-d'));
        $this->assertSame('n', $ws->getCell([2, $head + 2])->getDataType());
        $this->assertSame('#,##0.00', $ws->getCell([2, $head + 2])->getStyle()->getNumberFormat()->getFormatCode());
        $notes = $this->notesBelow($ws, $head + 5);
        $this->assertContains('Inter-branch movements within the selection eliminated: 450,000.00', $notes);
        $this->assertContains(ReportService::CASH_FLOW_NEGATIVE_NOTE, $notes);

        $pdf = implode(' ', $this->pdfPages($this->export($q, 'pdf', $this->admin, 'all')->assertOk()->getContent()));
        foreach (['ELIMINATED', '300,000.00', '150,000.00', '450,000.00', 'inter-branch movements within the selection eliminated', 'Branches: All branches'] as $text) {
            $this->assertStringContainsString($text, $pdf);
        }
    }

    /** AC 24 (second part, finding 5): GAL November shows the negative-amount note in both exports. */
    public function test_ac24_negative_note_for_gal_november(): void
    {
        $gal = $this->userIn($this->gal, 'Accountant');
        $q = ['from' => '2026-11-01', 'to' => '2026-11-30'];

        $ws = $this->xlsx($this->export($q, 'xlsx', $gal, $this->gal->id)->assertOk())->getSheet(0);
        [$head, , $rows] = $this->sheetRows($ws);
        $this->assertSame(['-4,000.00', '133,200.00'], [$rows['Nov 2026']['Income received'], $rows['Nov 2026']['Closing (cumulative)']]);
        $this->assertContains(ReportService::CASH_FLOW_NEGATIVE_NOTE, $this->notesBelow($ws, $head + 2));

        $pdf = implode(' ', $this->pdfPages($this->export($q, 'pdf', $gal, $this->gal->id)->assertOk()->getContent()));
        $this->assertStringContainsString('-4,000.00', $pdf);
        $this->assertStringContainsString('Negative amounts are reversals', $pdf);

        // KDY October has no negative figure: no note.
        $ws = $this->xlsx($this->export(['from' => '2026-10-01', 'to' => '2026-10-31'], 'xlsx')->assertOk())->getSheet(0);
        $this->assertNotContains(ReportService::CASH_FLOW_NEGATIVE_NOTE, $this->notesBelow($ws, 1));
    }

    /** AC 17: 0.10 + 0.20 is exactly 0.30 on screen and in both exports. */
    public function test_ac17_exact_cents(): void
    {
        $this->actingAs($this->admin);
        $retail = Category::where('name', 'Retail')->value('id');
        foreach (['0.10', '0.20'] as $amount) {
            app(LedgerService::class)->incomeOrExpense(Transaction::INCOME, ['account_id' => $this->acc($this->kdy, 'Cash')->id,
                'category_id' => $retail, 'txn_date' => '2026-11-03', 'amount' => $amount]);
        }
        $q = ['from' => '2026-11-01', 'to' => '2026-11-30'];
        $screen = $this->actingAs($this->acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/cash-flow?'.http_build_query($q))->assertOk()->json('months.0');
        $this->assertSame(['0.30', '294300.30'], [$screen['income'], $screen['closing']]);

        [, , $rows] = $this->sheetRows($this->xlsx($this->export($q, 'xlsx')->assertOk())->getSheet(0));
        $this->assertSame(['0.30', '294,300.30'], [$rows['Nov 2026']['Income received'], $rows['Nov 2026']['Closing (cumulative)']]);
        $pdf = implode(' ', $this->pdfPages($this->export($q, 'pdf')->assertOk()->getContent()));
        $this->assertStringContainsString('294,300.30', $pdf);
        $this->assertMatchesRegularExpression('/(?<![\d,])0\.30/', $pdf);
    }

    /** Review m6: the 24-month maximum (15 columns) fits one landscape PDF page with every month and the total. */
    public function test_pdf_at_24_months_is_one_page(): void
    {
        $pages = $this->pdfPages($this->export(['from' => '2024-12-01', 'to' => '2026-11-30'], 'pdf', $this->admin, 'all')->assertOk()->getContent());
        $this->assertCount(1, $pages);
        foreach (['Dec 2024', 'Nov 2026', 'Total', '1,047,500.00', '450,000.00', 'Page 1 of 1', 'Dec 2024 - Nov 2026 (FY 2024-25, FY 2025-26 and FY 2026-27 to date)'] as $text) {
            $this->assertStringContainsString($text, $pages[0]);
        }
    }

    /** Text cells of column A below a row (the sheet notes). */
    private function notesBelow(Worksheet $ws, int $from): array
    {
        $out = [];
        for ($r = $from; $r <= $ws->getHighestRow(); $r++) {
            $out[] = (string) $ws->getCell([1, $r])->getValue();
        }

        return $out;
    }
}
