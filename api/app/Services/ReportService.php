<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\YearEndClose;
use App\Support\BranchContext;
use App\Support\FiscalYear;
use App\Support\Money;
use App\Support\ReportParams;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
 *  - voided entries net to zero (contra entries on the original date) and are excluded by status: never counted.
 *
 * LedgerEntry has no branch scope and a transfer's `transactions.branch_id` is the sending branch, so every query
 * here runs on the query builder (no global scopes) and filters on the branch set resolved in params().
 */
class ReportService
{
    /**
     * AG-052: the receipt columns exist. Checked once per process (review minor 5); forgotten whenever migrations run
     * (AppServiceProvider), so a rollback is seen.
     */
    private static ?bool $receiptColumns = null;

    public static function forgetSchemaCache(): void
    {
        self::$receiptColumns = null;
    }

    public const NAMES = ['income-expense', 'branch-comparison', 'transfers', 'cash-book', 'trial-balance'];

    /** Excel export and cash book screen cap (detail rows). */
    public const EXPORT_CAP = 5000;

    /** dompdf needs far more memory per row than Excel: PDF has its own lower cap (QA bug 1, AC 22). */
    public const PDF_EXPORT_CAP = 1000;

    public const TYPE_LABELS = [
        Transaction::CAPITAL => 'Capital',
        Transaction::OPENING_ALLOCATION => 'Opening balance',
        Transaction::FUND_ALLOCATION => 'Fund allocation',
        Transaction::FUND_RETURN => 'Fund return',
        Transaction::BRANCH_TRANSFER => 'Branch transfer',
        Transaction::INTERNAL_TRANSFER => 'Internal transfer',
        Transaction::INCOME => 'Income',
        Transaction::EXPENSE => 'Expense',
        Transaction::REVERSAL => 'Reversal',
        Transaction::YEAR_END_CLOSE => 'Year-end close',
    ];

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

    /** Double-entry S2 basis line of the trial balance (before AG-092; the fiscal-year basis is tbBasis()). */
    public const TB_BASIS = 'All posted lines; period columns exclude voided entries';

    /** Settings spec R6: the virtual equity row of income and expense of earlier years that are not closed. */
    public const EARLIER_RESULT = 'Result of earlier years not yet closed';

    public const TB_CLOSING_ONLY_AT_YEAR_END = 'Closing entries can be included only for the last day of a closed fiscal year.';

