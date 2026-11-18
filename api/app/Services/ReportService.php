<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Support\BranchContext;
use App\Support\Money;
use App\Support\ReportParams;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * AG-082..085 reports. Read-only: nothing here posts money.
 *
 * Counting basis (spec R2/R3 + accounting review finding 1):
 *  - originals with status posted or reversed count on their own date, in their own column;
 *  - a reversal (type `reversal`, `reversal_of_id` -> original) counts on its own date in the ORIGINAL's
 *    column with the opposite sign. For ledger entries this means: classify by the original's type and by the
 *    side the original entry had on that account (the reversal entry sits on the other side), amount negated.
 *  - voided entries have no ledger entries and their transaction status is `void`: never counted.
 *
 * LedgerEntry has no branch scope and a transfer's `transactions.branch_id` is the sending branch, so every query
 * here runs on the query builder (no global scopes) and filters on the branch set resolved in params().
 */
class ReportService
{
    public const NAMES = ['income-expense', 'branch-comparison', 'transfers', 'cash-book'];

    public const EXPORT_CAP = 5000;

    public const MAX_DAYS = 366;

    public const CASH_BOOK_MAX_DAYS = 31;

    public const MOVEMENT_TYPES = [Transaction::OPENING_ALLOCATION, Transaction::FUND_ALLOCATION, Transaction::FUND_RETURN, Transaction::BRANCH_TRANSFER];

    public const MOVEMENT_LABELS = [
        Transaction::OPENING_ALLOCATION => 'Opening allocation',
        Transaction::FUND_ALLOCATION => 'Top-up',
        Transaction::FUND_RETURN => 'Fund return',
        Transaction::BRANCH_TRANSFER => 'Branch transfer',
    ];

    public const BASIS = 'Posted entries incl. reversals; voided entries excluded';

    // ------------------------------------------------------------------ filters (R1)

    /** Validate the common filters and resolve the branch set. Throws 422 (validation) or 403 (other branch). */
    public function params(string $name, array $input): ReportParams
    {
        $rules = [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'branches' => 'nullable|array',
            'branches.*' => 'integer|distinct',
        ];
        $rules += match ($name) {
            'income-expense' => ['type' => ['nullable', Rule::in(['all', 'income', 'expense'])]],
            'transfers' => ['types' => 'nullable|array', 'types.*' => ['string', Rule::in(self::MOVEMENT_TYPES)], 'page' => 'nullable|integer|min:1'],
            'cash-book' => ['accounts' => 'nullable|array', 'accounts.*' => 'integer|distinct'],
            default => [],
        };
        $d = validator($input, $rules)->validate();

        $days = CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) + 1;
        $max = $name === 'cash-book' ? self::CASH_BOOK_MAX_DAYS : self::MAX_DAYS;
        if ($days > $max) {
            throw ValidationException::withMessages(['to' => "The date range may not be longer than {$max} days."]);
        }

        $requested = array_map('intval', $d['branches'] ?? []);
        $found = Branch::whereIn('id', $requested)->get()->keyBy('id');
        if ($found->count() !== count($requested)) {
            throw ValidationException::withMessages(['branches' => 'Unknown branch.']);
        }

        $ctx = app(BranchContext::class);
        if ($ctx->isScoped()) {
            foreach ($requested as $id) {
                if ($id !== $ctx->branchId) {
                    abort(403, "You do not have access to branch {$found[$id]->code}.");
                }
            }
            $branches = Branch::whereKey($ctx->branchId)->get();
            $all = false;
            $subset = false;
        } else {
            if ($name === 'cash-book' && ! $requested) {
                throw ValidationException::withMessages(['branches' => 'Select at least one branch']);
            }
            $branches = $requested ? $found->values() : Branch::all();
            $all = ! $requested;
            $subset = $requested && count($requested) < Branch::count();
        }
        $branches = $branches->sortBy(fn (Branch $b) => [$b->is_main ? 0 : 1, $b->code])->values();

