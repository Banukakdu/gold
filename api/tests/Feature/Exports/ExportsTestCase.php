<?php

namespace Tests\Feature\Exports;

use App\Jobs\RunExport;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Export;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\ReadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\AccountingTestCase;
use Throwable;

/**
 * Fixture of docs/specs/background-exports.md section 7: Super Admin SA (`admin`), a KDY Accountant AK (also a GAL
 * Viewer), sync_rows 5, max_rows 20, 2 active per user. KDY: 8 posted incomes in October 2026 in 8 sub-categories
 * (I&E has 8 lines) and 25 transactions in its scope in total (the top-up, 16 September expenses, the 8 incomes).
 * The clock is fixed. RunExport is faked on the queue and run by work(), which retries as the worker does.
 */
abstract class ExportsTestCase extends AccountingTestCase
{
    protected Branch $kdy;

    protected Branch $gal;

    protected Account $kdyCash;

    protected User $ak;

    /** @var Transaction[] the 8 October incomes, 100.00 .. 800.00 */
    protected array $incomes = [];

    protected array $october = ['from' => '2026-10-01', 'to' => '2026-10-31'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:30:00', 'Asia/Colombo'));
        Storage::fake(Export::DISK);
        Queue::fake([RunExport::class]);
        config(['exports.sync_rows' => 5, 'exports.max_rows' => 20, 'exports.per_user_active' => 2, 'exports.email' => true]);

        $this->kdy = $this->makeBranch('KDY');
        $this->kdy->update(['name' => 'Kandy']);
        $this->gal = $this->makeBranch('GAL');
        $this->gal->update(['name' => 'Galle']);
        $this->kdyCash = $this->cashOf($this->kdy);
        $this->ak = $this->userIn($this->kdy, 'Accountant');
        $this->ak->update(['name' => 'Anura Kumara']);
        $this->ak->branches()->attach($this->gal->id);
        setPermissionsTeamId($this->gal->id);
        $this->ak->assignRole($this->branchRole($this->gal, 'Viewer'));

        $sales = Category::create(['type' => 'income', 'name' => 'Sales']);
        $rent = Category::create(['type' => 'expense', 'name' => 'Office']);
        $ledger = app(LedgerService::class);
        Auth::setUser($this->admin);
        $ledger->capital($this->mainAcc->id, '1000000.00', '2026-09-01', 'Owner capital');
        $ledger->transfer($this->mainAcc->id, $this->kdyCash->id, '100000.00', '2026-09-01', 'Top-up');
        Auth::setUser($this->ak);
        for ($i = 1; $i <= 16; $i++) {
            $ledger->incomeOrExpense(Transaction::EXPENSE, ['account_id' => $this->kdyCash->id, 'category_id' => $rent->id,
                'txn_date' => sprintf('2026-09-%02d', $i + 1), 'amount' => '10.00', 'payee_payer' => "Supplier {$i}"]);
        }
        for ($i = 1; $i <= 8; $i++) {
            $sub = Category::create(['type' => 'income', 'name' => "Line {$i}", 'parent_id' => $sales->id]);
            $this->incomes[] = $ledger->incomeOrExpense(Transaction::INCOME, ['account_id' => $this->kdyCash->id, 'category_id' => $sub->id,
                'txn_date' => sprintf('2026-10-%02d', $i + 1), 'amount' => sprintf('%d.00', $i * 100), 'payee_payer' => "Customer {$i}"]);
        }
        Auth::forgetUser();
    }

    protected function tearDown(): void
    {
        ReadSnapshot::$hook = null;
        parent::tearDown();
    }

    // ------------------------------------------------------------------ requests

    protected function exportTxn(User $u, string|int $branch, array $body = [])
    {
        return $this->actingAs($u)->withHeader('X-Branch-Id', (string) $branch)->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->withHeader('User-Agent', 'RequestAgent/1.0')->postJson('/api/v1/transactions/export', $body);
    }

