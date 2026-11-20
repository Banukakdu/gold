<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\DoubleEntryPreflight;
use App\Support\LedgerChecks;
use App\Support\LegacyVoids;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Controls spec R6 + accounting review finding 6. Run before and after deploy.
 *  - lists legacy voids (status void, no ledger entries) with ref, date, amount, account, voided-by and voided-at,
 *    and flags any without a `transaction.voided` audit row (documentary check only, no correction);
 *  - checks accounts.current_balance == ledger balance for every account (money and chart accounts);
 *  - double-entry V1-V6 (docs/specs/double-entry.md R7).
 * Exits 1 on any failure.
 */
class LedgerVerify extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'List legacy voids, check cached balances, the double-entry rules (V1-V6) and the year-end close (V7-V8)';

    public function handle(): int
    {
        $legacy = LegacyVoids::all();
        $audits = AuditLog::with('user:id,name')->where('event', 'transaction.voided')->where('auditable_type', Transaction::class)
            ->whereIn('auditable_id', $legacy->pluck('id'))->get()->keyBy('auditable_id');

        $this->info("Legacy voids (voided before contra entries; ledger lines were not kept): {$legacy->count()}");
        if ($legacy->isNotEmpty()) {
            $this->table(['Ref', 'Date', 'Amount', 'Account', 'Voided by', 'Voided at', 'Check'], $legacy->map(function (Transaction $t) use ($audits) {
                $a = $audits[$t->id] ?? null;

                return [
                    $t->ref_no ?? "#{$t->id}",
                    $t->txn_date->format('Y-m-d'),
                    Money::format($t->amount),
                    trim(($t->branch->code ?? '').' '.($t->account->name ?? '')),
                    $a?->user?->name ?? '-',
                    $a?->created_at?->setTimezone('Asia/Colombo')->format('Y-m-d H:i') ?? '-',
                    $a ? 'ok' : 'NO transaction.voided AUDIT ROW',
                ];
            })->all());
            $missing = $legacy->reject(fn ($t) => isset($audits[$t->id]))->count();
            if ($missing) {
                $this->warn("{$missing} legacy void(s) have no transaction.voided audit row. Review them before go-live.");
            }
        }

        $bad = 0;
        $ledgerBalances = LedgerChecks::accountBalances();
        foreach (Account::withoutGlobalScopes()->with('branch:id,code')->orderBy('id')->get() as $acc) {
            $ledger = Money::fromCents($ledgerBalances[$acc->id] ?? 0);
            if (Money::cmp($acc->current_balance, $ledger) !== 0) {
                $bad++;
                $this->error("Account #{$acc->id} {$acc->branch->code} {$acc->name}: current_balance ".Money::add($acc->current_balance).' != ledger '.$ledger);
            }
        }
        if ($bad) {
            $this->error("{$bad} account balance(s) differ from the ledger.");
        } else {
            $this->info('All account balances match the ledger.');
        }

        // Double-entry checks (docs/specs/double-entry.md R7). Each prints its count and lists offenders.
        $failed = $bad > 0;
        if (! Schema::hasColumn('ledger_entries', 'origin')) {
            $this->warn('Double-entry checks V1-V6 skipped: the double-entry migration has not run yet.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $offenders = fn ($rows) => $rows->map(fn ($r) => [$r->ref_no ?? "#{$r->transaction_id}", substr((string) $r->txn_date, 0, 10), $r->branch,
            isset($r->diff) ? Money::format(Money::fromCents((int) $r->diff)) : '-'])->all();

        $none = LedgerChecks::withoutLines();
        $unbalanced = LedgerChecks::unbalanced();
        $failed = $this->check('V1 transactions without lines or not balanced (in total and per branch)', $none->count() + $unbalanced->pluck('transaction_id')->unique()->count(),
            ['Ref', 'Date', 'Branch', 'Difference (Dr - Cr)'], array_merge($offenders($none), $offenders($unbalanced))) || $failed;

        $lines = DoubleEntryPreflight::lineOffenders();
        $v2 = collect($lines)->flatMap(fn ($rows, $rule) => $rows->map(fn ($r) => [substr($rule, 0, 2), "line #{$r->id}", $r->ref_no ?? "#{$r->transaction_id}", substr((string) $r->entry_date, 0, 10)]));
        $failed = $this->check('V2 lines one-sided (D1), line branch = account branch (D2), contras mirror (D3), income/expense lines dated txn_date (D4)',
            $v2->count(), ['Rule', 'Line', 'Ref', 'Date'], $v2->all()) || $failed;

        $net = LedgerChecks::netByBranch();
        $refs = fn ($rows) => $rows->map(fn ($r) => ($r->ref_no ?? "#{$r->transaction_id}").' ('.Money::format(Money::fromCents((int) $r->diff)).')')->unique()->take(20)->implode(', ');
        $v3 = $net->filter()->map(fn ($d, $b) => [$b, Money::format(Money::fromCents($d)), $refs($unbalanced->where('branch', $b))])->values();
        if ((int) $net->sum() !== 0) {
            $v3->push(['All branches', Money::format(Money::fromCents((int) $net->sum())), $refs($unbalanced)]);
        }
        $failed = $this->check('V3 trial balance nets to zero (per branch and all branches)', $v3->count(), ['Branch', 'Difference (Dr - Cr)', 'Entries not balanced'], $v3->all()) || $failed;

        $ib = LedgerChecks::interbranch();
        $v4 = collect($ib['pairs'])->filter(fn ($p) => $p['diff'] !== 0)
            ->map(fn ($p) => ["MAIN <-> {$p['branch']}", Money::format(Money::fromCents($p['head_office'])), Money::format(Money::fromCents($p['branch_current'])), Money::format(Money::fromCents($p['diff']))])
            ->merge($ib['stray']->map(fn ($a) => ["unexpected account #{$a->id} {$a->code} {$a->name}", '-', '-', '-']))->values();
        $failed = $this->check('V4 inter-branch pairs eliminate (HO in B + BCA(B) = 0)', $v4->count(), ['Pair', 'HO in branch', 'BCA in MAIN', 'Difference'], $v4->all()) || $failed;

        $v5 = LedgerChecks::categoryMismatches()->map(fn ($m) => [$m['branch'], "#{$m['category_id']} ".(Category::find($m['category_id'])?->name ?? ''),
            Money::format(Money::fromCents($m['ledger'])), Money::format(Money::fromCents($m['report'])), Money::format(Money::fromCents($m['diff']))]);
        $failed = $this->check('V5 category accounts agree with the I&E basis (all time)', $v5->count(), ['Branch', 'Category', 'Ledger', 'I&E', 'Difference'], $v5->all()) || $failed;

        $v6 = LedgerChecks::notBackfilled();
        $failed = $this->check('V6 back-fill complete (no income, expense, capital or cross-branch entry with only money lines)', $v6->count(),
            ['Ref', 'Date', 'Branch', 'Type'], $v6->map(fn ($r) => [$r->ref_no ?? "#{$r->transaction_id}", substr((string) $r->txn_date, 0, 10), $r->branch, $r->type])->all()) || $failed;

        // AG-051 V9 (accounting review MUST 2): requests that never posted have no lines; status agrees.
        if (Schema::hasColumn('transactions', 'approval_status')) {
            $v9 = LedgerChecks::approvalRequests();
            $failed = $this->check('V9 transfer requests: no lines unless approved, status agrees with approval status', $v9->count(),
                ['Ref', 'Date', 'Branch', 'Problem'], $v9->map(fn ($r) => [$r->ref_no ?? "#{$r->transaction_id}", substr((string) $r->txn_date, 0, 10), $r->branch, $r->problem])->all()) || $failed;
        }

        // AG-092 year-end close (docs/specs/settings.md R9).
        if (Schema::hasTable('year_end_closes')) {
            $v7 = LedgerChecks::yearEnd()->map(fn ($r) => [$r['check'], $r['branch'], $r['detail'], Money::format(Money::fromCents($r['diff']))]);
            $failed = $this->check('V7 year-end close: income and expense cleared, results moved as in the summary, Retained earnings = closed results',
                $v7->count(), ['Check', 'Branch', 'Account / detail', 'Difference (Dr - Cr)'], $v7->all()) || $failed;
            $v8 = LedgerChecks::closeLines()->map(fn ($r) => [$r['rule'], "line #{$r['id']}", $r['ref'], $r['date']]);
            $failed = $this->check('V8 closing lines only in closes, nothing added to a closed year, Retained earnings written only by the close',
                $v8->count(), ['Rule', 'Line', 'Ref', 'Date'], $v8->all()) || $failed;
        }

        if ($failed) {
            $this->error('ledger:verify FAILED.');

            return self::FAILURE;
        }
        $this->info('ledger:verify passed.');

        return self::SUCCESS;
    }

    /** Prints "<label>: <count>" and the offenders. Returns true when the check failed. */
    private function check(string $label, int $count, array $headers, array $rows): bool
    {
        if ($count === 0) {
            $this->info("{$label}: 0");

            return false;
        }
        $this->error("{$label}: {$count}");
        $this->table($headers, array_slice($rows, 0, 200));

        return true;
    }
}
