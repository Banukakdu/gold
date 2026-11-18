<?php

namespace App\Services;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Support\DoubleEntryPreflight;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Chart creation and back-fill of existing history (docs/specs/double-entry.md R1, R6). Used by the
 * 2026_11_22_000001_double_entry_backfill migration and by `php artisan ledger:backfill-double-entry`.
 *
 * Idempotent: a transaction that already balances per branch is skipped, so a re-run (or a run after a stop half-way)
 * ends with the same lines and balances as one clean run. Each run writes its own `ledger.double_entry_backfilled` row.
 */
class DoubleEntryBackfill
{
    public const SAMPLE = 20;

    public function __construct(private LedgerService $ledger, private BackfillPlanner $planner, private ChartOfAccounts $chart) {}

    /** What the migration does: preflight, chart, back-fill. Returns the run summary. */
    public function migrate(): array
    {
        DoubleEntryPreflight::assertReady();
        $this->setupChart();

        return $this->run(false);
    }

    /**
     * R6 "Chart": ledger codes for every category, 11xxx codes for money accounts (by id, per branch), the inter-branch pair
     * for every non-main branch, Owner's capital in MAIN and the category accounts. One `ledger.chart_created` row when
     * anything was created (user null, branch null).
     */
    public function setupChart(): array
    {
        $this->chart->takeCreated();
        DB::transaction(function () {
            $parents = Category::whereNull('parent_id')->whereNull('ledger_code')->orderBy('type')->orderBy('name')->orderBy('id')->get();
            foreach ($parents as $c) {
                $c->ledger_code = $this->chart->nextCategoryCode($c);
                $c->saveQuietly();
            }
            $subs = Category::query()->from('categories as c')->join('categories as p', 'p.id', '=', 'c.parent_id')
                ->whereNull('c.ledger_code')->orderBy('c.type')->orderBy('p.name')->orderBy('c.name')->orderBy('c.id')->select('c.*')->get();
            foreach ($subs as $c) {
                $c = Category::find($c->id);
                $c->ledger_code = $this->chart->nextCategoryCode($c);
                $c->saveQuietly();
            }

            foreach (Account::withoutGlobalScopes()->money()->whereNull('code')->orderBy('id')->get() as $a) {
                $a->code = $this->chart->nextMoneyCode($a->branch_id);
                $a->class = 'asset';
                $a->saveQuietly();
            }

            $main = Branch::where('is_main', true)->first();
            if ($main) {
                $this->chart->ownerCapital();
                foreach (Branch::where('is_main', false)->orderBy('id')->get() as $b) {
                    if ($b->id > ChartOfAccounts::MAX_BRANCH_ID) {
                        throw new RuntimeException("Branch {$b->code} has id {$b->id}; inter-branch account codes are limited to branch id 999.");
                    }
                    $this->chart->headOffice($b, $main);
                    $this->chart->branchCurrent($b, $main);
                }
            }
            foreach (Category::orderBy('id')->get() as $c) {
                foreach ($this->chart->categoryBranchIds($c) as $branchId) {
                    $this->chart->categoryAccount($c, $branchId);
                }
            }
        });
        $created = $this->chart->takeCreated();
        if ($created) {
            AuditLog::create(['user_id' => null, 'branch_id' => null, 'event' => 'ledger.chart_created',
                'new_values' => ['source' => 'migration', 'by_branch' => ChartOfAccounts::countByBranch($created)]]);
        }

        return ChartOfAccounts::countByBranch($created);
    }