    protected function exportReport(User $u, string|int $branch, string $name, array $body, string $format = 'xlsx')
    {
        return $this->actingAs($u)->withHeader('X-Branch-Id', (string) $branch)->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->withHeader('User-Agent', 'RequestAgent/1.0')->postJson("/api/v1/reports/{$name}/export", $body + ['format' => $format]);
    }

    /** AC 2's request: KDY October incomes (8 rows). */
    protected function requestEight(?User $u = null)
    {
        return $this->exportTxn($u ?? $this->ak, $this->kdy->id, $this->october + ['type' => 'income']);
    }

    protected function download(User $u, string $id, string|int $branch = '', array $server = [])
    {
        return $this->actingAs($u)->withHeader('X-Branch-Id', (string) ($branch === '' ? $this->kdy->id : $branch))
            ->withServerVariables($server + ['REMOTE_ADDR' => '10.0.0.99'])->withHeader('User-Agent', 'DownloadAgent/2.0')
            ->get("/api/v1/exports/{$id}/download");
    }

    // ------------------------------------------------------------------ the worker

    /**
     * Run RunExport as the `exports` worker would: up to $tries attempts; an exception retries (attempt 2), the last
     * one calls failed(). $this->fail() inside the job ends it (no retry).
     */
    protected function work(?string $id = null, int $tries = 2): RunExport
    {
        $id ??= Export::orderByDesc('requested_at')->orderByDesc('id')->value('id');
        for ($attempt = 1; ; $attempt++) {
            $job = (new RunExport($id))->withFakeQueueInteractions();
            $job->job->attempts = $attempt;
            try {
                app()->call([$job, 'handle']);

                return $job;
            } catch (Throwable $e) {
                if ($attempt >= $tries) {
                    $job->failed($e);

                    return $job;
                }
            }
        }
    }

    protected function latest(): Export
    {
        return Export::orderByDesc('requested_at')->orderByDesc('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ files

    protected function book(string $path): Spreadsheet
    {
        return IOFactory::createReader('Xlsx')->load($path);
    }

    protected function storedBook(Export $e): Spreadsheet
    {
        return $this->book(Storage::disk(Export::DISK)->path($e->file_path));
    }

    /** Header lines (column A until the blank row) of a sheet. */
    protected static function lines(Worksheet $ws): array
    {
        $lines = [];
        for ($r = 1; ($v = $ws->getCell([1, $r])->getValue()) !== null && $v !== ''; $r++) {
            $lines[] = (string) $v;
        }

        return $lines;
    }

    /**
     * Everything below the header of a sheet, cell by cell: value, data type, number format and bold (empty cells
     * skipped), so two files can be compared exactly.
     */
    protected static function grid(Worksheet $ws): array
    {
        $start = count(self::lines($ws)) + 2;
        $out = [];
        $maxCol = Coordinate::columnIndexFromString($ws->getHighestColumn());
        for ($r = $start; $r <= $ws->getHighestRow(); $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $cell = $ws->getCell([$c, $r]);
                $v = $cell->getValue();
                if ($v === null || $v === '') {
                    continue;
                }
                // An inline string (the background writer) and a shared string (PhpSpreadsheet) are both string cells.
                $type = $cell->getDataType() === 'inlineStr' ? 's' : $cell->getDataType();
                $out[] = [$r - $start, $c, is_numeric($v) && $type === 'n' ? (float) $v : (string) $v, $type,
                    $cell->getStyle()->getNumberFormat()->getFormatCode(), $cell->getStyle()->getFont()->getBold(),
                    $cell->getStyle()->getQuotePrefix()];
            }
        }

        return $out;
    }

    /** Data rows of the Transactions sheet: the number of rows with a Ref no below the column labels. */
    protected static function dataRows(Worksheet $ws): int
    {
        $start = count(self::lines($ws)) + 3;
        $n = 0;
        for ($r = $start; $ws->getCell([3, $r])->getValue() !== null; $r++) {
            $n++;
        }

        return $n;
    }

    /** Keys sorted at every depth: MySQL JSON does not keep key order. */
    protected static function sorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map([self::class, 'sorted'], $v);
    }
}
