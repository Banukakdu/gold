<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Transaction;
use App\Services\ReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry checks (docs/specs/double-entry.md R7 V1-V6), shared by `ledger:verify` and the period-lock control (R9).
 * Read-only grouped queries over all branches (no branch scope).
 */
final class LedgerChecks
{
    /**
     * V1: (transaction, branch) pairs whose debits differ from credits, with the difference (debit - credit, cents).
     * A transaction that does not balance in total has at least one such branch.
     *
     * @param  int|null  $branchId  only lines of this branch
     * @param  string|null  $through  only transactions dated on or before this Y-m-d date
     */
    public static function unbalanced(?int $branchId = null, ?string $through = null): Collection
    {
        $dr = Money::sqlCents('le.debit');
        $cr = Money::sqlCents('le.credit');

        return DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->join('branches as b', 'b.id', '=', 'le.branch_id')
            ->when($branchId, fn ($q, $id) => $q->where('le.branch_id', $id))
            ->when($through, fn ($q, $d) => $q->whereDate('t.txn_date', '<=', $d))
            ->groupBy('le.transaction_id', 'le.branch_id', 't.ref_no', 't.txn_date', 'b.code')
            ->havingRaw("SUM($dr) <> SUM($cr)")
            ->selectRaw("le.transaction_id, le.branch_id, b.code as branch, t.ref_no, t.txn_date, SUM($dr) - SUM($cr) as diff")
            ->orderBy('le.transaction_id')->get();
    }

    /** V1: transactions without lines that are not legacy voids. */
    public static function withoutLines(): Collection
    {
        return DB::table('transactions as t')->join('branches as b', 'b.id', '=', 't.branch_id')->where('t.status', '!=', 'void')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as le')->whereColumn('le.transaction_id', 't.id'))
            ->orderBy('t.id')->get(['t.id as transaction_id', 't.ref_no', 't.txn_date', 'b.code as branch']);
    }

    /** V3: debit - credit (cents) per branch over all lines; the trial balance nets to zero when every value is 0. */
    public static function netByBranch(): Collection
    {
        $dr = Money::sqlCents('le.debit');
        $cr = Money::sqlCents('le.credit');

        return DB::table('ledger_entries as le')->join('branches as b', 'b.id', '=', 'le.branch_id')
            ->groupBy('le.branch_id', 'b.code')->orderBy('le.branch_id')
            ->selectRaw("b.code as branch, SUM($dr) - SUM($cr) as diff")->pluck('diff', 'branch')->map(fn ($v) => (int) $v);
    }

    /**
     * V4: for every non-main branch B, balance(HO in B) + balance(BCA(B) in MAIN) = 0, and no other inter-branch account.
     *
     * @return array{pairs: array, stray: Collection}
     */
    public static function interbranch(): array
    {
        $main = Branch::where('is_main', true)->first();
        $bal = self::accountBalances();
        $accounts = Account::withoutGlobalScopes()->whereIn('type', Account::INTERBRANCH_TYPES)->get();
        $pairs = [];
        foreach (Branch::where('is_main', false)->orderBy('id')->get() as $b) {
            $ho = $accounts->first(fn ($a) => $a->type === 'head_office' && (int) $a->branch_id === (int) $b->id);
            $bca = $accounts->first(fn ($a) => $a->type === 'branch_current' && (int) $a->counterparty_branch_id === (int) $b->id);
            $h = $ho ? ($bal[$ho->id] ?? 0) : 0;
            $c = $bca ? ($bal[$bca->id] ?? 0) : 0;
            $pairs[] = ['branch' => $b->code, 'head_office' => $h, 'branch_current' => $c, 'diff' => $h + $c];
        }
        $stray = $accounts->filter(fn ($a) => ! $main
            || ($a->type === 'head_office' && ((int) $a->branch_id === (int) $main->id || (int) $a->counterparty_branch_id !== (int) $main->id))
            || ($a->type === 'branch_current' && ((int) $a->branch_id !== (int) $main->id || $a->counterparty_branch_id === null
                || (int) $a->counterparty_branch_id === (int) $main->id)))->values();

        return ['pairs' => $pairs, 'stray' => $stray];
    }

    /**
     * V5: category account balance (credit - debit for income, debit - credit for expense) vs the all-time I&E figure,
     * per (branch, category). Returns the rows that differ.
     */
    public static function categoryMismatches(): Collection
    {
        $reports = app(ReportService::class);
        $x = $reports->cents('t.amount');
        $ie = $reports->countedTransactions([Transaction::INCOME, Transaction::EXPENSE])
            ->groupByRaw('t.branch_id, COALESCE(o.category_id, t.category_id)')
            ->selectRaw("t.branch_id, COALESCE(o.category_id, t.category_id) as category_id, SUM(CASE WHEN t.type = 'reversal' THEN -$x ELSE $x END) as cents")
            ->get()->mapWithKeys(fn ($r) => [$r->branch_id.':'.$r->category_id => (int) $r->cents]);

        $dr = Money::sqlCents('le.debit');
        $cr = Money::sqlCents('le.credit');
        $ledger = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')->where('a.type', 'category')
            ->groupBy('a.branch_id', 'a.category_id', 'a.class')
            ->selectRaw("a.branch_id, a.category_id, a.class, SUM($dr) - SUM($cr) as net")->get()
            ->mapWithKeys(fn ($r) => [$r->branch_id.':'.$r->category_id => $r->class === 'income' ? -(int) $r->net : (int) $r->net]);

        $codes = Branch::pluck('code', 'id');
        $out = collect();
        foreach ($ie->keys()->merge($ledger->keys())->unique() as $key) {
            $a = $ledger[$key] ?? 0;
            $b = $ie[$key] ?? 0;
            if ($a !== $b) {
                [$branch, $cat] = explode(':', $key);
                $out->push(['branch' => $codes[(int) $branch] ?? $branch, 'category_id' => (int) $cat, 'ledger' => $a, 'report' => $b, 'diff' => $a - $b]);
            }
        }

        return $out;
    }

    /** V6: income, expense, capital or cross-branch transactions (and their reversals) that have only money-account lines. */
    public static function notBackfilled(): Collection
    {
        return DB::table('transactions as t')->leftJoin('transactions as o', 'o.id', '=', 't.reversal_of_id')
            ->join('branches as b', 'b.id', '=', 't.branch_id')
            ->whereRaw('COALESCE(o.type, t.type) <> ?', [Transaction::INTERNAL_TRANSFER])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as le')->whereColumn('le.transaction_id', 't.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')
                ->whereColumn('le.transaction_id', 't.id')->whereNotIn('a.type', Account::MONEY_TYPES))
            ->orderBy('t.id')->get(['t.id as transaction_id', 't.ref_no', 't.txn_date', 'b.code as branch', 't.type']);
    }

    /** @return array<int, int> ledger balance (debit - credit, cents) by account id */
    public static function accountBalances(): array
    {
        $dr = Money::sqlCents('debit');
        $cr = Money::sqlCents('credit');

        return DB::table('ledger_entries')->groupBy('account_id')->selectRaw("account_id, SUM($dr) - SUM($cr) as b")
            ->pluck('b', 'account_id')->map(fn ($v) => (int) $v)->all();
    }
}