    /**
     * Back-fill every transaction in id order, one DB transaction each. With $dryRun nothing is written (and this works
     * before `migrate`): the same counts and a sample of SAMPLE transactions are returned.
     *
     * @return array{transactions:int, lines:int, by_type:array, by_branch:array, locked_periods:array, money_balances_unchanged:bool, skipped_legacy_voids:int, sample:array}
     */
    public function run(bool $dryRun): array
    {
        $before = $this->moneyBalances();
        $codes = Branch::pluck('code', 'id');
        $sum = ['transactions' => 0, 'lines' => 0, 'by_type' => [], 'by_branch' => [], 'locked_periods' => [], 'skipped_legacy_voids' => 0];
        $sample = [];

        Transaction::withoutGlobalScopes()->orderBy('id')->chunkById(500, function ($txns) use ($dryRun, $codes, &$sum, &$sample) {
            foreach ($txns as $t) {
                $plan = $this->planner->plan($t);
                if ($plan['status'] === BackfillPlanner::LEGACY_VOID) {
                    $sum['skipped_legacy_voids']++;

                    continue;
                }
                if ($plan['status'] === BackfillPlanner::ERROR) {
                    throw new LedgerException($plan['error']);
                }
                if ($plan['status'] !== BackfillPlanner::PLAN) {
                    continue;
                }
                if (! $dryRun) {
                    $written = $this->ledger->backfill($t);
                    if (count($written) !== count($plan['lines'])) {
                        throw new LedgerException("Back-fill of {$t->ref_no} wrote ".count($written).' lines, '.count($plan['lines']).' were planned.');
                    }
                }
                $n = count($plan['lines']);
                $sum['transactions']++;
                $sum['lines'] += $n;
                $sum['by_type'][$t->type] = ($sum['by_type'][$t->type] ?? 0) + $n;
                foreach ($plan['lines'] as $l) {
                    $code = $codes[$l['branch_id']] ?? (string) $l['branch_id'];
                    $sum['by_branch'][$code] = ($sum['by_branch'][$code] ?? 0) + 1;
                    if (PeriodLock::isLocked((int) $l['branch_id'], $l['date'])) {
                        $key = $code.'|'.substr($l['date'], 0, 7);
                        $sum['locked_periods'][$key] ??= ['branch' => $code, 'period' => substr($l['date'], 0, 7), 'lines' => 0];
                        $sum['locked_periods'][$key]['lines']++;
                    }
                }
                if (count($sample) < self::SAMPLE) {
                    $sample[] = ['ref_no' => $t->ref_no ?? "#{$t->id}", 'type' => $t->type, 'status' => $t->status,
                        'lines' => array_map(fn ($l) => ['date' => $l['date'], 'account' => $this->planner->describe($l['ref'], (int) $l['branch_id']),
                            'debit' => Money::fromCents($l['debit']), 'credit' => Money::fromCents($l['credit']), 'contra' => isset($l['contra_of_new'])], $plan['lines'])];
                }
            }
        });

        // R6 safety: no cash / bank / wallet balance may change.
        $after = $this->moneyBalances();
        if ($before !== $after) {
            throw new RuntimeException('The back-fill changed a cash, bank or wallet balance. This must never happen; restore the backup and report it.');
        }
        ksort($sum['by_type']);
        ksort($sum['by_branch']);
        ksort($sum['locked_periods']);
        $sum['locked_periods'] = array_values($sum['locked_periods']);
        $sum['money_balances_unchanged'] = true;

        // A fresh install (no history at all) has nothing to record.
        if (! $dryRun && Transaction::withoutGlobalScopes()->exists()) {
            AuditLog::create(['user_id' => null, 'branch_id' => null, 'event' => 'ledger.double_entry_backfilled', 'new_values' => $sum]);
        }

        return $sum + ['sample' => $sample];
    }

    /** @return array<int, string> cached and ledger balance of every money account, by id */
    private function moneyBalances(): array
    {
        return DB::table('accounts as a')->whereIn('a.type', Account::MONEY_TYPES)
            ->leftJoin('ledger_entries as le', 'le.account_id', '=', 'a.id')
            ->groupBy('a.id', 'a.current_balance')->orderBy('a.id')
            ->selectRaw('a.id, a.current_balance, COALESCE(SUM('.Money::sqlCents('le.debit').' - '.Money::sqlCents('le.credit').'), 0) as ledger')
            ->get()->mapWithKeys(fn ($r) => [$r->id => Money::add($r->current_balance).'|'.(int) $r->ledger])->all();
    }
}
