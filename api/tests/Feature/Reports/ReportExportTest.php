<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PeriodLock;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\ReportExporter;
use App\Services\ReportService;
use Database\Seeders\ReportExampleSeeder;
use Illuminate\Support\Facades\DB;

/** PDF / Excel export (spec section 5, accounting review findings 4, 5, 7). */
class ReportExportTest extends ReportTestCase
{
    private User $acct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acct = $this->userIn($this->kdy, 'Accountant');
    }

    private function export(string $name, array $q, string $format, ?User $user = null, string|int|null $branch = null)
    {
        return $this->actingAs($user ?? $this->acct)->withHeader('X-Branch-Id', (string) ($branch ?? $this->kdy->id))
            ->postJson("/api/v1/reports/{$name}/export", $q + ['format' => $format]);
    }

    /** AC 21 + AC 29: every report in both formats, file name, totals, header lines, signatures, audit. */
    public function test_ac21_ac29_accountant_exports_every_report(): void
    {
        $totals = [
            'income-expense' => ['62,500.00', '8,200.00', '54,300.00'],
            'branch-comparison' => ['294,300.00'],
            'transfers' => ['130,000.00'],
            'cash-book' => ['254,300.00', '40,000.00'],
        ];
        $titles = ['income-expense' => 'Income & Expense by Category', 'branch-comparison' => 'Branch Comparison',
            'transfers' => 'Transfers & Allocations', 'cash-book' => 'Daily Cash Book'];
        $header = ['Account-gold', 'Branches: KDY Kandy', 'Period: 2026-10-01 to 2026-10-31', 'Amounts in LKR',
            'Posted entries incl. reversals; voided entries excluded', "Printed by {$this->acct->name} at "];

        foreach ($totals as $name => $expected) {
            // PDF
            $res = $this->export($name, $this->october(), 'pdf')->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
            $this->assertSame("attachment; filename=\"{$name}_2026-10-01_2026-10-31.pdf\"", $res->headers->get('Content-Disposition'));
            $pages = $this->pdfPages($res->getContent());
            $this->assertNotEmpty($pages);
            foreach ($pages as $i => $text) {
                foreach ([...$header, $titles[$name], 'Page '.($i + 1).' of '.count($pages)] as $line) {
                    $this->assertStringContainsString($line, $text, "$name PDF page ".($i + 1));
                }
            }
            $all = implode(' ', $pages);
            foreach ($expected as $amount) {
                $this->assertStringContainsString($amount, $all, "$name PDF total");
            }
            $signed = in_array($name, ['cash-book', 'branch-comparison'], true);
            foreach (['Prepared by', 'Checked by', 'Approved by'] as $who) {
                $signed ? $this->assertStringContainsString($who, end($pages)) : $this->assertStringNotContainsString($who, $all);
            }

            // Excel
            $res = $this->export($name, $this->october(), 'xlsx')->assertOk();
            $this->assertSame("attachment; filename=\"{$name}_2026-10-01_2026-10-31.xlsx\"", $res->headers->get('Content-Disposition'));
            $book = $this->xlsx($res);
            $cells = [];
            foreach ($book->getAllSheets() as $sheet) {
                $top = implode("\n", array_map(fn ($r) => (string) $sheet->getCell([1, $r])->getValue(), range(1, 8)));
                foreach ([...$header, $titles[$name]] as $line) {
                    $this->assertStringContainsString($line, $top, "$name sheet {$sheet->getTitle()}");
                }
                $this->assertNotNull($sheet->getFreezePane());
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $cells[] = $cell->getFormattedValue();
                    }
                }
            }
            foreach ($expected as $amount) {
                $this->assertContains($amount, $cells, "$name xlsx total");
            }
        }

        $logs = AuditLog::where('event', 'report.exported')->get();
        $this->assertCount(8, $logs);
        $this->assertSame(['name' => 'cash-book', 'format' => 'xlsx', 'rows' => 11],
            array_intersect_key($logs->last()->new_values, array_flip(['name', 'format', 'rows'])));
        $this->assertSame('2026-10-01', $logs->last()->new_values['params']['from']);
        $this->assertSame($this->acct->id, $logs->last()->user_id);
        $this->assertSame($this->kdy->id, $logs->last()->branch_id);
    }

    public function test_excel_cells_are_typed(): void
    {
        $book = $this->xlsx($this->export('transfers', $this->october(), 'xlsx', $this->admin, 'all')->assertOk());
        $this->assertSame(['Movements', 'Matrix'], $book->getSheetNames());
        $ws = $book->getSheetByName('Movements');
        $headRow = null;
        foreach ($ws->getRowIterator() as $row) {
            if ($ws->getCell([1, $row->getRowIndex()])->getValue() === 'Date') {
                $headRow = $row->getRowIndex();
                break;
            }
        }
        $this->assertSame('A'.($headRow + 1), $ws->getFreezePane());
        // PhpSpreadsheet recycles Cell objects: read each cell right after fetching it.
        $this->assertSame('n', $ws->getCell([1, $headRow + 1])->getDataType());
        $this->assertSame('2026-10-16', $ws->getCell([1, $headRow + 1])->getFormattedValue());
        $this->assertSame('n', $ws->getCell([6, $headRow + 1])->getDataType());
        $this->assertSame('#,##0.00', $ws->getCell([6, $headRow + 1])->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('-10,000.00', $ws->getCell([6, $headRow + 1])->getFormattedValue()); // finding 6: signed
        $this->assertSame('150,000.00', $ws->getCell([6, $headRow + 6])->getFormattedValue());
        $this->assertSame('Net moved (reversals deducted)', $ws->getCell([1, $headRow + 6])->getValue());
    }

    /** AC 22: 5,001 detail rows -> 422 and no file; exactly 5,000 -> file. */
    public function test_ac22_row_cap(): void
    {
        $cash = $this->acc($this->kdy, 'Cash');
        $txnId = DB::table('transactions')->insertGetId(['branch_id' => $this->kdy->id, 'type' => 'income', 'txn_date' => '2026-11-03', 'amount' => '50.00',
            'account_id' => $cash->id, 'category_id' => Category::where('name', 'Retail')->value('id'), 'status' => 'posted', 'created_by' => $this->admin->id,
            'ref_no' => 'KDY-INC-TEST', 'created_at' => now(), 'updated_at' => now()]);
        $row = ['transaction_id' => $txnId, 'account_id' => $cash->id, 'branch_id' => $this->kdy->id, 'entry_date' => '2026-11-03', 'debit' => '0.01', 'credit' => '0.00'];
        foreach (array_chunk(array_fill(0, 5000, $row), 500) as $chunk) {
            DB::table('ledger_entries')->insert($chunk);
        }
        $q = ['from' => '2026-11-01', 'to' => '2026-11-30', 'accounts' => [$cash->id]];

        $res = $this->export('cash-book', $q, 'xlsx')->assertOk();
        $this->assertSame('attachment; filename="cash-book_2026-11-01_2026-11-30.xlsx"', $res->headers->get('Content-Disposition'));
        $this->assertSame(5000, AuditLog::where('event', 'report.exported')->latest('id')->first()->new_values['rows']);

        DB::table('ledger_entries')->insert($row);
        $before = AuditLog::where('event', 'report.exported')->count();
        $this->export('cash-book', $q, 'xlsx')->assertStatus(422)
            ->assertJsonPath('message', 'This export has 5,001 rows; the limit is 5,000. Narrow the date range or branches.');
        $this->export('cash-book', $q, 'pdf')->assertStatus(422);
        $this->assertSame($before, AuditLog::where('event', 'report.exported')->count());
    }

    /** Inserts $n cash book lines on KDY Cash in November 2026 (one per entry, spread over 30 days). Test data only. */
    private function bulkLines(int $n): array
    {
        $cash = $this->acc($this->kdy, 'Cash');
        $txnId = DB::table('transactions')->insertGetId(['branch_id' => $this->kdy->id, 'type' => 'income', 'txn_date' => '2026-11-03', 'amount' => '50.00',
            'account_id' => $cash->id, 'category_id' => Category::where('name', 'Retail')->value('id'), 'status' => 'posted', 'created_by' => $this->admin->id,
            'ref_no' => 'KDY-INC-BULK-'.uniqid(), 'payee_payer' => 'Walk-in customer', 'created_at' => now(), 'updated_at' => now()]);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['transaction_id' => $txnId, 'account_id' => $cash->id, 'branch_id' => $this->kdy->id,
                'entry_date' => sprintf('2026-11-%02d', 1 + $i % 30), 'debit' => '0.01', 'credit' => '0.00'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ledger_entries')->insert($chunk);
        }

        return ['from' => '2026-11-01', 'to' => '2026-11-30', 'accounts' => [$cash->id]];
    }

    /** AC 22 (fix round): PDF has its own cap of 1,000 lines; at the cap it succeeds within a sane memory peak. */
    public function test_ac22_pdf_cap(): void
    {
        $q = $this->bulkLines(1000);
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $start = microtime(true);
        $res = $this->export('cash-book', $q, 'pdf')->assertOk();
        $peakMb = (memory_get_peak_usage() - $base) / 1024 / 1024;
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $pages = $this->pdfPages($res->getContent());
        $this->assertGreaterThan(30, count($pages));
        $this->assertStringContainsString('Carried forward 10.00 0.00', end($pages));
        $this->assertLessThan(256, $peakMb, "PDF export peak {$peakMb} MB");
        $this->assertLessThan(60, microtime(true) - $start);
        $this->assertSame(1000, AuditLog::where('event', 'report.exported')->latest('id')->first()->new_values['rows']);

        // One more line: PDF refused (422, no raw error), Excel still fine.
        $this->bulkLines(1);
        $before = AuditLog::where('event', 'report.exported')->count();
        $this->export('cash-book', $q, 'pdf')->assertStatus(422)
            ->assertJsonPath('message', 'This export has 1,001 rows; the limit for PDF is 1,000. Use Excel or narrow the date range or branches.');
        $this->assertSame($before, AuditLog::where('event', 'report.exported')->count());
        $this->export('cash-book', $q, 'xlsx')->assertOk();
    }

    /** Fix round: the cash book screen is capped at 5,000 lines as well. */
    public function test_cash_book_screen_cap(): void
    {
        $q = $this->bulkLines(5001);
        $this->actingAs($this->acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/cash-book?'.http_build_query($q))->assertStatus(422)
            ->assertJsonPath('message', 'This cash book has 5,001 lines; the limit is 5,000. Narrow the date range, branches or accounts.');
    }

    /** QA bug 2: cash book Type column shows labels; day totals are formatted. QA bug 4: em dash drawn with DejaVu Sans. */
    public function test_type_labels_and_dash_glyph(): void
    {
        $pdf = $this->export('cash-book', $this->october(), 'pdf')->getContent();
        $text = implode(' ', $this->pdfPages($pdf));
        foreach (['Income', 'Expense', 'Fund allocation', 'Branch transfer', 'Internal transfer', 'Reversal'] as $label) {
            $this->assertStringContainsString(" {$label} ", $text);
        }
        $this->assertStringNotContainsString('fund_allocation', $text);

        $ws = $this->xlsx($this->export('cash-book', $this->october(), 'xlsx'))->getSheetByName('KDY Cash');
        $values = [];
        foreach ($ws->getRowIterator() as $row) {
            $values[] = (string) $ws->getCell([2, $row->getRowIndex()])->getValue().'|'.(string) $ws->getCell([3, $row->getRowIndex()])->getValue();
        }
        $this->assertContains('Day total (opening 170,000.00)|', $values);
        $this->assertNotEmpty(preg_grep('/\|Fund allocation$/', $values));
        $this->assertEmpty(preg_grep('/\|fund_allocation$/', $values));

        $matrix = $this->export('transfers', $this->october(), 'pdf', $this->admin, 'all')->getContent();
        $this->assertStringContainsString('DejaVuSans', $matrix);
        $this->assertStringNotContainsString('DejaVuSans', $this->export('branch-comparison', $this->october(), 'pdf')->getContent());
    }

    /** Reviewer #2: export needs report.view as well as report.export. */
    public function test_export_requires_report_view(): void
    {
        $role = Role::create(['name' => 'Exporter', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions(['report.export']);
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($role);
        $this->export('income-expense', $this->october(), 'xlsx', $u)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.view');
    }

    /** Nit: only PNG/JPEG data URIs are used as the logo. */
    public function test_logo_only_png_or_jpeg_data_uri(): void
    {
        $exporter = app(ReportExporter::class);
        $p = app(ReportService::class)->params('income-expense', $this->october());
        $data = ['provisional' => false];
        foreach (['data:image/png;base64,iVBORw0KGgo=' => true, 'data:image/jpeg;base64,/9j/4AAQ' => true, 'data:image/svg+xml;base64,PHN2Zz4=' => false,
            'https://example.com/logo.png' => false, 'data:image/png;base64,"><script>' => false] as $logo => $ok) {
            Setting::put('company_logo', $logo);
            $this->assertSame($ok ? $logo : null, $exporter->header('income-expense', $p, $data, $this->admin)['logo'], $logo);
        }
    }

    public function test_example_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not run in production');
        try {
            (new ReportExampleSeeder)->run(app(LedgerService::class));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /** AC 23: user text that looks like a formula stays literal text. */
    public function test_ac23_formula_injection(): void
    {
        $this->actingAs($this->admin);
        app(LedgerService::class)->incomeOrExpense(Transaction::EXPENSE, ['account_id' => $this->acc($this->kdy, 'Cash')->id,
            'category_id' => Category::where('name', 'Water')->value('id'), 'txn_date' => '2026-11-04', 'amount' => '10.00', 'payee_payer' => '=HYPERLINK("x")']);

        $book = $this->xlsx($this->export('cash-book', ['from' => '2026-11-04', 'to' => '2026-11-04'], 'xlsx')->assertOk());
        $found = null;
        foreach ($book->getAllSheets() as $ws) {
            foreach ($ws->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $this->assertNotSame('f', $cell->getDataType(), 'no formulas in report exports');
                    if ($cell->getValue() === '=HYPERLINK("x")') {
                        $found = [$ws, $cell->getCoordinate()];
                    }
                }
            }
        }
        $this->assertNotNull($found, 'payee exported as literal text');
        $this->assertSame('s', $found[0]->getCell($found[1])->getDataType());
        $this->assertTrue($found[0]->getCell($found[1])->getStyle()->getQuotePrefix());
    }

    /** AC 25: 0.10 + 0.20 = 0.30 in both exports. */
    public function test_ac25_exact_cents_in_exports(): void
    {
        $this->actingAs($this->admin);
        foreach (['0.10', '0.20'] as $amt) {
            app(LedgerService::class)->incomeOrExpense(Transaction::INCOME, ['account_id' => $this->acc($this->kdy, 'Cash')->id,
                'category_id' => Category::where('name', 'Retail')->value('id'), 'txn_date' => '2026-11-10', 'amount' => $amt]);
        }
        $q = ['from' => '2026-11-10', 'to' => '2026-11-10'];
        $this->assertStringContainsString('Total income 0.30', implode(' ', $this->pdfPages($this->export('income-expense', $q, 'pdf')->getContent())));
        $ws = $this->xlsx($this->export('income-expense', $q, 'xlsx'))->getSheetByName('Income');
        $values = [];
        foreach ($ws->getRowIterator() as $row) {
            $values[] = [$ws->getCell([1, $row->getRowIndex()])->getValue(), $ws->getCell([3, $row->getRowIndex()])->getFormattedValue()];
        }
        $this->assertContains(['Total income', '0.30'], $values);
    }

    /** AC 30 (finding 5): multi-page lists carry Brought forward / page total / Carried forward. */
    public function test_ac30_page_carry_forward(): void
    {
        $this->actingAs($this->admin);
        $ledger = app(LedgerService::class);
        for ($i = 1; $i <= 30; $i++) {
            $ledger->transfer($this->acc($this->kdy, 'Cash')->id, $this->acc($this->gal, 'Cash')->id, '100.00', '2026-11-0'.(1 + $i % 9), "T$i");
        }
        $q = ['from' => '2026-11-01', 'to' => '2026-11-30'];

        // 30 movements -> list pages of 20 + 10, then the matrix page.
        $pages = $this->pdfPages($this->export('transfers', $q, 'pdf')->getContent());
        $this->assertCount(3, $pages); // 2 list pages + matrix
        $this->assertStringContainsString('Brought forward 0.00', $pages[0]);
        $this->assertStringContainsString('Page total 2,000.00', $pages[0]);
        $this->assertStringContainsString('Carried forward 2,000.00', $pages[0]);
        $this->assertStringContainsString('Brought forward 2,000.00', $pages[1]);
        $this->assertStringContainsString('Page total 1,000.00', $pages[1]);
        $this->assertStringContainsString('Carried forward = Net moved (reversals deducted) 3,000.00', $pages[1]);

        // Cash book KDY Cash: 30 payments + day rows -> more than one page; last CF = total payments.
        $pages = $this->pdfPages($this->export('cash-book', $q + ['accounts' => [$this->acc($this->kdy, 'Cash')->id]], 'pdf')->getContent());
        $this->assertGreaterThan(1, count($pages));
        $this->assertMatchesRegularExpression('/Carried forward 0\.00 ([\d,]+\.\d\d)/', $pages[0]);
        preg_match('/Carried forward 0\.00 ([\d,]+\.\d\d)/', $pages[0], $m);
        $this->assertStringContainsString("Brought forward 0.00 {$m[1]}", $pages[1]);
        $this->assertStringContainsString('Carried forward 0.00 3,000.00', end($pages));
    }

    /** AC 32 (finding 7): provisional marker until every month in range is locked for every branch in scope. */
    public function test_ac32_provisional_marker(): void
    {
        $this->assertTrue($this->report('income-expense', $this->october())->json('provisional'));
        $this->assertStringContainsString('PROVISIONAL - period not locked', $this->pdfPages($this->export('branch-comparison', $this->october(), 'pdf', $this->admin, 'all')->getContent())[0]);

        foreach ([$this->main, $this->kdy] as $b) {
            PeriodLock::create(['branch_id' => $b->id, 'period' => '2026-10', 'locked_by' => $this->admin->id]);
        }
        $this->assertTrue($this->report('income-expense', $this->october())->json('provisional')); // GAL still open
        $this->assertFalse($this->report('income-expense', $this->october(['branches' => [$this->kdy->id]]))->json('provisional'));
        $this->assertTrue($this->report('income-expense', ['from' => '2026-10-01', 'to' => '2026-11-30', 'branches' => [$this->kdy->id]])->json('provisional'));

        PeriodLock::create(['branch_id' => $this->gal->id, 'period' => '2026-10', 'locked_by' => $this->admin->id]);
        foreach (['income-expense', 'branch-comparison', 'transfers'] as $name) {
            $this->assertFalse($this->report($name, $this->october())->json('provisional'));
        }
        $this->assertStringNotContainsString('PROVISIONAL', implode(' ', $this->pdfPages($this->export('branch-comparison', $this->october(), 'pdf', $this->admin, 'all')->getContent())));
        $ws = $this->xlsx($this->export('branch-comparison', $this->october(), 'xlsx', $this->admin, 'all'))->getActiveSheet();
        $this->assertStringNotContainsString('PROVISIONAL', implode(' ', array_map(fn ($r) => (string) $ws->getCell([1, $r])->getValue(), range(1, 10))));
    }

    /** AC 8 + AC 9: Cashier has no reports; Viewer can view but not export. */
    public function test_ac8_ac9_permissions(): void
    {
        $cashier = $this->userIn($this->kdy, 'Cashier');
        $viewer = $this->userIn($this->kdy, 'Viewer');
        $h = ['X-Branch-Id' => (string) $this->kdy->id];
        $q = http_build_query($this->october());
        foreach (['income-expense', 'branch-comparison', 'transfers', 'cash-book'] as $name) {
            $this->actingAs($cashier)->withHeaders($h)->getJson("/api/v1/reports/{$name}?{$q}")->assertForbidden();
            $this->actingAs($cashier)->withHeaders($h)->postJson("/api/v1/reports/{$name}/export", $this->october(['format' => 'pdf']))->assertForbidden();
            $this->actingAs($viewer)->withHeaders($h)->getJson("/api/v1/reports/{$name}?{$q}")->assertOk();
            $this->actingAs($viewer)->withHeaders($h)->postJson("/api/v1/reports/{$name}/export", $this->october(['format' => 'xlsx']))->assertForbidden();
        }
        $this->assertSame(0, AuditLog::where('event', 'report.exported')->count());
    }

    public function test_unknown_report_and_bad_format(): void
    {
        $this->export('profit-and-loss', $this->october(), 'pdf')->assertNotFound();
        $this->export('transfers', $this->october(), 'csv')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->export('transfers', $this->october(['branches' => [$this->gal->id]]), 'pdf')->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to branch GAL.');
    }
}
