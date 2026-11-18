<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Transaction;
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

        $posted = Transaction::where('status', 'posted')->whereDate('txn_date', '>=', $from)->whereDate('txn_date', '<=', $to);
        $income = (clone $posted)->where('type', 'income')->sum('amount');
        $expense = (clone $posted)->where('type', 'expense')->sum('amount');

        $topExpenses = Transaction::query()
            ->join('categories as c', 'c.id', '=', 'transactions.category_id')
            ->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')
            ->where('transactions.type', 'expense')->where('transactions.status', 'posted')
            ->whereDate('txn_date', '>=', $from)->whereDate('txn_date', '<=', $to)
            ->groupBy(DB::raw('COALESCE(p.name, c.name)'))
            ->selectRaw('COALESCE(p.name, c.name) as category, SUM(transactions.amount) as total')
            ->orderByDesc('total')->limit(5)->get();

        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $trendRows = Transaction::where('status', 'posted')->whereIn('type', ['income', 'expense'])
            ->whereDate('txn_date', '>=', $months->first()->toDateString())
            ->get(['type', 'txn_date', 'amount'])
            ->groupBy(fn ($t) => $t->txn_date->format('Y-m'));
        $trend = $months->map(fn ($m) => [
            'month' => $m->format('Y-m'),
            'income' => Money::add(...($trendRows[$m->format('Y-m')] ?? collect())->where('type', 'income')->pluck('amount')->all() ?: [0]),
            'expense' => Money::add(...($trendRows[$m->format('Y-m')] ?? collect())->where('type', 'expense')->pluck('amount')->all() ?: [0]),
        ]);

        return [
            'scope' => $ctx->isScoped() ? 'branch' : 'all',
            'from' => $from, 'to' => $to,
            'total_balance' => Money::add(Account::sum('current_balance')),
            'income' => Money::add($income),
            'expense' => Money::add($expense),
            'net' => Money::fromCents(Money::toCents($income) - Money::toCents($expense)),
            'accounts' => Account::with('branch:id,code,name')->where('status', 'active')->get(['id', 'branch_id', 'name', 'type', 'current_balance']),
            'branch_balances' => $ctx->isScoped() ? null : Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($b) => $b->only(['id', 'code', 'name']) + ['balance' => Money::add(Account::withoutGlobalScopes()->where('branch_id', $b->id)->sum('current_balance'))]),
            'top_expense_categories' => $topExpenses,
            'trend' => $trend,
            'recent' => Transaction::with(['branch:id,code', 'category:id,name'])->latest('txn_date')->latest('id')->limit(10)
                ->get(['id', 'ref_no', 'branch_id', 'type', 'txn_date', 'amount', 'category_id', 'status', 'description']),
        ];
    }
}
