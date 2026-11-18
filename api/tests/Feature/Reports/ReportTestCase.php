<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Transaction;
use Database\Seeders\ReportExampleSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\AccountingTestCase;

/** Loads the worked example of docs/specs/reports.md section 7. */
abstract class ReportTestCase extends AccountingTestCase
{
    protected Branch $kdy;

    protected Branch $gal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReportExampleSeeder::class);
        $this->kdy = Branch::where('code', 'KDY')->first();
        $this->gal = Branch::where('code', 'GAL')->first();
    }

    protected function txn(int|string $key): Transaction
    {
        return ReportExampleSeeder::$txn[$key];
    }

    protected function acc(Branch $b, string $name): Account
    {
        return Account::withoutGlobalScopes()->where('branch_id', $b->id)->where('name', $name)->first();
    }

    /** GET a report as Super Admin (All mode unless a branch is given). */
    protected function report(string $name, array $q, string|int $branch = 'all')
    {
        return $this->asAdmin($branch)->getJson("/api/v1/reports/{$name}?".http_build_query($q));
    }

    protected function october(array $extra = []): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-10-31'] + $extra;
    }

    /**
     * Text of each PDF page (dompdf core-font output: literal strings in FlateDecode content streams).
     *
     * @return string[] one entry per page, in order
     */
    protected function pdfPages(string $pdf): array
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $pages = [];
        // Slice each stream by its /Length (QA/reviewer R8): the compressed bytes may themselves end in \r or \n,
        // so trimming end-of-line characters before "endstream" corrupts some streams (flaky, timestamp-dependent).
        preg_match_all('/\/Length (\d+)[^>]*>>\s*stream\r?\n/', $pdf, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            $raw = substr($pdf, $hit[0][1] + strlen($hit[0][0]), (int) $hit[1][0]);
            $content = @gzuncompress($raw);
            // Page content streams are text operators; embedded font programs (DejaVu) are binary.
            if ($content === false || str_contains($content, "\0") || ! str_contains($content, ' Tf')) {
                continue;
            }
            preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $content, $s);
            $pages[] = implode(' ', array_map(fn ($x) => stripcslashes(substr($x, 1, -1)), $s[0]));
        }

        return $pages;
    }

    /** Load an xlsx response into PhpSpreadsheet. */
    protected function xlsx($response): Spreadsheet
    {
        return IOFactory::createReader('Xlsx')->load($response->getFile()->getPathname());
    }
}
