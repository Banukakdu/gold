<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Transaction;
use App\Services\ReportService;
use App\Support\BranchContext;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** AG-080 consolidated (Super Admin, all branches) / AG-081 branch dashboard. */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $d = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = $d['from'] ?? now()->startOfMonth()->toDateString();
        $to = $d['to'] ?? now()->toDateString();
        $ctx = app(BranchContext::class);

        // Accounting review finding 3: same basis as the reports (R3). Originals (posted or reversed) count on their
        // date, reversals count on theirs with the opposite sign; voided entries never count.
        $reports = app(ReportService::class);
        $x = $reports->cents('t.amount');
        $signed = "CASE WHEN t.type = 'reversal' THEN -$x ELSE $x END";
        $counted = fn () => $reports->countedTransactions([Transaction::INCOME, Transaction::EXPENSE])
            ->when($ctx->isScoped(), fn ($q) => $q->where('t.branch_id', $ctx->branchId));
        $inPeriod = fn () => $counted()->whereDate('t.txn_date', '>=', $from)->whereDate('t.txn_date', '<=', $to);

        $totals = $inPeriod()->groupByRaw('COALESCE(o.type, t.type)')
            ->selectRaw("COALESCE(o.type, t.type) as etype, SUM($signed) as cents")->pluck('cents', 'etype');
        $income = (int) ($totals[Transaction::INCOME] ?? 0);
        $expense = (int) ($totals[Transaction::EXPENSE] ?? 0);

        $topExpenses = $inPeriod()
            ->join('categories as c', 'c.id', '=', DB::raw('COALESCE(o.category_id, t.category_id)'))
            ->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')
            ->whereRaw('COALESCE(o.type, t.type) = ?', [Transaction::EXPENSE])
            ->groupBy(DB::raw('COALESCE(p.name, c.name)'))
            ->selectRaw("COALESCE(p.name, c.name) as category, SUM($signed) as cents")
            ->orderByDesc('cents')->limit(5)->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => Money::fromCents((int) $r->cents)]);

        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $trendRows = $counted()->whereDate('t.txn_date', '>=', $months->first()->toDateString())
            ->selectRaw("COALESCE(o.type, t.type) as etype, t.txn_date, $signed as cents")->get()
            ->groupBy(fn ($t) => substr($t->txn_date, 0, 7));
        $sum = fn (string $month, string $type) => Money::fromCents((int) ($trendRows[$month] ?? collect())->where('etype', $type)->sum(fn ($r) => (int) $r->cents));
        $trend = $months->map(fn ($m) => [
            'month' => $m->format('Y-m'),
            'income' => $sum($m->format('Y-m'), Transaction::INCOME),
            'expense' => $sum($m->format('Y-m'), Transaction::EXPENSE),
        ]);

        return [
            'scope' => $ctx->isScoped() ? 'branch' : 'all',
            'from' => $from, 'to' => $to,
            // Double-entry R8: money accounts only.
            'total_balance' => Money::add(Account::money()->sum('current_balance')),
            'income' => Money::fromCents($income),
            'expense' => Money::fromCents($expense),
            'net' => Money::fromCents($income - $expense),
            'accounts' => Account::money()->with('branch:id,code,name')->where('status', 'active')->get(['id', 'branch_id', 'name', 'type', 'current_balance']),
            'branch_balances' => $ctx->isScoped() ? null : Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($b) => $b->only(['id', 'code', 'name']) + ['balance' => Money::add(Account::withoutGlobalScopes()->money()->where('branch_id', $b->id)->sum('current_balance'))]),
            'top_expense_categories' => $topExpenses,
            'trend' => $trend,
            // AG-092 (settings spec R5, review finding 15): the dashboard is unchanged by a year-end close; a closing
            // transaction moves no money and is not a recent entry.
            'recent' => Transaction::with(['branch:id,code', 'category:id,name'])->where('type', '<>', Transaction::YEAR_END_CLOSE)->latest('txn_date')->latest('id')->limit(10)
                ->get(['id', 'ref_no', 'branch_id', 'type', 'txn_date', 'amount', 'category_id', 'status', 'description']),
        ];
    }
}