        $extra = match ($name) {
            'income-expense' => ['type' => $d['type'] ?? 'all'],
            'transfers' => ['types' => $d['types'] ?? self::MOVEMENT_TYPES, 'page' => (int) ($d['page'] ?? 1)],
            'cash-book' => ['accounts' => array_map('intval', $d['accounts'] ?? [])],
            default => [],
        };
        if ($name === 'cash-book' && $extra['accounts']) {
            $ok = DB::table('accounts')->whereIn('id', $extra['accounts'])->whereIn('branch_id', $branches->pluck('id'))->count();
            if ($ok !== count($extra['accounts'])) {
                throw ValidationException::withMessages(['accounts' => 'Unknown account.']);
            }
        }

        return new ReportParams($d['from'], $d['to'], $branches, $all, $subset, $extra);
    }

    /** Header data shared by every report (screen, PDF, Excel). */
    public function meta(ReportParams $p): array
    {
        return [
            'from' => $p->from,
            'to' => $p->to,
            'branches' => $p->branches->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'status' => $b->status])->values()->all(),
            'all_branches' => $p->allBranches,
            'subset' => $p->subset,
            'provisional' => $this->provisional($p),
        ];
    }

    /** Finding 7: true when any month in the range is not locked for any branch in scope. */
    public function provisional(ReportParams $p): bool
    {
        $months = [];
        for ($m = CarbonImmutable::parse($p->from)->startOfMonth(); $m->format('Y-m') <= substr($p->to, 0, 7); $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $locks = PeriodLock::whereIn('period', $months)->get(['branch_id', 'period']);
        foreach ($months as $month) {
            $global = $locks->where('period', $month)->whereNull('branch_id')->isNotEmpty();
            foreach ($p->branchIds() as $id) {
                if (! $global && $locks->where('period', $month)->where('branch_id', $id)->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ AG-082 Income & Expense (R4)

    public function incomeExpense(ReportParams $p): array
    {
        $types = $p->extra['type'] === 'all' ? [Transaction::INCOME, Transaction::EXPENSE] : [$p->extra['type']];
        $x = $this->cents('t.amount');

        $rows = $this->countedTransactions($types)
            ->whereIn('t.branch_id', $p->branchIds())
            ->whereDate('t.txn_date', '>=', $p->from)->whereDate('t.txn_date', '<=', $p->to)
            ->groupByRaw('COALESCE(o.type, t.type), COALESCE(o.category_id, t.category_id)')
            ->selectRaw("COALESCE(o.type, t.type) as etype, COALESCE(o.category_id, t.category_id) as cat_id, SUM(CASE WHEN t.type = 'reversal' THEN -$x ELSE $x END) as cents")
            ->get();

        $cats = Category::whereIn('id', $rows->pluck('cat_id'))->get()->keyBy('id');
        $parents = Category::whereIn('id', $cats->pluck('parent_id')->filter())->get()->keyBy('id');

        $out = $this->meta($p);
        foreach ([Transaction::INCOME, Transaction::EXPENSE] as $type) {
            $out[$type] = in_array($type, $types, true)
                ? $this->categoryTree($rows->where('etype', $type), $cats, $parents)
                : null;
        }
        $out['net'] = $p->extra['type'] === 'all'
            ? Money::fromCents(Money::toCents($out['income']['total']) - Money::toCents($out['expense']['total']))
            : null;

        return $out;
    }

    /** Detail rows in an income/expense export: one per sub-category line. */
    public function incomeExpenseRows(array $r): int
    {
        return collect([$r['income'], $r['expense']])->filter()->sum(fn ($s) => collect($s['categories'])->sum(fn ($c) => count($c['subs'])));
    }

    private function categoryTree(Collection $rows, Collection $cats, Collection $parents): array
    {
        $total = (int) $rows->sum(fn ($r) => (int) $r->cents);
        $groups = [];
        foreach ($rows as $r) {
            $cat = $cats[$r->cat_id];
            $parent = $cat->parent_id ? $parents[$cat->parent_id] : $cat;
            $sub = $cat->parent_id ? $cat : null;
            $groups[$parent->id] ??= ['cat' => $parent, 'cents' => 0, 'subs' => []];
            $groups[$parent->id]['cents'] += (int) $r->cents;
            $groups[$parent->id]['subs'][] = ['cat' => $sub, 'cents' => (int) $r->cents];
        }
        $sort = fn (array $a, array $b) => [$b['cents'], $a['name']] <=> [$a['cents'], $b['name']];

        $categories = array_map(function ($g) use ($sort) {
            $subs = array_map(fn ($s) => [
                'id' => $s['cat']?->id,
                'name' => $s['cat']?->name ?? '(no sub-category)',
                'is_active' => $s['cat']?->is_active ?? $g['cat']->is_active,
                'cents' => $s['cents'],
            ], $g['subs']);
            usort($subs, $sort);

            return [
                'id' => $g['cat']->id,
                'name' => $g['cat']->name,
                'is_active' => $g['cat']->is_active,
                'cents' => $g['cents'],
                'subs' => $subs,
            ];
        }, array_values($groups));
        usort($categories, $sort);

        $fmt = fn (array $line) => array_diff_key($line, ['cents' => 1, 'subs' => 1])
            + ['amount' => Money::fromCents($line['cents']), 'percent' => self::percent($line['cents'], $total)];

        return [
            'total' => Money::fromCents($total),
            'categories' => array_map(fn ($c) => $fmt($c) + ['subs' => array_map($fmt, $c['subs'])], $categories),
        ];
    }

    /**
     * Share of the type total, 1 decimal, rounded half away from zero (finding 9), from integer cents.
     * Null ("—") when the total is zero or negative.
     */
    public static function percent(int $cents, int $total): ?string
    {
        if ($total <= 0) {
            return null;
        }
        $num = abs($cents) * 1000;
        $tenths = intdiv($num * 2 + $total, 2 * $total);
        $sign = $cents < 0 && $tenths > 0 ? '-' : '';

        return $sign.intdiv($tenths, 10).'.'.($tenths % 10);
    }

    // ------------------------------------------------------------------ AG-083 Branch Comparison (R5 + finding 2)

    public const COMPARISON_KEYS = ['opening', 'capital', 'funds_received', 'income', 'expenses', 'transfers_in', 'transfers_out', 'closing'];

    public function branchComparison(ReportParams $p): array
    {
        $ids = $p->branchIds();
        $dr = $this->cents('le.debit');
        $cr = $this->cents('le.credit');

        $before = fn (string $op, string $date) => DB::table('ledger_entries as le')->whereIn('le.branch_id', $ids)->whereDate('le.entry_date', $op, $date)
            ->groupBy('le.branch_id')->selectRaw("le.branch_id, SUM($dr - $cr) as cents")->pluck('cents', 'branch_id');
        $opening = $before('<', $p->from);
        $ledgerClosing = $before('<=', $p->to);

        // Effective side: a reversal entry is booked on the original entry's side, negated.
        $moves = DB::table('ledger_entries as le')
            ->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->leftJoin('transactions as o', 'o.id', '=', 't.reversal_of_id')
            ->whereIn('le.branch_id', $ids)
            ->whereIn('t.status', ['posted', 'reversed'])
            ->whereDate('le.entry_date', '>=', $p->from)->whereDate('le.entry_date', '<=', $p->to)
            ->groupByRaw('le.branch_id, COALESCE(o.type, t.type)')
            ->selectRaw("le.branch_id, COALESCE(o.type, t.type) as etype,
                SUM(CASE WHEN t.type = 'reversal' THEN -$cr ELSE $dr END) as dr,
                SUM(CASE WHEN t.type = 'reversal' THEN -$dr ELSE $cr END) as cr")
            ->get()->groupBy('branch_id');

        $rows = [];
        $total = array_fill_keys(self::COMPARISON_KEYS, 0);
        foreach ($p->branches as $b) {
            $c = array_fill_keys(self::COMPARISON_KEYS, 0);
            $c['opening'] = (int) ($opening[$b->id] ?? 0);
            foreach ($moves[$b->id] ?? [] as $m) {
                $in = (int) $m->dr;
                $out = (int) $m->cr;
                match ($m->etype) {
                    Transaction::CAPITAL => $c['capital'] += $in - $out,
                    Transaction::OPENING_ALLOCATION, Transaction::FUND_ALLOCATION => [$c['funds_received'] += $in, $c['transfers_out'] += $out],
                    Transaction::FUND_RETURN, Transaction::BRANCH_TRANSFER => [$c['transfers_in'] += $in, $c['transfers_out'] += $out],
                    Transaction::INCOME => $c['income'] += $in - $out,
                    Transaction::EXPENSE => $c['expenses'] += $out - $in,
                    // Internal transfers net to zero inside the branch (both accounts are in it) and are not shown.
                    // Anything else is not expected; the reconciliation below would report it.
                    default => null,
                };
            }
            $c['closing'] = $c['opening'] + $c['capital'] + $c['funds_received'] + $c['income'] - $c['expenses'] + $c['transfers_in'] - $c['transfers_out'];
            $expected = (int) ($ledgerClosing[$b->id] ?? 0);
            if ($c['closing'] !== $expected) {
                Log::error('Branch comparison does not reconcile', ['branch' => $b->code, 'from' => $p->from, 'to' => $p->to, 'computed' => $c['closing'], 'ledger' => $expected]);
                throw new RuntimeException("Branch comparison for {$b->code} does not reconcile with the ledger.");
            }
            foreach ($c as $k => $v) {
                $total[$k] += $v;
            }
            $rows[] = ['branch' => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'status' => $b->status]] + array_map([Money::class, 'fromCents'], $c);
        }

        return $this->meta($p) + ['rows' => $rows, 'total' => array_map([Money::class, 'fromCents'], $total)];
    }

    // ------------------------------------------------------------------ AG-084 Transfers & Allocations (R6)

    /** @param  bool  $all  true = every movement (export), false = page of 50 (screen) */
    public function transfers(ReportParams $p, bool $all = false): array
    {
        $x = $this->cents('t.amount');
        $base = $this->movementQuery($p);

        $list = (clone $base)
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->leftJoin('branches as fb', 'fb.id', '=', 'fa.branch_id')
            ->leftJoin('branches as tb', 'tb.id', '=', 'ta.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->orderByDesc('t.txn_date')->orderByDesc('t.id')
            ->selectRaw("t.id, t.ref_no, t.type, t.txn_date, t.status, t.description, $x as cents, o.ref_no as orig_ref, o.type as orig_type,
                fb.code as from_branch, fa.name as from_account, tb.code as to_branch, ta.name as to_account, u.name as created_by");

        $map = fn ($r) => [
            'id' => $r->id,
            'ref_no' => $r->ref_no,
            'type' => $r->type,
            'movement_type' => $r->orig_type ?? $r->type,
            'txn_date' => substr($r->txn_date, 0, 10),
            'from' => ['branch_code' => $r->from_branch, 'account' => $r->from_account],
            'to' => ['branch_code' => $r->to_branch, 'account' => $r->to_account],
            // Finding 6: reversal rows are negative so the column foots to Net moved.
            'amount' => Money::fromCents($r->type === Transaction::REVERSAL ? -(int) $r->cents : (int) $r->cents),
            'status' => $r->type === Transaction::REVERSAL ? 'reversal' : $r->status,
            'reversal_of_ref' => $r->orig_ref,
            'description' => $r->description,
            'created_by' => $r->created_by,
        ];

        if ($all) {
            $data = $list->get()->map($map)->all();
            $movements = ['data' => $data, 'current_page' => 1, 'last_page' => 1, 'total' => count($data)];
        } else {
            $page = $list->paginate(50, ['*'], 'page', $p->extra['page'] ?? 1);
            $movements = ['data' => collect($page->items())->map($map)->all(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
        }

        // Matrix (A,B): originals A->B minus reversals of A->B originals. A reversal keeps the original's branch_id
        // (sender) and related_branch_id (receiver), so the cell is the transaction's own pair, signed.
        $cells = (clone $base)->groupBy('t.branch_id', 't.related_branch_id')
            ->selectRaw("t.branch_id as from_id, t.related_branch_id as to_id, SUM(CASE WHEN t.type = 'reversal' THEN -$x ELSE $x END) as cents")
            ->get();

        $inScope = $p->branchIds();
        $ids = collect($inScope)->merge($cells->pluck('from_id'))->merge($cells->pluck('to_id'))->map(fn ($v) => (int) $v)->unique();
        $branches = Branch::whereIn('id', $ids)->get()->sortBy(fn ($b) => [$b->is_main ? 0 : 1, $b->code])->values();
        $amount = [];
        foreach ($cells as $c) {
            $amount[(int) $c->from_id][(int) $c->to_id] = ($amount[(int) $c->from_id][(int) $c->to_id] ?? 0) + (int) $c->cents;
        }
        $out = [];
        $sent = $received = array_fill_keys($ids->all(), 0);
        foreach ($branches as $a) {
            foreach ($branches as $b) {
                // Only pairs that involve a branch in scope are filled (branch user: own row and column).
                if ($a->id === $b->id || (! in_array($a->id, $inScope, true) && ! in_array($b->id, $inScope, true))) {
                    continue;
                }
                $v = $amount[$a->id][$b->id] ?? 0;
                $sent[$a->id] += $v;
                $received[$b->id] += $v;
                $out[] = ['from_id' => $a->id, 'to_id' => $b->id, 'amount' => Money::fromCents($v)];
            }
        }
        $fmt = fn (array $m) => collect($m)->map(fn ($v) => Money::fromCents($v))->all();
        $net = [];
        foreach ($sent as $id => $v) {
            $net[$id] = $received[$id] - $v;
        }

        return $this->meta($p) + [
            'movements' => $movements,
            'net_moved' => Money::fromCents((int) $cells->sum(fn ($c) => (int) $c->cents)),
            'matrix' => [
                'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'status' => $b->status, 'in_scope' => in_array($b->id, $inScope, true)])->all(),
                'cells' => $out,
                'sent' => $fmt($sent),
                'received' => $fmt($received),
                'net' => $fmt($net),
            ],
        ];
    }

    public function transferRows(ReportParams $p): int
    {
        return $this->movementQuery($p)->count();
    }

    private function movementQuery(ReportParams $p): Builder
    {
        $types = $p->extra['types'] ?? self::MOVEMENT_TYPES;
        $ids = $p->branchIds();

        return $this->countedTransactions(self::MOVEMENT_TYPES)
            ->where(fn ($w) => $w->whereIn('t.type', $types)->orWhereIn('o.type', $types))
            ->where(fn ($w) => $w->whereIn('t.branch_id', $ids)->orWhereIn('t.related_branch_id', $ids))
            ->whereDate('t.txn_date', '>=', $p->from)->whereDate('t.txn_date', '<=', $p->to);
    }

    // ------------------------------------------------------------------ AG-085 Daily Cash Book (R7)

    public function cashBook(ReportParams $p): array
    {
        $accounts = Account::withoutGlobalScopes()->with('branch:id,code,name,is_main')
            ->whereIn('branch_id', $p->branchIds())
            ->when($p->extra['accounts'] ?? [], fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get()
            ->sortBy(fn ($a) => [$a->branch->is_main ? 0 : 1, $a->branch->code, $a->name])->values();
        $ids = $accounts->pluck('id')->all();
        $dr = $this->cents('le.debit');
        $cr = $this->cents('le.credit');

        $opening = DB::table('ledger_entries as le')->whereIn('le.account_id', $ids)->whereDate('le.entry_date', '<', $p->from)
            ->groupBy('le.account_id')->selectRaw("le.account_id, SUM($dr - $cr) as cents")->pluck('cents', 'account_id');

        $entries = $this->cashBookEntries($p, $ids)
            ->selectRaw("le.account_id, le.entry_date, $dr as dr, $cr as cr, t.id as txn_id, t.ref_no, t.type, t.status, t.payee_payer, t.description, o.ref_no as orig_ref")
            ->orderBy('le.entry_date')->orderBy('le.id')
            ->get();

        // Finding 8: cross-references between an original and its reversal.
        $reversedBy = DB::table('transactions')->whereIn('reversal_of_id', $entries->where('status', 'reversed')->pluck('txn_id')->unique())
            ->pluck('ref_no', 'reversal_of_id');
        $byAccount = $entries->groupBy('account_id');

        $out = [];
        foreach ($accounts as $a) {
            $open = (int) ($opening[$a->id] ?? 0);
            $balance = $open;
            $receipts = $payments = 0;
            $days = [];
            foreach (($byAccount[$a->id] ?? collect())->groupBy(fn ($e) => substr($e->entry_date, 0, 10)) as $date => $lines) {
                $dayOpen = $balance;
                $in = (int) $lines->sum(fn ($l) => (int) $l->dr);
                $outC = (int) $lines->sum(fn ($l) => (int) $l->cr);
                $balance += $in - $outC;
                $receipts += $in;
                $payments += $outC;
                $days[] = [
                    'date' => $date,
                    'opening' => Money::fromCents($dayOpen),
                    'receipts' => Money::fromCents($in),
                    'payments' => Money::fromCents($outC),
                    'closing' => Money::fromCents($balance),
                    'lines' => $lines->map(fn ($l) => [
                        'ref_no' => $l->ref_no,
                        'type' => $l->type,
                        'description' => $this->lineText($l, $reversedBy),
                        'receipt' => Money::fromCents((int) $l->dr),
                        'payment' => Money::fromCents((int) $l->cr),
                    ])->values()->all(),
                ];
            }
            $out[] = [
                'account' => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'branch_code' => $a->branch->code, 'branch_name' => $a->branch->name, 'status' => $a->status],
                'opening' => Money::fromCents($open),
                'receipts' => Money::fromCents($receipts),
                'payments' => Money::fromCents($payments),
                'closing' => Money::fromCents($balance),
                'days' => $days,
            ];
        }

        return $this->meta($p) + ['accounts' => $out];
    }

    public function cashBookRows(ReportParams $p): int
    {
        $ids = DB::table('accounts')->whereIn('branch_id', $p->branchIds())
            ->when($p->extra['accounts'] ?? [], fn ($q, $a) => $q->whereIn('id', $a))->pluck('id')->all();

        return $this->cashBookEntries($p, $ids)->count();
    }

    private function cashBookEntries(ReportParams $p, array $accountIds): Builder
    {
        return DB::table('ledger_entries as le')
            ->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->leftJoin('transactions as o', 'o.id', '=', 't.reversal_of_id')
            ->whereIn('le.account_id', $accountIds)
            ->whereIn('t.status', ['posted', 'reversed'])
            ->whereDate('le.entry_date', '>=', $p->from)->whereDate('le.entry_date', '<=', $p->to);
    }

    private function lineText(object $l, Collection $reversedBy): string
    {
        $text = (string) ($l->payee_payer ?: $l->description);
        if ($l->type === Transaction::REVERSAL) {
            $ref = "Reversal of {$l->orig_ref}";

            return $text === '' || $text === $ref ? $ref : "{$ref}: {$text}";
        }
        if ($l->status === 'reversed' && isset($reversedBy[$l->txn_id])) {
            return trim("{$text} (reversed by {$reversedBy[$l->txn_id]})");
        }

        return $text;
    }

    // ------------------------------------------------------------------ shared

    /**
     * Transactions that count (R2) for the given original types: originals posted/reversed plus their posted reversals.
     * Joins the original as `o` (null for originals).
     */
    public function countedTransactions(array $types): Builder
    {
        return DB::table('transactions as t')
            ->leftJoin('transactions as o', 'o.id', '=', 't.reversal_of_id')
            ->where(fn ($w) => $w
                ->where(fn ($a) => $a->whereIn('t.type', $types)->whereIn('t.status', ['posted', 'reversed']))
                ->orWhere(fn ($a) => $a->where('t.type', Transaction::REVERSAL)->where('t.status', 'posted')->whereIn('o.type', $types)));
    }

    /** SQL for a decimal(15,2) column as exact integer cents (works on MySQL and SQLite). */
    public function cents(string $column): string
    {
        $int = DB::connection()->getDriverName() === 'mysql' ? 'SIGNED' : 'INTEGER';

        return "CAST(ROUND($column * 100) AS $int)";
    }
}
