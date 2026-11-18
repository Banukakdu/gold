<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportExporter;
use App\Services\ReportService;
use Illuminate\Http\Request;

/** AG-082..085, AG-089: Income & Expense, Branch Comparison, Transfers & Allocations, Daily Cash Book, Trial Balance (+ PDF/Excel export). */
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
            return response()->json(['message' => 'This cash book has '.number_format($rows).' lines; the limit is '.number_format(ReportService::EXPORT_CAP).'. Narrow the date range, branches or accounts.'], 422);
        }

        return $this->reports->cashBook($params);
    }

    /** AG-089 trial balance (double-entry S2). */
    public function trialBalance(Request $request)
    {
        return $this->reports->trialBalance($this->reports->params('trial-balance', $request->all()));
    }

    public function export(Request $request, string $name)
    {
        abort_unless(in_array($name, ReportService::NAMES, true), 404, 'Unknown report.');
        $format = $request->validate(['format' => 'required|in:pdf,xlsx'])['format'];
        $params = $this->reports->params($name, $request->except('format'));

        return $this->exporter->download($name, $params, $format, $request->user());
    }
}
