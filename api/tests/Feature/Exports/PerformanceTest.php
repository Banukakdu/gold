<?php

namespace Tests\Feature\Exports;

use App\Models\Export;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use XMLReader;

/**
 * docs/specs/background-exports.md AC 9: 100,000 KDY transactions export within 600 s with memory_limit 1024M.
 * A performance test on MySQL: run with EXPORTS_PERF=1 and DB_CONNECTION=mysql (skipped otherwise).
 */
class PerformanceTest extends ExportsTestCase
{
    public function test_ac9_one_hundred_thousand_rows(): void
    {
        if (! env('EXPORTS_PERF') || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Performance test: set EXPORTS_PERF=1 and run on MySQL.');
        }
        config(['exports.max_rows' => 100000]);
        $now = now();
        $base = ['branch_id' => $this->kdy->id, 'type' => Transaction::INCOME, 'status' => 'posted', 'account_id' => $this->kdyCash->id,
            'category_id' => $this->incomes[0]->category_id, 'created_by' => $this->ak->id, 'created_at' => $now, 'updated_at' => $now,
            'payee_payer' => 'Bulk customer', 'description' => 'Performance test row'];
        // Stored rows only (the export reads transactions): 100,000 - the 25 fixture rows in KDY's scope.
        foreach (array_chunk(range(1, 100000 - 25), 2000) as $chunk) {
            DB::table('transactions')->insert(array_map(fn ($i) => $base + ['ref_no' => "PERF-{$i}", 'amount' => sprintf('%d.%02d', $i % 5000, $i % 100),
                'txn_date' => sprintf('2026-%02d-%02d', 1 + $i % 10, 1 + $i % 28)], $chunk));
        }
        $this->exportTxn($this->ak, $this->kdy->id)->assertStatus(202)->assertJsonPath('export.rows', 100000);

        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '1024M');
        memory_reset_peak_usage();
        $start = microtime(true);
        try {
            $this->work();
        } finally {
            ini_set('memory_limit', $limit);
        }
        $seconds = microtime(true) - $start;
        $peak = memory_get_peak_usage() / 1024 / 1024;

        $e = $this->latest();
        $this->assertSame([Export::READY, 100000], [$e->status, $e->rows], (string) $e->error);
        $this->assertLessThan(600, $seconds);
        fwrite(STDERR, sprintf("\nAC 9: 100,000 rows in %.1f s, peak %.0f MB, file %.1f MB\n", $seconds, $peak, $e->size_bytes / 1048576));

        // Count the data rows straight from the sheet XML (loading 100,000 rows into PhpSpreadsheet would not fit).
        $xml = new XMLReader;
        $xml->open('zip://'.Storage::disk(Export::DISK)->path($e->file_path).'#xl/worksheets/sheet1.xml');
        $rows = 0;
        $labels = null;
        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'pane') {
                $labels = (int) $xml->getAttribute('ySplit');
            }
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'row' && $labels !== null && (int) $xml->getAttribute('r') > $labels) {
                $rows++;
            }
        }
        $this->assertSame(100000, $rows);
    }
}
