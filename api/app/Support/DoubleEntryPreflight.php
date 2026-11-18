<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\ChartOfAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Double-entry migration preflight (docs/specs/double-entry.md section 5 and R6). Read-only.
 * Runs before any DDL, so a refusal leaves the database exactly as it was. Works on the pre-feature schema.
 */
final class DoubleEntryPreflight
{
    /** Offenders listed per check. */
    public const LIST_LIMIT = 20;

    /** @throws RuntimeException listing every failing check and its offenders */
    public static function assertReady(): void
    {
        $problems = self::problems();
        if ($problems) {
            throw new RuntimeException("Migration stopped before any change (double-entry preflight):\n - ".implode("\n - ", $problems)
                ."\nFix these (see php artisan ledger:verify), then run the migration again.");
        }
    }

    /** @return string[] one line per failing check, naming its offenders */
    public static function problems(): array
    {
        $out = [];
        $list = fn ($rows, callable $fmt) => $rows->take(self::LIST_LIMIT)->map($fmt)->implode('; ').($rows->count() > self::LIST_LIMIT ? '; ...' : '');

        // Cached balances (the existing ledger:verify check).
        $balances = DB::table('accounts as a')->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoin('ledger_entries as le', 'le.account_id', '=', 'a.id')
            ->groupBy('a.id', 'a.name', 'b.code', 'a.current_balance')
            ->selectRaw('a.id, a.name, b.code, a.current_balance, COALESCE(SUM('.Money::sqlCents('le.debit').' - '.Money::sqlCents('le.credit').'), 0) as ledger')
            ->orderBy('a.id')->get()
            ->filter(fn ($r) => Money::toCents($r->current_balance) !== (int) $r->ledger)->values();
        if ($balances->isNotEmpty()) {
            $out[] = "{$balances->count()} account balance(s) differ from the ledger: "
                .$list($balances, fn ($r) => "#{$r->id} {$r->code} {$r->name} (current_balance ".Money::add($r->current_balance).', ledger '.Money::add($r->ledger).')');
        }

        foreach (self::lineOffenders() as $rule => $rows) {
            if ($rows->isNotEmpty()) {
                $out[] = "{$rows->count()} ledger line(s) break {$rule}: ".$list($rows, fn ($r) => "line #{$r->id} of ".($r->ref_no ?? "#{$r->transaction_id}"));
            }
        }

        $noCategory = self::entries()->whereNull('t.category_id')->get(['t.id', 't.ref_no']);
        if ($noCategory->isNotEmpty()) {
            $out[] = "{$noCategory->count()} income/expense entr(ies) have no category: ".$list($noCategory, fn ($r) => $r->ref_no ?? "#{$r->id}");
        }
        $otherBranch = self::entries()->join('categories as c', 'c.id', '=', 't.category_id')
            ->whereNotNull('c.branch_id')->whereColumn('c.branch_id', '!=', 't.branch_id')->get(['t.id', 't.ref_no', 'c.name']);
        if ($otherBranch->isNotEmpty()) {
            $out[] = "{$otherBranch->count()} income/expense entr(ies) use another branch's category: "
                .$list($otherBranch, fn ($r) => ($r->ref_no ?? "#{$r->id}")." ({$r->name})");
        }

        return array_merge($out, self::chartLimits());
    }

    /**
     * Chart code limits (R1), checked here so the chart step after the DDL cannot fail on them (review R1): inter-branch
     * codes are 15 + a 3-digit branch id, money codes 11001-11999 per branch, category codes 2 digits per level.
     *
     * @return string[]
     */
    public static function chartLimits(): array
    {
        $out = [];
        $max = ChartOfAccounts::MAX_BRANCH_ID;
        $big = DB::table('branches')->where('id', '>', $max)->orderBy('id')->get(['id', 'code']);
        if ($big->isNotEmpty()) {
            $out[] = "{$big->count()} branch(es) have an id above {$max}, the limit of the inter-branch account codes: "
                .$big->map(fn ($b) => "{$b->code} (id {$b->id})")->implode(', ');
        }
        $money = DB::table('accounts as a')->join('branches as b', 'b.id', '=', 'a.branch_id')->whereIn('a.type', Account::MONEY_TYPES)
            ->groupBy('b.code')->havingRaw('COUNT(*) > 999')->selectRaw('b.code, COUNT(*) as n')->get();
        foreach ($money as $m) {
            $out[] = "Branch {$m->code} has {$m->n} cash, bank and wallet accounts; codes 11001-11999 allow 999.";
        }
        $parents = DB::table('categories')->whereNull('parent_id')->groupBy('type')->havingRaw('COUNT(*) > 99')->selectRaw('type, COUNT(*) as n')->get();
        foreach ($parents as $p) {
            $out[] = "{$p->n} top-level {$p->type} categories; category codes allow 99 per type.";
        }
        $subs = DB::table('categories as c')->join('categories as p', 'p.id', '=', 'c.parent_id')
            ->groupBy('p.id', 'p.name')->havingRaw('COUNT(*) > 99')->selectRaw('p.name, COUNT(*) as n')->get();
        foreach ($subs as $p) {
            $out[] = "Category {$p->name} has {$p->n} sub-categories; category codes allow 99 under one parent.";
        }

        return $out;
    }

    /**
     * D1-D4 offenders (also used by ledger:verify V2), each a collection of {id, transaction_id, ref_no, entry_date, branch_id}.
     *
     * @return array<string, Collection>
     */
    public static function lineOffenders(): array
    {
        $base = fn () => DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->select('le.id', 'le.transaction_id', 't.ref_no', 'le.entry_date', 'le.branch_id', 'le.debit', 'le.credit')->orderBy('le.id');

        return [
            'D1 (exactly one side > 0, none negative)' => $base()->where(fn ($w) => $w->where('le.debit', '<', 0)->orWhere('le.credit', '<', 0)
                ->orWhere(fn ($b) => $b->where('le.debit', '>', 0)->where('le.credit', '>', 0))
                ->orWhere(fn ($b) => $b->where('le.debit', '=', 0)->where('le.credit', '=', 0)))->get(),
            'D2 (line branch = account branch)' => $base()->join('accounts as a', 'a.id', '=', 'le.account_id')
                ->whereColumn('a.branch_id', '!=', 'le.branch_id')->get(),
            'D3 (a contra mirrors its original)' => $base()->join('ledger_entries as o', 'o.id', '=', 'le.contra_of_id')
                ->where(fn ($w) => $w->whereColumn('o.account_id', '!=', 'le.account_id')->orWhereColumn('o.transaction_id', '!=', 'le.transaction_id')
                    ->orWhereColumn('o.debit', '!=', 'le.credit')->orWhereColumn('o.credit', '!=', 'le.debit'))->get(),
            'D4 (income/expense lines dated txn_date)' => $base()->whereIn('t.type', [Transaction::INCOME, Transaction::EXPENSE])
                ->whereRaw('DATE(le.entry_date) <> DATE(t.txn_date)')->get(),
        ];
    }

    private static function entries()
    {
        return DB::table('transactions as t')->whereIn('t.type', [Transaction::INCOME, Transaction::EXPENSE])->orderBy('t.id');
    }
}
