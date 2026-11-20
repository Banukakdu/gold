<?php

namespace Tests\Feature\Exports;

use App\Jobs\RunExport;
use App\Models\AuditLog;
use App\Models\Export;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** docs/specs/background-exports.md ACs 1-5 (R1 threshold, PDF never queued, validation first). */
class ThresholdTest extends ExportsTestCase
{
    public function test_ac1_small_export_downloads_at_once(): void
    {
        $r = $this->exportTxn($this->ak, $this->kdy->id, $this->october + ['type' => 'income', 'min' => '400'])->assertOk();
        $this->assertSame('attachment; filename="transactions_2026-10-01_2026-10-31.xlsx"', $r->headers->get('Content-Disposition'));
        $this->assertSame(5, AuditLog::where('event', 'report.exported')->sole()->new_values['rows']);
        $this->assertSame(0, Export::count());
        Queue::assertNothingPushed();
    }

    public function test_ac2_large_export_is_queued(): void
    {
        $r = $this->requestEight()->assertStatus(202)
            ->assertJsonPath('message', 'This export has 8 rows, so it is being prepared in the background. We will email you when it is ready.')
            ->assertJsonPath('export.status', 'queued')
            ->assertJsonPath('export.rows', 8)
            ->assertJsonPath('export.title', 'Transactions')
            ->assertJsonPath('export.branches_label', 'KDY')
            ->assertJsonPath('export.period_label', '1 Oct 2026 to 31 Oct 2026');
        $e = Export::sole();
        $this->assertSame($e->id, $r->json('export.id'));
        $this->assertSame([Export::QUEUED, $this->kdy->id, 8, $this->ak->id], [$e->status, $e->branch_id, $e->rows, $e->user_id]);
        $this->assertSame([$this->kdy->id], $e->branch_ids);
        // The resource holds no file path, user id or params internals.
        $this->assertArrayNotHasKey('file_path', $r->json('export'));
        $this->assertArrayNotHasKey('params', $r->json('export'));
        $this->assertArrayNotHasKey('user_id', $r->json('export'));
        $this->assertSame('/transactions?dir=desc&from=2026-10-01&sort=date&to=2026-10-31&type=income', $r->json('export.rerun_url'));

        $a = AuditLog::where('event', 'export.requested')->sole();
        $this->assertSame([$this->ak->id, $this->kdy->id, '10.0.0.7'], [(int) $a->user_id, (int) $a->branch_id, $a->ip]);
        $this->assertSame(self::sorted(['export_id' => $e->id, 'name' => 'transactions', 'format' => 'xlsx', 'rows' => 8, 'branch_ids' => [$this->kdy->id],
            'params' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'income', 'sort' => 'date', 'dir' => 'desc']]), self::sorted($a->new_values));
        Queue::assertPushed(RunExport::class, fn (RunExport $j) => $j->exportId === $e->id && $j->connection === 'exports' && $j->queue === 'exports');
        $this->assertSame(0, AuditLog::where('event', 'report.exported')->count());
    }

    public function test_ac3_over_the_limit_is_refused(): void
    {
        // 13 September expenses (from the 5th) + 8 October incomes.
        $this->exportTxn($this->ak, $this->kdy->id, ['from' => '2026-09-05'])->assertStatus(422)
            ->assertJsonPath('message', 'This export has 21 rows; the limit is 20. Narrow the filters.');
        config(['exports.max_rows' => 16]);
        // Cash book of September: the top-up and 16 expenses.
        $this->exportReport($this->ak, $this->kdy->id, 'cash-book', ['from' => '2026-09-01', 'to' => '2026-09-30'])->assertStatus(422)
            ->assertJsonPath('message', 'This export has 17 rows; the limit is 16. Narrow the date range or branches.');
        $this->assertSame(0, Export::count());
        $this->assertSame(0, AuditLog::whereIn('event', ['export.requested', 'report.exported'])->count());
        Queue::assertNothingPushed();
    }

    public function test_ac4_pdf_is_never_queued_and_cash_flow_is_never_queued(): void
    {
        $r = $this->exportReport($this->ak, $this->kdy->id, 'income-expense', $this->october + ['type' => 'income'], 'pdf')->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame(8, AuditLog::where('event', 'report.exported')->sole()->new_values['rows']);

        config(['exports.sync_rows' => 1]);
        $this->exportReport($this->ak, $this->kdy->id, 'cash-flow', ['from' => '2026-09-01', 'to' => '2026-10-31'])->assertOk();
        $this->assertSame(0, Export::count());

        // 1,001 PDF rows: the existing message (Excel would be queued, not refused).
        config(['exports.sync_rows' => 5000, 'exports.max_rows' => 100000]);
        $txn = $this->incomes[0];
        $row = ['transaction_id' => $txn->id, 'account_id' => $this->kdyCash->id, 'branch_id' => $this->kdy->id, 'entry_date' => '2026-10-15', 'debit' => '0.01', 'credit' => '0.00'];
        DB::table('ledger_entries')->insert(array_fill(0, 1001, $row));
        $q = $this->october + ['accounts' => [$this->kdyCash->id]];
        $this->exportReport($this->ak, $this->kdy->id, 'cash-book', $q, 'pdf')->assertStatus(422)
            ->assertJsonPath('message', 'This export has 1,009 rows; the limit for PDF is 1,000. Use Excel or narrow the date range or branches.');
        $this->assertSame(0, Export::count());
    }

    public function test_ac5_invalid_request_creates_nothing(): void
    {
        $this->exportReport($this->ak, $this->kdy->id, 'cash-book', ['from' => '2026-09-01', 'to' => '2026-10-31'])->assertStatus(422)
            ->assertJsonValidationErrors(['to' => 'The date range may not be longer than 31 days.']);
        $this->exportTxn($this->ak, $this->kdy->id, ['from' => '2026-10-31', 'to' => '2026-10-01'])->assertStatus(422);
        $this->assertSame(0, Export::count());
        $this->assertSame(0, AuditLog::where('event', 'export.requested')->count());
    }

    public function test_a_report_export_over_the_threshold_is_queued_with_its_filters(): void
    {
        $this->exportReport($this->ak, $this->kdy->id, 'income-expense', $this->october + ['type' => 'income', 'page' => 3])->assertStatus(202)
            ->assertJsonPath('export.title', 'Income & Expense by Category')
            ->assertJsonPath('export.rerun_url', '/reports/income-expense?from=2026-10-01&to=2026-10-31&type=income');
        $e = Export::sole();
        $this->assertSame(['report', 'income-expense', 8], [$e->kind, $e->name, $e->rows]);
        $this->assertSame(self::sorted(['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'income']), self::sorted($e->params));
    }

    public function test_permissions_of_the_endpoints_are_unchanged(): void
    {
        $viewer = $this->userIn($this->kdy, 'Viewer');
        $this->requestEight($viewer)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.export');
        $this->assertSame(0, Export::count());
    }
}
