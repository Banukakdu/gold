<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\YearEndClose;
use App\Support\BusinessDate;
use App\Support\FiscalYear;
use App\Support\LedgerChecks;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-092 year-end close, read-only part (docs/specs/settings.md R7, R8, S5): fiscal-year list, the R8 checks, the
 * per-branch balances and the planned closing lines. Posting is LedgerService::closeYear / reopenYear only.
 */
class YearEnd
{
    public function __construct(private ReportService $reports, private PostingRules $rules) {}

    public function settings(): Settings
    {
        return app(Settings::class);
    }

    public function year(int $startYear): FiscalYear
    {
        return $this->settings()->fiscalYearStarting($startYear);
    }

    public function activeClose(int $startYear, bool $lock = false): ?YearEndClose
    {
        return YearEndClose::active()->where('fiscal_year', $startYear)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /** Last day of the latest active close (R8 "While a year is closed"), or null. */
    public static function closedThrough(bool $share = false): ?string
    {
        try {
            $v = YearEndClose::active()->when($share, fn ($q) => $q->sharedLock())->max('period_end');
        } catch (QueryException $e) {
            // The double-entry back-fill migration posts before the year-end table exists (nothing can be closed then).
            if (! Schema::hasTable('year_end_closes')) {
                return null;
            }
            throw $e;
        }

        return $v ? substr((string) $v, 0, 10) : null;
    }

    /** The fiscal year of $date when the date is inside a closed year (<= E of the latest active close), else null. */
    public function closedYearOf(string $date, bool $share = false): ?FiscalYear
    {
        $through = self::closedThrough($share);

        return $through !== null && $date <= $through ? $this->settings()->fiscalYear($date) : null;
    }

    /** First ledger line date, or null when the ledger is empty. */
    public function firstLineDate(): ?string
    {
        $v = DB::table('ledger_entries')->min('entry_date');

        return $v ? substr((string) $v, 0, 10) : null;
    }

    /** Lines on income / expense accounts, joined to their account as `a`. */
    private function ieLines()
    {
        return DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')->whereIn('a.class', ['income', 'expense']);
    }

    public function hasIncomeExpense(FiscalYear $fy): bool
    {
        return $this->ieLines()->whereDate('le.entry_date', '>=', $fy->start())->whereDate('le.entry_date', '<=', $fy->end())->exists();
    }

    /**
     * Review finding 11: the earliest year before $fy that has income or expense lines and is not closed, or null.
     */
    public function earliestUnclosedBefore(FiscalYear $fy): ?FiscalYear
    {
        $first = $this->ieLines()->whereDate('le.entry_date', '<', $fy->start())->min('le.entry_date');
        if (! $first) {
            return null;
        }
        $closed = YearEndClose::active()->pluck('fiscal_year')->map(fn ($v) => (int) $v)->all();
        for ($y = $this->settings()->fiscalYear(substr((string) $first, 0, 10)); $y->startYear < $fy->startYear; $y = $y->next()) {
            if (! in_array($y->startYear, $closed, true) && $this->hasIncomeExpense($y)) {
                return $y;
            }
        }

        return null;
    }

    /**
     * R7 balances: b = debit - credit per income / expense account over all lines dated S..E (every origin and status),
     * by branch id, accounts in code order. Lines of $excludeTxnIds (an active close, for its preview) are left out.
     *
     * @return array<int, array<int, int>> [branch_id => [account_id => cents]]
     */
    public function balances(FiscalYear $fy, array $excludeTxnIds = []): array
    {
        $dr = Money::sqlCents('le.debit');
        $cr = Money::sqlCents('le.credit');
        $rows = $this->ieLines()->whereDate('le.entry_date', '>=', $fy->start())->whereDate('le.entry_date', '<=', $fy->end())
            ->when($excludeTxnIds, fn ($q, $ids) => $q->whereNotIn('le.transaction_id', $ids))
            ->groupBy('le.branch_id', 'le.account_id', 'a.code')
            ->orderBy('le.branch_id')->orderBy('a.code')->orderBy('le.account_id')
            ->selectRaw("le.branch_id, le.account_id, SUM($dr) - SUM($cr) as b")->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->branch_id][(int) $r->account_id] = (int) $r->b;
        }

