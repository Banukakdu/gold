<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BackgroundExports;
use App\Services\BankReconciliation;
use App\Services\ReportExporter;
use App\Services\ReportService;
use Illuminate\Http\Request;

/** AG-082..086, AG-089: Income & Expense, Branch Comparison, Transfers & Allocations, Daily Cash Book, Cash Flow Summary, Trial Balance (+ PDF/Excel export). */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports, private ReportExporter $exporter) {}

    public function incomeExpense(Request $request)
    {
        return $this->reports->incomeExpense($this->reports->params('income-expense', $request->all()));
    }

    public function branchComparison(Request $request)
    {
        return $this->reports->branchComparison($this->reports->params('branch-comparison', $request->all()));
    }

    public function transfers(Request $request)
    {
        return $this->reports->transfers($this->reports->params('transfers', $request->all()));
    }

    public function cashBook(Request $request)
    {
        $params = $this->reports->params('cash-book', $request->all());
        $rows = $this->reports->cashBookRows($params);
        if ($rows > ReportService::EXPORT_CAP) {
            // AG-087 QA L1: the screen's cap only; Excel takes the full book (in the background), so the text says so.
            return response()->json(['message' => 'This cash book has '.number_format($rows).' lines, too many to show on screen (the limit is '
                .number_format(ReportService::EXPORT_CAP).'). Narrow the date range, branches or accounts, or export it to Excel.'], 422);
        }

        return $this->reports->cashBook($params);
    }

    /** AG-089 trial balance (double-entry S2). */
    public function trialBalance(Request $request)
    {
        return $this->reports->trialBalance($this->reports->params('trial-balance', $request->all()));
    }

    /** AG-086 monthly Cash Flow Summary (docs/specs/cash-flow.md). */
    public function cashFlow(Request $request)
    {
        return $this->reports->cashFlow($this->reports->params('cash-flow', $request->all()));
    }

    /** AG-033 S3 Unreconciled bank entries (docs/specs/bank-reconciliation.md). */
    public function bankReconciliation(Request $request, BankReconciliation $recon)
    {
        return $recon->report($recon->reportParams($request->all()), BankReconciliation::SCREEN_ROWS); // review finding 9
    }

    /** AG-033c S6 bank reconciliation statements list (docs/specs/reconciliation-statements-list.md). Read only. */
    public function reconciliationStatements(Request $request, BankReconciliation $recon)
    {
        return $recon->statementList($recon->statementListParams($request->all()));
    }

    /** AG-033e S7 bank reconciliation status per account (docs/specs/reconciliation-overdue.md). Read only. */
    public function reconciliationStatus(Request $request, BankReconciliation $recon)
    {
        return $recon->status($recon->statusParams($request->all()));
    }

    /** AG-087 R1: validation first, then the count; a large Excel export is queued (202), anything else downloads. */
    public function export(Request $request, string $name, BackgroundExports $exports)
    {
        abort_unless(in_array($name, ReportService::NAMES, true), 404, 'Unknown report.');
        $format = $request->validate(['format' => 'required|in:pdf,xlsx'])['format'];
        $params = match ($name) {
            'bank-reconciliation' => app(BankReconciliation::class)->reportParams($request->except('format')),
            // AG-033c: the list filters without paging (every matching row).
            'reconciliation-statements' => app(BankReconciliation::class)->statementListParams($request->except('format'), paging: false),
            // AG-033e: always as at today.
            'reconciliation-status' => app(BankReconciliation::class)->statusParams($request->except('format')),
            default => $this->reports->params($name, $request->except('format')),
        };

        return $this->exporter->download($name, $params, $format, $request->user(),
            fn (int $rows) => $exports->queueReport($request, $name, $params, $rows));
    }
}
