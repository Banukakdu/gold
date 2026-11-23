<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Transaction;
use App\Models\YearEndClose;
use App\Services\BankReconciliation;
use App\Services\ReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry checks (docs/specs/double-entry.md R7 V1-V6), shared by `ledger:verify` and the period-lock control (R9).
 * Read-only grouped queries over all branches (no branch scope).
 */
final class LedgerChecks
{
    /** V9: the only types that can carry an approval_status (AG-051 transfers, AG-064 expenses). */
    public const APPROVAL_TYPES = [Transaction::BRANCH_TRANSFER, Transaction::FUND_RETURN, Transaction::EXPENSE];

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

    /**
     * V1: transactions without lines that are not legacy voids. AG-051 R13: transfer requests that never posted
     * (status pending / rejected) have no lines by design; V9 (approvalRequests) asserts that instead.
     */
    public static function withoutLines(): Collection
    {
        return DB::table('transactions as t')->join('branches as b', 'b.id', '=', 't.branch_id')->whereNotIn('t.status', ['void', 'pending', 'rejected'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as le')->whereColumn('le.transaction_id', 't.id'))
            ->orderBy('t.id')->get(['t.id as transaction_id', 't.ref_no', 't.txn_date', 'b.code as branch']);
    }

    /**
     * V9 (AG-051, accounting review MUST 2; AG-064 R13): a request that never posted has zero ledger lines, approval_status
     * appears only on a type that can be held, and status
     * agrees with approval_status (pending <-> pending; rejected / cancelled / expired <-> rejected; approved <->
     * posted | reversed, or void for an expense; status pending / rejected only with an approval_status). Offenders as [ref, date, branch, problem].
     */
    public static function approvalRequests(): Collection
    {
        $closed = ['rejected', 'cancelled', 'expired'];
        $lines = fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as le')->whereColumn('le.transaction_id', 't.id');
        $base = fn () => DB::table('transactions as t')->join('branches as b', 'b.id', '=', 't.branch_id')
            ->select('t.id as transaction_id', 't.ref_no', 't.txn_date', 'b.code as branch', 't.status', 't.approval_status', 't.type')->orderBy('t.id');

        $withLines = $base()->where(fn ($w) => $w->whereIn('t.status', ['pending', 'rejected'])->orWhereIn('t.approval_status', ['pending', ...$closed]))
            ->whereExists($lines)->get()
            ->each(fn ($r) => $r->problem = 'request that never posted has ledger lines');
        $disagree = $base()->where(fn ($w) => $w
            ->where(fn ($x) => $x->where('t.approval_status', 'pending')->where('t.status', '!=', 'pending'))
            ->orWhere(fn ($x) => $x->whereIn('t.approval_status', $closed)->where('t.status', '!=', 'rejected'))
            // An approved expense may later be voided through the normal void (AG-064 R1); transfers are reversed instead.
            ->orWhere(fn ($x) => $x->where('t.approval_status', 'approved')->whereNotIn('t.status', ['posted', 'reversed'])
                ->where(fn ($y) => $y->where('t.type', '!=', 'expense')->orWhere('t.status', '!=', 'void')))
            ->orWhere(fn ($x) => $x->whereIn('t.status', ['pending', 'rejected'])->whereNull('t.approval_status'))
            ->orWhere(fn ($x) => $x->whereNotNull('t.approval_status')->whereNotIn('t.approval_status', ['pending', 'approved', ...$closed]))
            // AG-064 R13: only branch transfers, fund returns and expenses are ever held.
            ->orWhere(fn ($x) => $x->whereNotNull('t.approval_status')->whereNotIn('t.type', self::APPROVAL_TYPES)))
            ->get()->each(fn ($r) => $r->problem = in_array($r->type, self::APPROVAL_TYPES, true)
                ? "status {$r->status} disagrees with approval_status ".($r->approval_status ?? 'null')
                : "approval_status {$r->approval_status} on a {$r->type} entry (never held)");

        return $withLines->merge($disagree)->values();
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
        // AG-092 (settings spec R9 V5): the year-end close lines are not I&E figures.
        $ledger = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')->where('a.type', 'category')
            ->where('le.origin', '<>', 'close')
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

    /**
     * V7 (settings spec R9 + review finding 8). For every active close of [S, E]:
     *  - each branch's income and expense accounts net to 0.00 over all lines dated <= E;
     *  - the close's lines on HO in B (non-main B) or on 32000 in MAIN's own closing transaction equal -N(B) of `summary`;
     * and MAIN 32000 = -sum(total_net of active closes). Returns the offenders as [check, branch, detail, difference].
     */
    public static function yearEnd(): Collection
    {
        $out = collect();
        $dr = Money::sqlCents('le.debit');
        $cr = Money::sqlCents('le.credit');
        $codes = Branch::pluck('code', 'id');
        $codeIds = $codes->flip();
        $closes = YearEndClose::active()->orderBy('fiscal_year')->get();
        $total = 0;
        foreach ($closes as $c) {
            $end = $c->period_end->format('Y-m-d');
            $label = 'FY start '.$c->fiscal_year;
            $rows = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')->whereIn('a.class', ['income', 'expense'])
                ->whereDate('le.entry_date', '<=', $end)->groupBy('le.branch_id', 'le.account_id', 'a.code', 'a.name')
                ->havingRaw("SUM($dr) <> SUM($cr)")->selectRaw("le.branch_id, a.code, a.name, SUM($dr) - SUM($cr) as diff")->get();
            foreach ($rows as $r) {
                $out->push(['check' => "{$label}: income/expense not cleared", 'branch' => $codes[$r->branch_id] ?? '', 'detail' => "{$r->code} {$r->name}", 'diff' => (int) $r->diff]);
            }
            foreach ($c->summary['by_branch'] ?? [] as $code => $b) {
                $net = Money::toCents($b['net'] ?? '0');
                $txn = $b['transaction_id'] ?? null;
                $branchId = $codeIds[$code] ?? null;
                $isMain = $branchId !== null && Branch::whereKey($branchId)->value('is_main');
                $q = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')->where('le.transaction_id', $txn ?? 0)->whereNull('le.contra_of_id');
                $isMain ? $q->where('a.type', 'retained_earnings') : $q->where('a.type', 'head_office');
                $moved = (int) $q->selectRaw("COALESCE(SUM($dr) - SUM($cr), 0) as v")->value('v');
                if ($moved !== -$net) {
                    $out->push(['check' => "{$label}: result moved differs from summary", 'branch' => (string) $code, 'detail' => 'summary net '.Money::format(Money::fromCents($net)), 'diff' => $moved + $net]);
                }
            }
            $total += Money::toCents($c->totalNet());
        }
        $re = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')->where('a.type', 'retained_earnings')
            ->selectRaw("COALESCE(SUM($dr) - SUM($cr), 0) as v")->value('v');
        if ((int) $re !== -$total) {
            $out->push(['check' => 'Retained earnings = -sum(total_net) of active closes', 'branch' => 'MAIN', 'detail' => 'expected '.Money::format(Money::fromCents(-$total)), 'diff' => (int) $re + $total]);
        }

        return $out;
    }

    /**
     * V8 (settings spec R9 + review findings 3 and 7): offenders as [rule, line id, ref, date].
     *  - close lines only in year_end_close transactions, and those have no other origin;
     *  - for each active close, every line dated in [S, E] was created at or before closed_at, or is one of its own lines;
     *  - every line on a retained_earnings account has origin close.
     */
    public static function closeLines(): Collection
    {
        $base = fn () => DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->select('le.id', 't.ref_no', 'le.transaction_id', 'le.entry_date')->orderBy('le.id');
        $out = collect();
        $add = fn (string $rule, $rows) => $rows->each(fn ($r) => $out->push(['rule' => $rule, 'id' => $r->id, 'ref' => $r->ref_no ?? "#{$r->transaction_id}", 'date' => substr((string) $r->entry_date, 0, 10)]));
        $add('close line outside a year-end close', $base()->where('le.origin', 'close')->where('t.type', '<>', Transaction::YEAR_END_CLOSE)->get());
        $add('other origin in a year-end close', $base()->where('le.origin', '<>', 'close')->where('t.type', Transaction::YEAR_END_CLOSE)->get());
        foreach (YearEndClose::active()->get() as $c) {
            $add("line in closed FY start {$c->fiscal_year} created after the close", $base()
                ->whereDate('le.entry_date', '>=', $c->period_start->format('Y-m-d'))->whereDate('le.entry_date', '<=', $c->period_end->format('Y-m-d'))
                ->where('le.created_at', '>', $c->closed_at->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))
                ->whereNotIn('le.transaction_id', $c->transactionIds() ?: [0])->get());
        }
        $add('Retained earnings line not from the close', $base()->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('a.type', 'retained_earnings')->where('le.origin', '<>', 'close')->get());

        return $out;
    }

    /**
     * @param  int|null  $maxId  only lines with id <= this (AG-093 control.json: the rows a backup is sure to hold)
     * @return array<int, int> ledger balance (debit - credit, cents) by account id
     */
    public static function accountBalances(?int $maxId = null): array
    {
        $dr = Money::sqlCents('debit');
        $cr = Money::sqlCents('credit');

        return DB::table('ledger_entries')->when($maxId !== null, fn ($q) => $q->where('id', '<=', $maxId))
            ->groupBy('account_id')->selectRaw("account_id, SUM($dr) - SUM($cr) as b")
            ->pluck('b', 'account_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * AG-093 control.json (backups review F2): sum of debits and sum of credits (cents) per branch code, over lines
     * with id <= $maxId when given. The books balance when debit = credit in every branch (V3).
     *
     * @return array<string, array{debit: int, credit: int}>
     */
    public static function sumsByBranch(?int $maxId = null): array
    {
        $dr = Money::sqlCents('le.debit');
        $cr = Money::sqlCents('le.credit');

        return DB::table('ledger_entries as le')->join('branches as b', 'b.id', '=', 'le.branch_id')
            ->when($maxId !== null, fn ($q) => $q->where('le.id', '<=', $maxId))
            ->groupBy('le.branch_id', 'b.code')->orderBy('b.code')
            ->selectRaw("b.code as branch, SUM($dr) as debit, SUM($cr) as credit")->get()
            ->mapWithKeys(fn ($r) => [(string) $r->branch => ['debit' => (int) $r->debit, 'credit' => (int) $r->credit]])->all();
    }

    /**
     * V10 (AG-033 R11): every active mark is on a bank-account line of its own account and branch, its transaction is
     * not void, at most one active mark per line, and every completed statement's stored reconciled figure equals
     * reconciled(A, S) now, its statement balance, and book - uncleared receipts + uncleared payments + cleared booked
     * later as stored. Offenders as [check, account, detail].
     *
     * @return Collection<int, array{check: string, account: string, detail: string}>
     */
    public static function bankReconciliation(): Collection
    {
        $out = collect();
        $label = fn ($r) => trim(($r->branch ?? '').' '.($r->account ?? "#{$r->account_id}"));
        $marks = DB::table('bank_reconciliation_marks as m')
            ->leftJoin('ledger_entries as le', 'le.id', '=', 'm.ledger_entry_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'm.account_id')
            ->leftJoin('accounts as la', 'la.id', '=', 'le.account_id')
            ->leftJoin('transactions as t', 't.id', '=', 'le.transaction_id')
            ->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->whereNull('m.unmarked_at')
            ->where(fn ($w) => $w->whereNull('le.id')->orWhereColumn('le.account_id', '!=', 'm.account_id')->orWhereColumn('le.branch_id', '!=', 'm.branch_id')
                ->orWhereColumn('a.branch_id', '!=', 'm.branch_id')->orWhere('la.type', '!=', 'bank')->orWhere('t.status', 'void'))
            ->orderBy('m.id')
            ->get(['m.id', 'm.account_id', 'm.ledger_entry_id', 'a.name as account', 'b.code as branch', 'la.type', 't.status', 't.ref_no',
                'le.account_id as line_account', 'le.branch_id as line_branch', 'm.branch_id']);
        foreach ($marks as $r) {
            $problem = match (true) {
                $r->line_account === null => 'line does not exist',
                (int) $r->line_account !== (int) $r->account_id => 'line is on another account',
                (int) $r->line_branch !== (int) $r->branch_id => 'line or account is in another branch',
                $r->type !== 'bank' => 'line is not on a bank account',
                $r->status === 'void' => 'transaction is void',
                default => 'account is in another branch',
            };
            $out->push(['check' => 'active mark', 'account' => $label($r), 'detail' => "mark #{$r->id} on line #{$r->ledger_entry_id} ".($r->ref_no ?? '').": {$problem}"]);
        }

        $dupes = DB::table('bank_reconciliation_marks')->whereNull('unmarked_at')->groupBy('ledger_entry_id')
            ->havingRaw('COUNT(*) > 1')->selectRaw('ledger_entry_id, COUNT(*) as n')->get();
        foreach ($dupes as $d) {
            $out->push(['check' => 'one active mark per line', 'account' => '', 'detail' => "line #{$d->ledger_entry_id}: {$d->n} active marks"]);
        }

        $recon = app(BankReconciliation::class);
        $statements = DB::table('bank_statements as s')->join('accounts as a', 'a.id', '=', 's.account_id')->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.status', 'completed')->orderBy('s.id')
            ->get(['s.*', 'a.name as account', 'b.code as branch']);
        foreach ($statements as $s) {
            $date = substr((string) $s->statement_date, 0, 10);
            $now = $recon->reconciledCents((int) $s->account_id, $date);
            $stored = (int) $s->book_balance_cents - (int) $s->uncleared_receipts_cents + (int) $s->uncleared_payments_cents + (int) $s->cleared_booked_later_cents;
            $f = fn (int $c) => Money::format(Money::fromCents($c));
            foreach ([
                'reconciled now' => $now,
                'statement balance' => (int) $s->statement_balance_cents,
                'book - uncleared receipts + uncleared payments + cleared booked later (stored)' => $stored,
            ] as $what => $value) {
                if ($value !== (int) $s->reconciled_cents) {
                    $out->push(['check' => 'completed statement', 'account' => $label($s),
                        'detail' => "statement #{$s->id} of {$date}: stored reconciled {$f((int) $s->reconciled_cents)} != {$what} {$f($value)}"]);
                }
            }
        }

        return $out->values();
    }

    /**
     * V11 (AG-033d R11): every active check is on a completed statement of its own branch and account; at most one active
     * check per statement; no checker is a preparer of the statement (completed it or ticked a line frozen in it); every
     * cancelled check's statement is undone; every ended check has ended_by and ended_at; every superseded check points to
     * a check on the same statement with a later checked_at. Offenders as [check, account, detail].
     *
     * @return Collection<int, array{check: string, account: string, detail: string}>
     */
    public static function reconciliationChecks(): Collection
    {
        $out = collect();
        $rows = DB::table('bank_statement_checks as c')
            ->leftJoin('bank_statements as s', 's.id', '=', 'c.bank_statement_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'c.account_id')
            ->leftJoin('branches as b', 'b.id', '=', 'c.branch_id')
            ->leftJoin('bank_statement_checks as n', 'n.id', '=', 'c.superseded_by_id')
            ->orderBy('c.id')
            ->get(['c.id', 'c.status', 'c.branch_id', 'c.account_id', 'c.checked_by', 'c.checked_at', 'c.ended_by', 'c.ended_at', 'c.superseded_by_id',
                'c.bank_statement_id', 's.status as s_status', 's.branch_id as s_branch', 's.account_id as s_account', 's.completed_by', 's.statement_date',
                'a.name as account', 'b.code as branch', 'n.bank_statement_id as n_statement', 'n.checked_at as n_checked_at']);
        $ticked = DB::table('bank_reconciliation_marks')->whereNotNull('statement_id')->select('statement_id', 'marked_by')->distinct()->get()
            ->map(fn ($m) => $m->statement_id.':'.$m->marked_by)->flip();
        $label = fn ($r) => trim(($r->branch ?? '').' '.($r->account ?? "#{$r->account_id}"));
        foreach ($rows as $r) {
            $problems = [];
            $on = "check #{$r->id} of statement #{$r->bank_statement_id}".($r->statement_date ? ' ('.substr((string) $r->statement_date, 0, 10).')' : '');
            if ($r->status === 'active') {
                if ($r->s_status !== 'completed') {
                    $problems[] = 'active check on a statement that is not completed';
                }
                if ((int) $r->s_branch !== (int) $r->branch_id || (int) $r->s_account !== (int) $r->account_id) {
                    $problems[] = 'active check in another branch or account than its statement';
                }
            }
            if ((int) $r->checked_by === (int) $r->completed_by || isset($ticked[$r->bank_statement_id.':'.$r->checked_by])) {
                $problems[] = 'the checker is a preparer of the statement';
            }
            if ($r->status === 'cancelled' && $r->s_status !== 'undone') {
                $problems[] = 'cancelled check on a statement that is not undone';
            }
            if ($r->status !== 'active' && ($r->ended_by === null || $r->ended_at === null)) {
                $problems[] = 'ended check without ended_by / ended_at';
            }
            if ($r->status === 'superseded' && ($r->superseded_by_id === null || (int) $r->n_statement !== (int) $r->bank_statement_id
                || (string) $r->n_checked_at < (string) $r->checked_at)) {
                $problems[] = 'superseded check not replaced by a later check on the same statement';
            }
            foreach ($problems as $p) {
                $out->push(['check' => 'check', 'account' => $label($r), 'detail' => "{$on}: {$p}"]);
            }
        }
        $dupes = DB::table('bank_statement_checks')->where('status', 'active')->groupBy('bank_statement_id')
            ->havingRaw('COUNT(*) > 1')->selectRaw('bank_statement_id, COUNT(*) as n')->get();
        foreach ($dupes as $d) {
            $out->push(['check' => 'one active check per statement', 'account' => '', 'detail' => "statement #{$d->bank_statement_id}: {$d->n} active checks"]);
        }

        return $out->values();
    }
}
