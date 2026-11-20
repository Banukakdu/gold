<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans the double-entry back-fill of one existing transaction (docs/specs/double-entry.md R6). Read-only, and it works
 * on the pre-feature schema too, so `ledger:backfill-double-entry --dry-run` can run before `migrate` (F9 step 2).
 * A transaction needs the back-fill when all its lines are on money accounts and its posting rules have other lines;
 * one that already has a chart line is skipped when it balances per branch (idempotent), and stops the run otherwise.
 *
 * The missing lines are the posting rules (PostingRules) of the ORIGINAL transaction without its money lines, mirrored
 * for a reversal (the category is the original's category_id, the I&E basis), plus a contra for each of them when the
 * entry was voided with contra lines. They are dated as the lines they complete (txn_date).
 */
class BackfillPlanner
{
    public const SKIP = 'skip';

    public const LEGACY_VOID = 'legacy_void';

    public const PLAN = 'plan';

    public const ERROR = 'error';

    private ?bool $hasOrigin = null;

    private ?int $mainId = null;

    /** @var array<int, int> */
    private array $branchOf = [];

    public function __construct(private PostingRules $rules) {}

    /**
     * Lines (status PLAN) are [{ref, branch_id, debit, credit, date, contra_of_new?}] in insert order.
     *
     * @return array{status: string, lines?: array, error?: string}
     */
    public function plan(Transaction $t): array
    {
        $lines = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.transaction_id', $t->id)->orderBy('le.id')
            ->select('le.id', 'le.branch_id', 'le.entry_date', 'le.debit', 'le.credit', 'le.contra_of_id', 'a.type as account_type')
            ->when($this->hasOrigin(), fn ($q) => $q->addSelect('le.origin'))
            ->get();
        $ref = $t->ref_no ?? "#{$t->id}";

        if ($lines->isEmpty()) {
            // AG-051 R13: a transfer request that never posted has no lines by design (ledger:verify V9 asserts it).
            if (in_array($t->status, ['pending', 'rejected'], true)) {
                return ['status' => self::SKIP];
            }

            return $t->status === 'void' ? ['status' => self::LEGACY_VOID]
                : $this->error("Transaction {$ref} has no ledger lines and is not a legacy void.");
        }
        // Already double entry (posted after the migration, or back-filled): skip when it balances per branch.
        // A voided entry with money lines only also nets to zero, so "balances" alone is not the test.
        if ($lines->contains(fn ($l) => ! in_array($l->account_type, Account::MONEY_TYPES, true))) {
            if (self::balances($lines)) {
                return ['status' => self::SKIP];
            }
            // Finding 5: never add a second back-fill set.
            if ($lines->contains(fn ($l) => ($l->origin ?? null) === 'backfill')) {
                return $this->error("Transaction {$ref} has back-filled lines but does not balance. The back-fill stopped; no second back-fill set is added. Run ledger:verify.");
            }

            return $this->error("Transaction {$ref} does not balance. The back-fill stopped. Run ledger:verify.");
        }

        $o = $t->type === Transaction::REVERSAL ? Transaction::withoutGlobalScopes()->find($t->reversal_of_id) : $t;
        if (! $o) {
            return $this->error("Reversal {$ref} has no original transaction.");
        }
        $cents = Money::toCents($o->amount);
        $spec = match ($o->type) {
            Transaction::INCOME, Transaction::EXPENSE => $o->category_id === null ? null
                : $this->rules->incomeExpense($o->type, (int) $o->account_id, $this->branchOf($o->account_id), (int) $o->category_id, $cents),
            Transaction::CAPITAL => $this->rules->capital((int) $o->account_id, $this->branchOf($o->account_id), $this->mainId(), $cents),
            Transaction::OPENING_ALLOCATION, Transaction::FUND_ALLOCATION, Transaction::FUND_RETURN, Transaction::BRANCH_TRANSFER, Transaction::INTERNAL_TRANSFER => $this->rules->transfer(
                (int) $o->from_account_id, $this->branchOf($o->from_account_id), (int) $o->to_account_id, $this->branchOf($o->to_account_id), $this->mainId(), $cents),
            default => null,
        };
        if ($spec === null) {
            return $this->error("Transaction {$ref} ({$o->type}) cannot be back-filled: its category or accounts are missing.");
        }

        $add = array_values(array_filter($spec, fn ($s) => $s['ref'][0] !== PostingRules::MONEY));
        if (! $add) {
            // Internal transfer (and its reversal): both lines are money lines in one branch.
            return self::balances($lines) ? ['status' => self::SKIP] : $this->error("Transaction {$ref} does not balance. Run ledger:verify.");
        }
        if ($t->type === Transaction::REVERSAL) {
            $add = PostingRules::mirror($add);
        }
        $date = $t->txn_date->format('Y-m-d');
        $planned = array_map(fn ($s) => $s + ['date' => $date], $add);
        $contra = $lines->whereNotNull('contra_of_id')->first();
        if ($contra) {
            foreach ($add as $i => $s) {
                $planned[] = ['ref' => $s['ref'], 'branch_id' => $s['branch_id'], 'debit' => $s['credit'], 'credit' => $s['debit'],
                    'date' => substr((string) $contra->entry_date, 0, 10), 'contra_of_new' => $i];
            }
        }

        $after = $lines->map(fn ($l) => (object) ['branch_id' => $l->branch_id, 'debit' => $l->debit, 'credit' => $l->credit])
            ->merge(array_map(fn ($p) => (object) ['branch_id' => $p['branch_id'], 'debit' => Money::fromCents($p['debit']), 'credit' => Money::fromCents($p['credit'])], $planned));
        if (! $planned || ! self::balances($after)) {
            return $this->error("Transaction {$ref} would not balance after the back-fill. Check its lines with ledger:verify.");
        }

        return ['status' => self::PLAN, 'lines' => $planned];
    }

    /** Debits = credits for the lines in total and per branch (integer cents). */
    public static function balances(Collection $lines): bool
    {
        $net = [];
        foreach ($lines as $l) {
            $net[(int) $l->branch_id] = ($net[(int) $l->branch_id] ?? 0) + Money::toCents($l->debit) - Money::toCents($l->credit);
        }

        return ! array_filter($net);
    }

    /** "Sales > Retail (KDY)", "Head office current account (KDY)", ... for the dry-run sample. Works before the migration. */
    public function describe(array $ref, int $branchId): string
    {
        $code = Branch::whereKey($branchId)->value('code');

        return match ($ref[0]) {
            PostingRules::CATEGORY => (($c = Category::find($ref[1])) ? ucfirst($c->type).' '.ChartOfAccounts::categoryAccountName($c) : "category #{$ref[1]}")." ({$code})",
            PostingRules::HEAD_OFFICE => ChartOfAccounts::NAME_HEAD_OFFICE." ({$code})",
            PostingRules::BRANCH_CURRENT => 'Branch current account – '.Branch::whereKey($ref[1])->value('code')." ({$code})",
            PostingRules::OWNER_CAPITAL => ChartOfAccounts::NAME_OWNER_CAPITAL." ({$code})",
            default => "account #{$ref[1]} ({$code})",
        };
    }

    /** Memoised (review R2): an account never changes branch. */
    private function branchOf(int|string|null $accountId): int
    {
        return $this->branchOf[(int) $accountId] ??= (int) DB::table('accounts')->where('id', $accountId)->value('branch_id');
    }

    private function mainId(): int
    {
        return $this->mainId ??= (int) Branch::where('is_main', true)->value('id');
    }

    private function hasOrigin(): bool
    {
        return $this->hasOrigin ??= Schema::hasColumn('ledger_entries', 'origin');
    }

    private function error(string $message): array
    {
        return ['status' => self::ERROR, 'error' => $message];
    }
}