    public const TB_GROUPS = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity',
        'interbranch' => 'Inter-branch (eliminated on consolidation)', 'income' => 'Income', 'expense' => 'Expenses'];

    public const TB_CONSOLIDATED_MESSAGE = 'Select at least two branches in All branches mode';

    public static function basis(string $name): string
    {
        return $name === 'trial-balance' ? self::TB_BASIS : self::BASIS;
    }

    /** Same labels as the web app's TYPE_LABELS. */
    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

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
            'transfers' => ['types' => 'nullable|array', 'types.*' => ['string', Rule::in(self::MOVEMENT_TYPES)], 'page' => 'nullable|integer|min:1',
                // AG-052 S5: receipt states, or in_transit (R14) on its own.
                'receipt' => ['bail', 'nullable', 'array', TransferReceipts::filterRule([...array_keys(TransferReceipts::LABELS), TransferReceipts::IN_TRANSIT])]],
            'cash-book' => ['accounts' => 'nullable|array', 'accounts.*' => 'integer|distinct'],
            'trial-balance' => ['view' => ['nullable', Rule::in(['branch', 'consolidated'])], 'closing_entries' => 'nullable|boolean'],
            default => [],
        };
        $d = validator($input, $rules)->validate();
        if ($name === 'transfers' && in_array(TransferReceipts::IN_TRANSIT, $d['receipt'] ?? [], true) && count(array_unique($d['receipt'])) > 1) {
            throw ValidationException::withMessages(['receipt' => 'In transit at end date cannot be combined with other receipt values.']);
        }

        $fy = null;
        if ($name === 'trial-balance') {
            // Settings spec R5: one fiscal year per trial balance (replaces the 366-day rule for this report).
            $fy = app(Settings::class)->fiscalYear($d['from']);
            if (! $fy->contains($d['to'])) {
                throw ValidationException::withMessages(['to' => "From and To must be in the same fiscal year ({$fy->label()}: {$fy->start()} to {$fy->end()})."]);
            }
            if (filter_var($d['closing_entries'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && ($d['to'] !== $fy->end() || ! YearEndClose::active()->where('fiscal_year', $fy->startYear)->exists())) {
                throw ValidationException::withMessages(['closing_entries' => self::TB_CLOSING_ONLY_AT_YEAR_END]);
            }
        } else {
            $days = CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) + 1;
            $max = $name === 'cash-book' ? self::CASH_BOOK_MAX_DAYS : self::MAX_DAYS;
            if ($days > $max) {
                throw ValidationException::withMessages(['to' => "The date range may not be longer than {$max} days."]);
            }
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
            'transfers' => ['types' => $d['types'] ?? self::MOVEMENT_TYPES, 'page' => (int) ($d['page'] ?? 1),
                'receipt' => array_values(array_unique(array_map('trim', $d['receipt'] ?? [])))],
            'cash-book' => ['accounts' => array_map('intval', $d['accounts'] ?? [])],
            'trial-balance' => ['view' => $d['view'] ?? 'branch', 'closing_entries' => filter_var($d['closing_entries'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'fiscal_year' => $fy->startYear],
            default => [],
        };
        if ($name === 'trial-balance' && $extra['view'] === 'consolidated' && ($ctx->isScoped() || $branches->count() < 2)) {
            throw ValidationException::withMessages(['view' => self::TB_CONSOLIDATED_MESSAGE]);
        }
        if ($name === 'cash-book' && $extra['accounts']) {
            $ok = DB::table('accounts')->whereIn('type', Account::MONEY_TYPES)->whereIn('id', $extra['accounts'])->whereIn('branch_id', $branches->pluck('id'))->count();
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
            'company' => app(Settings::class)->companyName(),
            'currency' => app(Settings::class)->currencyCode(),
            'from' => $p->from,
            'to' => $p->to,
            'branches' => $p->branches->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'status' => $b->status])->values()->all(),
            'all_branches' => $p->allBranches,
            'subset' => $p->subset,
            'provisional' => $this->provisional($p),
        ];
    }

    /**
     * Finding 7: true when any month in the range is not locked for any branch in scope.
     * Locks are cumulative (controls review finding 1): a month is locked for B when an active lock for B or for all
     * branches exists for that month or a later one.
     */
    public function provisional(ReportParams $p): bool
    {
        $through = PeriodLock::closedThrough();
        $last = substr($p->to, 0, 7);
        foreach ($p->branchIds() as $id) {
            $closed = max((string) $through['global'], (string) ($through['branches'][$id] ?? ''));
            if ($closed < $last) {
                return true;
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
        // Categories review finding 8: a "<code> only" tag on branch-only subs when more than one branch is selected.
        $tagBranches = count($p->branchIds()) > 1 ? Branch::pluck('code', 'id') : null;
        foreach ([Transaction::INCOME, Transaction::EXPENSE] as $type) {
            $out[$type] = in_array($type, $types, true)
                ? $this->categoryTree($rows->where('etype', $type), $cats, $parents, $tagBranches)
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

    private function categoryTree(Collection $rows, Collection $cats, Collection $parents, ?Collection $tagBranches = null): array
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

        $categories = array_map(function ($g) use ($sort, $tagBranches) {
            $subs = array_map(fn ($s) => [
                'id' => $s['cat']?->id,
                'name' => $s['cat']?->name ?? '(no sub-category)',
                'is_active' => $s['cat']?->is_active ?? $g['cat']->is_active,
                // Categories review finding 8: the ledger code (and branch of a branch-only sub) beside the name.
                'code' => $s['cat']?->ledger_code ?? $g['cat']->ledger_code,
                'branch_only' => $tagBranches && $s['cat']?->branch_id ? ($tagBranches[$s['cat']->branch_id] ?? null) : null,
                'cents' => $s['cents'],
            ], $g['subs']);
            usort($subs, $sort);

            return [
                'id' => $g['cat']->id,
                'name' => $g['cat']->name,
                'is_active' => $g['cat']->is_active,
                'code' => $g['cat']->ledger_code,
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

        // Double-entry R8: money accounts only (cash, bank, wallet), so chart lines never change these figures.
        $before = fn (string $op, string $date) => $this->moneyLines()->whereIn('le.branch_id', $ids)->whereDate('le.entry_date', $op, $date)
            ->groupBy('le.branch_id')->selectRaw("le.branch_id, SUM($dr - $cr) as cents")->pluck('cents', 'branch_id');
        $opening = $before('<', $p->from);
        $ledgerClosing = $before('<=', $p->to);

        // Effective side: a reversal entry is booked on the original entry's side, negated.
        $moves = $this->moneyLines()
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
        // The report also runs on a database migrated only up to the double-entry schema (double-entry AC 27).
        self::$receiptColumns ??= Schema::hasColumn('transactions', 'receipt_status');
        $receiptColumns = self::$receiptColumns ? 't.receipt_status, t.received_on' : 'NULL as receipt_status, NULL as received_on';

        $list = (clone $base)
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->leftJoin('branches as fb', 'fb.id', '=', 'fa.branch_id')
            ->leftJoin('branches as tb', 'tb.id', '=', 'ta.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->orderByDesc('t.txn_date')->orderByDesc('t.id')
            ->selectRaw("t.id, t.ref_no, t.type, t.txn_date, t.status, t.description, $x as cents, o.ref_no as orig_ref, o.type as orig_type,
                $receiptColumns,
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
            // AG-052 S5: null = "Not required" (reversal rows never have one, Q3).
            'receipt_status' => $r->receipt_status,
            'received_on' => $r->received_on ? substr($r->received_on, 0, 10) : null,
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

        $receipt = $p->extra['receipt'] ?? [];
        $inTransit = $receipt === [TransferReceipts::IN_TRANSIT]
            ? ['as_at' => $p->to, 'count' => $movements['total'], 'amount' => Money::fromCents((int) $cells->sum(fn ($c) => (int) $c->cents))]
            : null;

        return $this->meta($p) + [
            'receipt' => $receipt,
            'in_transit' => $inTransit,
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

    /**
     * AG-052 S5: a Receipt filter narrows the movements, the matrix and Net moved alike (as Types does). Reversal rows
     * have no receipt, so they drop out. "In transit at end date" (R14) lists the originals in transit at To, whatever
     * their date before To; From does not limit it.
     */
    private function movementQuery(ReportParams $p): Builder
    {
        $types = $p->extra['types'] ?? self::MOVEMENT_TYPES;
        $receipt = $p->extra['receipt'] ?? [];
        $ids = $p->branchIds();

        $q = $this->countedTransactions(self::MOVEMENT_TYPES)
            ->where(fn ($w) => $w->whereIn('t.type', $types)->orWhereIn('o.type', $types))
            ->where(fn ($w) => $w->whereIn('t.branch_id', $ids)->orWhereIn('t.related_branch_id', $ids));
        if ($receipt === [TransferReceipts::IN_TRANSIT]) {
            return app(TransferReceipts::class)->whereInTransit($q, $p->to);
        }

        return $q->whereDate('t.txn_date', '>=', $p->from)->whereDate('t.txn_date', '<=', $p->to)
            ->when($receipt, fn ($w) => $w->whereIn('t.receipt_status', $receipt));
    }

    // ------------------------------------------------------------------ AG-085 Daily Cash Book (R7)

    public function cashBook(ReportParams $p): array
    {
        $accounts = Account::withoutGlobalScopes()->money()->with('branch:id,code,name,is_main')
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
        $ids = DB::table('accounts')->whereIn('type', Account::MONEY_TYPES)->whereIn('branch_id', $p->branchIds())
            ->when($p->extra['accounts'] ?? [], fn ($q, $a) => $q->whereIn('id', $a))->pluck('id')->all();

        return $this->cashBookEntries($p, $ids)->count();
    }

    private function cashBookEntries(ReportParams $p, array $accountIds): Builder
    {
        return $this->moneyLines()
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

    // ------------------------------------------------------------------ AG-089 Trial balance (double-entry S2)

    /**
     * By branch: one section per branch with every account that has a closing balance or a period movement.
     * Consolidated: one row per consolidation line (money account, Owner's capital, category code, MAIN <-> branch pair)
     * with each branch's closing balance (signed, debit positive), the eliminations and consolidated Dr / Cr.
     * Opening = lines before From; period = lines From..To of transactions that are not void (gross); closing = lines up
     * to To. Opening + period Dr - period Cr = closing holds because every line of a voided entry is dated its txn_date.
     */
    public function trialBalance(ReportParams $p): array
    {
        $ids = $p->branchIds();
        $dr = $this->cents('le.debit');
        $cr = $this->cents('le.credit');
        $fy = app(Settings::class)->fiscalYearStarting((int) $p->extra['fiscal_year']);
        $post = (bool) ($p->extra['closing_entries'] ?? false);
        // Settings spec R6: income and expense start at the fiscal year start S; their earlier lines go to the virtual
        // row. Pre-closing (default) leaves this year's closing lines out of every column; post-closing includes them.
        $sums = DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->whereIn('le.branch_id', $ids)->whereDate('le.entry_date', '<=', $p->to)
            ->where(fn ($w) => $w->whereNotIn('a.class', ['income', 'expense'])->orWhereDate('le.entry_date', '>=', $fy->start()))
            ->when(! $post, fn ($q) => $q->where(fn ($w) => $w->where('le.origin', '<>', 'close')->orWhereDate('le.entry_date', '<', $fy->start())))
            ->groupBy('le.account_id')
            ->selectRaw("le.account_id,
                SUM(CASE WHEN DATE(le.entry_date) < ? THEN $dr - $cr ELSE 0 END) as opening,
                SUM(CASE WHEN DATE(le.entry_date) >= ? AND t.status <> 'void' THEN $dr ELSE 0 END) as pdr,
                SUM(CASE WHEN DATE(le.entry_date) >= ? AND t.status <> 'void' THEN $cr ELSE 0 END) as pcr,
                SUM($dr - $cr) as closing", [$p->from, $p->from, $p->from])
            ->get()->keyBy('account_id');
        $accounts = Account::withoutGlobalScopes()->with('branch:id,code,name,is_main,status')->whereIn('branch_id', $ids)->get();
        $num = fn ($id) => ['opening' => (int) ($sums[$id]->opening ?? 0), 'pdr' => (int) ($sums[$id]->pdr ?? 0),
            'pcr' => (int) ($sums[$id]->pcr ?? 0), 'closing' => (int) ($sums[$id]->closing ?? 0)];
        $groupOrder = array_flip(array_keys(self::TB_GROUPS));
        $earlier = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->whereIn('le.branch_id', $ids)->whereIn('a.class', ['income', 'expense'])->whereDate('le.entry_date', '<', $fy->start())
            ->groupBy('le.branch_id')->selectRaw("le.branch_id, SUM($dr) - SUM($cr) as v")->pluck('v', 'branch_id')->map(fn ($v) => (int) $v)->all();

        $out = $this->meta($p) + ['view' => $p->extra['view'], 'basis' => $this->tbBasis($fy, $post)] + $this->fiscalMeta($fy, $post);
        if ($p->extra['view'] === 'consolidated') {
            // The consolidated `subset` (a pair not eliminated) replaces the meta one.
            return array_merge($out, $this->consolidatedTrialBalance($p, $accounts, $num, $groupOrder, $earlier));
        }

        $sections = [];
        $allBalanced = true;
        foreach ($p->branches as $b) {
            $rows = [];
            $tot = array_fill_keys(['opening_dr', 'opening_cr', 'period_dr', 'period_cr', 'closing_dr', 'closing_cr'], 0);
            $identity = true;
            $list = $accounts->where('branch_id', $b->id)->sortBy(fn (Account $a) => [$groupOrder[$a->group()] ?? 9, (string) $a->code])->values();
            foreach ($list as $a) {
                $n = $num($a->id);
                if ($n['closing'] === 0 && $n['pdr'] === 0 && $n['pcr'] === 0) {
                    continue;
                }
                $identity = $identity && $n['closing'] === $n['opening'] + $n['pdr'] - $n['pcr'];
                $c = ['opening_dr' => max($n['opening'], 0), 'opening_cr' => max(-$n['opening'], 0), 'period_dr' => $n['pdr'], 'period_cr' => $n['pcr'],
                    'closing_dr' => max($n['closing'], 0), 'closing_cr' => max(-$n['closing'], 0)];
                foreach ($c as $k => $v) {
                    $tot[$k] += $v;
                }
                $rows[] = ['account' => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'class' => $a->class, 'type' => $a->type,
                    'group' => $a->group(), 'status' => $a->status]] + array_map([Money::class, 'fromCents'], $c);
            }
            // R6 virtual row, after the equity accounts; shown only when not 0.00.
            if ($v = $earlier[$b->id] ?? 0) {
                $c = ['opening_dr' => max($v, 0), 'opening_cr' => max(-$v, 0), 'period_dr' => 0, 'period_cr' => 0, 'closing_dr' => max($v, 0), 'closing_cr' => max(-$v, 0)];
                foreach ($c as $k => $x) {
                    $tot[$k] += $x;
                }
                $row = ['account' => ['id' => null, 'code' => null, 'name' => self::EARLIER_RESULT, 'class' => 'equity', 'type' => 'earlier_result',
                    'group' => 'equity', 'status' => 'active']] + array_map([Money::class, 'fromCents'], $c);
                $pos = 0;
                foreach ($rows as $i => $r) {
                    if (($groupOrder[$r['account']['group']] ?? 9) <= $groupOrder['equity']) {
                        $pos = $i + 1;
                    }
                }
                array_splice($rows, $pos, 0, [$row]);
            }
            $balanced = $identity && $tot['opening_dr'] === $tot['opening_cr'] && $tot['period_dr'] === $tot['period_cr'] && $tot['closing_dr'] === $tot['closing_cr']
                && $tot['opening_dr'] - $tot['opening_cr'] + $tot['period_dr'] - $tot['period_cr'] === $tot['closing_dr'] - $tot['closing_cr'];
            $allBalanced = $allBalanced && $balanced;
            if (! $balanced) {
                Log::error('Trial balance out of balance', ['branch' => $b->code, 'from' => $p->from, 'to' => $p->to, 'totals' => $tot]);
            }
            $sections[] = ['branch' => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'status' => $b->status],
                'rows' => $rows, 'totals' => array_map([Money::class, 'fromCents'], $tot), 'balanced' => $balanced,
                'difference' => Money::fromCents(abs($tot['closing_dr'] - $tot['closing_cr']) ?: abs($tot['period_dr'] - $tot['period_cr']) ?: abs($tot['opening_dr'] - $tot['opening_cr']))];
        }

        return $out + ['sections' => $sections, 'balanced' => $allBalanced];
    }

    private function consolidatedTrialBalance(ReportParams $p, Collection $accounts, callable $num, array $groupOrder, array $earlier = []): array
    {
        $ids = $p->branchIds();
        $main = Branch::where('is_main', true)->first();
        $mainIn = $main && in_array($main->id, $ids, true);
        $codes = Branch::pluck('code', 'id'); // review N10: one query, not one per row
        $lines = [];
        foreach ($accounts as $a) {
            [$key, $label, $sort] = match (true) {
                $a->isMoney() => ["acc:{$a->id}", "{$a->branch->code} · {$a->name}", [$a->branch->is_main ? 0 : 1, $a->branch->code, (string) $a->code]],
                $a->type === 'owner_capital' => ['owner_capital', $a->name, [(string) $a->code]],
                $a->type === Account::RETAINED_EARNINGS => ['retained_earnings', $a->name, [(string) $a->code]],
                $a->type === 'category' => ["cat:{$a->code}", $a->name, [(string) $a->code]],
                $a->type === 'head_office' => ["pair:{$a->branch_id}", ($main->code ?? 'MAIN')." ↔ {$a->branch->code}", [$a->branch->code]],
                $a->type === 'branch_current' => ["pair:{$a->counterparty_branch_id}", "{$a->branch->code} ↔ ".($codes[$a->counterparty_branch_id] ?? ''), [$codes[$a->counterparty_branch_id] ?? '']],
                default => ["acc:{$a->id}", "{$a->branch->code} · {$a->name}", [(string) $a->code]],
            };
            $n = $num($a->id);
            $lines[$key] ??= ['key' => $key, 'label' => $label, 'group' => $a->group(), 'sort' => [$groupOrder[$a->group()] ?? 9, ...$sort],
                'by_branch' => array_fill_keys($ids, 0), 'opening' => 0, 'pdr' => 0, 'pcr' => 0, 'closing' => 0, 'pair_branch' => null];
            $lines[$key]['by_branch'][$a->branch_id] += $n['closing'];
            foreach (['opening', 'pdr', 'pcr', 'closing'] as $k) {
                $lines[$key][$k] += $n[$k];
            }
            if (in_array($a->type, Account::INTERBRANCH_TYPES, true)) {
                $lines[$key]['pair_branch'] = $a->type === 'head_office' ? $a->branch_id : $a->counterparty_branch_id;
            }
        }
        // Settings spec R6: one consolidation line for the virtual row, summed over branches.
        if (array_filter($earlier)) {
            $line = ['key' => 'earlier_result', 'label' => self::EARLIER_RESULT, 'group' => 'equity', 'sort' => [$groupOrder['equity'], '99999'],
                'by_branch' => array_fill_keys($ids, 0), 'opening' => 0, 'pdr' => 0, 'pcr' => 0, 'closing' => 0, 'pair_branch' => null];
            foreach ($earlier as $bid => $v) {
                $line['by_branch'][$bid] = $v;
                $line['opening'] += $v;
                $line['closing'] += $v;
            }
            $lines['earlier_result'] = $line;
        }
        // Accountant sign-off B: group order first, then the keys within the group, element by element. Comparing the
        // arrays whole would order shorter arrays first (PHP compares array length before values), so money rows
        // (three keys) would follow the one-key income and expense rows.
        uasort($lines, function ($x, $y) {
            $c = $x['sort'][0] <=> $y['sort'][0];
            for ($i = 1, $n = max(count($x['sort']), count($y['sort'])); $c === 0 && $i < $n; $i++) {
                $c = ($x['sort'][$i] ?? '') <=> ($y['sort'][$i] ?? '');
            }

            return $c;
        });

        $rows = [];
        $tot = ['by_branch' => array_fill_keys($ids, 0), 'elimination' => 0, 'dr' => 0, 'cr' => 0];
        $identity = true;
        $subset = false;
        foreach ($lines as $l) {
            // A pair row nets to 0.00 across branches but still shows its branch balances.
            if (! array_filter($l['by_branch']) && $l['pdr'] === 0 && $l['pcr'] === 0) {
                continue;
            }
            $identity = $identity && $l['closing'] === $l['opening'] + $l['pdr'] - $l['pcr'];
            $elim = null;
            if ($l['pair_branch'] !== null) {
                if ($mainIn && in_array((int) $l['pair_branch'], $ids, true)) {
                    $elim = -$l['closing'];
                } else {
                    $subset = true;
                }
            }
            $net = $l['closing'] + ($elim ?? 0);
            foreach ($l['by_branch'] as $id => $v) {
                $tot['by_branch'][$id] += $v;
            }
            $tot['elimination'] += $elim ?? 0;
            $tot['dr'] += max($net, 0);
            $tot['cr'] += max(-$net, 0);
            $rows[] = ['key' => $l['key'], 'label' => $l['label'], 'group' => $l['group'],
                'by_branch' => array_map([Money::class, 'fromCents'], $l['by_branch']),
                'elimination' => $elim === null ? null : Money::fromCents($elim),
                'dr' => Money::fromCents(max($net, 0)), 'cr' => Money::fromCents(max(-$net, 0)),
                'opening' => Money::fromCents($l['opening']), 'period_dr' => Money::fromCents($l['pdr']), 'period_cr' => Money::fromCents($l['pcr']),
                'closing' => Money::fromCents($l['closing'])];
        }
        $balanced = $identity && ! array_filter($tot['by_branch']) && $tot['elimination'] === 0 && $tot['dr'] === $tot['cr'];
        if (! $balanced) {
            Log::error('Consolidated trial balance out of balance', ['from' => $p->from, 'to' => $p->to, 'totals' => $tot]);
        }
        $diff = abs($tot['dr'] - $tot['cr']) ?: (int) collect($tot['by_branch'])->map(fn ($v) => abs($v))->max();

        return [
            'branches' => $p->branches->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'status' => $b->status])->values()->all(),
            'rows' => $rows,
            'totals' => ['by_branch' => array_map([Money::class, 'fromCents'], $tot['by_branch']), 'elimination' => Money::fromCents($tot['elimination']),
                'dr' => Money::fromCents($tot['dr']), 'cr' => Money::fromCents($tot['cr'])],
            'balanced' => $balanced,
            'difference' => Money::fromCents($diff),
            'subset' => $subset,
        ];
    }

    /** S6 basis line of the trial balance (settings spec, review finding 14). */
    public function tbBasis(FiscalYear $fy, bool $post): string
    {
        return self::TB_BASIS.". Income and expense from the start of {$fy->label()} (".app(Settings::class)->formatDate($fy->start()).'). '
            ."Results of earlier years are in Retained earnings (MAIN) and Head office current accounts once closed, otherwise in '"
            .self::EARLIER_RESULT."'. Retained earnings excludes results before go-live. Closing entries ".($post ? 'included.' : 'excluded.');
    }

    /** S6 / API: the fiscal year of the report, its close state, and whether an earlier year is not closed. */
    private function fiscalMeta(FiscalYear $fy, bool $post): array
    {
        $closes = YearEndClose::with(['closedBy:id,name', 'reopenedBy:id,name'])->where('fiscal_year', $fy->startYear)->orderBy('id')->get();
        $active = $closes->firstWhere('status', YearEndClose::CLOSED);
        $last = $closes->last();
        $earlier = app(YearEnd::class)->earliestUnclosedBefore($fy);

        return [
            'fiscal_year' => $fy->toArray() + [
                'closed' => $active !== null,
                'closed_at' => $active?->closed_at?->toIso8601String(),
                'closed_by' => $active?->closedBy?->name,
                // Review finding 5: reopened and not closed again (red banner).
                'reopened' => ! $active && $last ? ['at' => $last->reopened_at?->toIso8601String(), 'by' => $last->reopenedBy?->name, 'reason' => $last->reopen_reason] : null,
                'reopen_count' => $closes->where('status', YearEndClose::REOPENED)->count(),
            ],
            'earlier_unclosed' => $earlier !== null,
            'earlier_unclosed_label' => $earlier?->label(),
            'closing_entries' => $post,
        ];
    }

    /** Detail rows of a trial-balance export (account rows, all sections). */
    public function trialBalanceRows(array $r): int
    {
        return isset($r['sections']) ? (int) collect($r['sections'])->sum(fn ($s) => count($s['rows'])) : count($r['rows']);
    }

    // ------------------------------------------------------------------ shared

    /** Ledger lines on cash / bank / wallet accounts only (double-entry R8), as `le` joined to its account `la`. */
    public function moneyLines(): Builder
    {
        return DB::table('ledger_entries as le')->join('accounts as la', 'la.id', '=', 'le.account_id')->whereIn('la.type', Account::MONEY_TYPES);
    }

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
        return Money::sqlCents($column);
    }
}