        return $out;
    }

    /** Review finding 2: the I&E report net (income - expense) for S..E per branch id, on the report's basis. */
    public function ieNet(FiscalYear $fy): array
    {
        $x = $this->reports->cents('t.amount');

        return $this->reports->countedTransactions([Transaction::INCOME, Transaction::EXPENSE])
            ->whereDate('t.txn_date', '>=', $fy->start())->whereDate('t.txn_date', '<=', $fy->end())
            ->groupBy('t.branch_id')
            ->selectRaw("t.branch_id, SUM(CASE WHEN COALESCE(o.type, t.type) = 'income' THEN 1 ELSE -1 END * CASE WHEN t.type = 'reversal' THEN -$x ELSE $x END) as cents")
            ->pluck('cents', 'branch_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * The close of every branch (R7) with the I&E reconciliation (finding 2).
     *
     * @return array{branches: array, total_net: int, total_ie_net: int}
     */
    public function plan(FiscalYear $fy, array $excludeTxnIds = []): array
    {
        $balances = $this->balances($fy, $excludeTxnIds);
        $ie = $this->ieNet($fy);
        $main = Branch::main();
        $classes = Account::withoutGlobalScopes()->whereIn('id', collect($balances)->flatMap(fn ($b) => array_keys($b))->all())->pluck('class', 'id');
        $out = [];
        $total = $totalIe = 0;
        foreach (Branch::orderByDesc('is_main')->orderBy('code')->get() as $b) {
            $bal = $balances[$b->id] ?? [];
            $income = $expense = 0;
            foreach ($bal as $id => $v) {
                if ($classes[$id] === 'income') {
                    $income -= $v;
                } else {
                    $expense += $v;
                }
            }
            $net = $income - $expense;
            $ieNet = $ie[$b->id] ?? 0;
            if (! array_filter($bal) && $ieNet === 0) {
                continue;
            }
            $out[] = ['branch' => $b, 'income' => $income, 'expense' => $expense, 'net' => $net, 'ie_net' => $ieNet,
                'spec' => array_filter($bal) ? $this->rules->close($b->id, $main->id, $bal) : []];
            $total += $net;
            $totalIe += $ieNet;
        }

        return ['branches' => $out, 'total_net' => $total, 'total_ie_net' => $totalIe];
    }

    /**
     * R8 checks 1-5b (not the confirmation), each [code, message]. Empty = ready to close.
     *
     * @param  array|null  $plan  the plan() of this year when already computed
     */
    public function checks(FiscalYear $fy, ?array $plan = null): array
    {
        $out = [];
        $label = $fy->label();
        $month = PeriodLock::label($fy->endPeriod());
        if ($fy->end() >= BusinessDate::today()) {
            $out[] = ['code' => 'not_ended', 'message' => "{$label} has not ended yet."];
        }
        if ($this->activeClose($fy->startYear)) {
            $out[] = ['code' => 'closed', 'message' => "{$label} is already closed."];

            return $out;
        }
        if ($earlier = $this->earliestUnclosedBefore($fy)) {
            $out[] = ['code' => 'previous_open', 'message' => "Close {$earlier->label()} first."];
        }
        $through = PeriodLock::closedThrough();
        $open = Branch::orderByDesc('is_main')->orderBy('code')->get()
            ->filter(fn (Branch $b) => PeriodLock::throughFor($through, $b->id) < $fy->endPeriod())->pluck('code');
        if ($open->isNotEmpty()) {
            $out[] = ['code' => 'locks', 'message' => "Lock {$month} for: {$open->implode(', ')} (or lock all branches) before closing {$label}."];
        }
        $unbalanced = LedgerChecks::unbalanced(null, $fy->end())->pluck('transaction_id')->unique()->count();
        if ($unbalanced > 0) {
            $out[] = ['code' => 'unbalanced', 'message' => "{$unbalanced} entries up to {$month} are not balanced. Run ledger:verify."];
        }
        foreach (($plan ?? $this->plan($fy))['branches'] as $p) {
            if ($p['net'] !== $p['ie_net']) {
                $out[] = ['code' => 'ie_mismatch', 'message' => "The ledger result for {$p['branch']->code} differs from the Income & Expense report by "
                    .Money::format(Money::fromCents(abs($p['net'] - $p['ie_net']))).'. Run ledger:verify.'];
                break;
            }
        }

        return $out;
    }

    /** S5 list: one row per fiscal year from the year of the first ledger line to the current year, newest first. */
    public function years(): Collection
    {
        $first = $this->firstLineDate();
        if ($first === null) {
            return collect();
        }
        $current = $this->settings()->currentFiscalYear();
        $from = $this->settings()->fiscalYear($first);
        $closes = YearEndClose::with(['closedBy:id,name', 'reopenedBy:id,name'])->orderBy('id')->get()->groupBy('fiscal_year');
        $rows = collect();
        for ($y = $from; $y->startYear <= max($current->startYear, $from->startYear); $y = $y->next()) {
            $rows->push($this->row($y, $closes[$y->startYear] ?? collect()));
        }

        return $rows->reverse()->values();
    }

    public function inList(int $startYear): bool
    {
        $first = $this->firstLineDate();

        return $first !== null && $startYear >= $this->settings()->fiscalYear($first)->startYear
            && $startYear <= max($this->settings()->currentFiscalYear()->startYear, $this->settings()->fiscalYear($first)->startYear);
    }

    private function row(FiscalYear $fy, Collection $closes): array
    {
        $active = $closes->firstWhere('status', YearEndClose::CLOSED);
        $plan = $active ? null : $this->plan($fy);
        $checks = $active ? [] : $this->checks($fy, $plan);
        $status = match (true) {
            $active !== null => 'closed',
            $closes->isNotEmpty() => 'reopened',
            ! $this->hasIncomeExpense($fy) => 'no_result',
            ! $checks => 'ready',
            default => 'open',
        };

        return $fy->toArray() + [
            'status' => $status,
            'can_close' => ! $active && ! $checks,
            'result' => $active ? $active->totalNet() : Money::fromCents($plan['total_net']),
            'checks' => $checks,
            'close' => $closes->isEmpty() ? null : self::present($closes->last()),
            'history' => $closes->map(fn ($c) => self::present($c))->values()->all(),
        ];
    }

    public static function present(YearEndClose $c): array
    {
        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;

        return [
            'id' => $c->id,
            'status' => $c->status,
            'closed_by' => $person($c->closedBy),
            'closed_at' => $c->closed_at?->toIso8601String(),
            'total_net' => $c->totalNet(),
            'reopened_by' => $person($c->reopenedBy),
            'reopened_at' => $c->reopened_at?->toIso8601String(),
            'reopen_reason' => $c->reopen_reason,
            'transaction_ids' => $c->transactionIds(),
        ];
    }

    /** GET /fiscal-years/{y}/preview (S5). */
    public function preview(FiscalYear $fy): array
    {
        $active = $this->activeClose($fy->startYear);
        $plan = $this->plan($fy, $active ? $active->transactionIds() : []);
        $codes = Branch::pluck('code', 'id');
        $branches = array_map(function ($p) use ($codes) {
            return [
                'branch' => ['id' => $p['branch']->id, 'code' => $p['branch']->code, 'name' => $p['branch']->name, 'status' => $p['branch']->status],
                'income' => Money::fromCents($p['income']),
                'expense' => Money::fromCents($p['expense']),
                'net' => Money::fromCents($p['net']),
                'ie_net' => Money::fromCents($p['ie_net']),
                'difference' => Money::fromCents($p['net'] - $p['ie_net']),
                'lines' => array_map(function ($l) use ($codes) {
                    $acc = $this->describe($l['ref']);

                    return ['branch_code' => $codes[$l['branch_id']] ?? '', 'code' => $acc?->code, 'account' => $acc?->name ?? $this->refName($l['ref']),
                        'debit' => Money::fromCents($l['debit']), 'credit' => Money::fromCents($l['credit'])];
                }, $p['spec']),
            ];
        }, $plan['branches']);

        return [
            'fiscal_year' => $fy->toArray() + ['short_label' => $fy->shortLabel(), 'closed' => $active !== null],
            'branches' => $branches,
            'total_net' => Money::fromCents($plan['total_net']),
            'total_ie_net' => Money::fromCents($plan['total_ie_net']),
            'retained_earnings_movement' => Money::fromCents($plan['total_net']),
            'checks' => $active ? [] : $this->checks($fy, $plan),
        ];
    }

    /** The existing account of a rule reference, without creating anything (read-only preview). */
    private function describe(array $ref): ?Account
    {
        $q = Account::withoutGlobalScopes();
        $main = Branch::where('is_main', true)->value('id');

        return match ($ref[0]) {
            PostingRules::ACCOUNT => $q->find($ref[1]),
            PostingRules::RETAINED_EARNINGS => $q->where('branch_id', $main)->where('type', Account::RETAINED_EARNINGS)->first(),
            PostingRules::HEAD_OFFICE => $q->where('branch_id', $ref[1])->where('type', 'head_office')->first(),
            PostingRules::BRANCH_CURRENT => $q->where('branch_id', $main)->where('type', 'branch_current')->where('counterparty_branch_id', $ref[1])->first(),
            default => null,
        };
    }

    private function refName(array $ref): string
    {
        return match ($ref[0]) {
            PostingRules::RETAINED_EARNINGS => ChartOfAccounts::NAME_RETAINED_EARNINGS,
            PostingRules::HEAD_OFFICE => ChartOfAccounts::NAME_HEAD_OFFICE,
            PostingRules::BRANCH_CURRENT => 'Branch current account – '.Branch::find($ref[1])?->code,
            default => '',
        };
    }
}
