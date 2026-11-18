<?php

namespace App\Services;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The only place money is posted (CLAUDE.md), full double entry (docs/specs/double-entry.md).
 *
 * Debit and credit have their usual meaning on every account (R2): a debit increases an asset or an expense, a credit
 * increases a liability, equity or income. On a cash / bank / wallet account this is "debit = money in, credit = money
 * out", as before.
 *
 * Every posting set (an original posting, one reclass, one void's contras):
 *  - runs in one DB transaction and locks every account it touches FOR UPDATE, in id order;
 *  - asserts the period is open for each (branch, line date) of the set (R9);
 *  - is built as a list first and checked in integer cents: debits = credits for the whole set and for each branch (L1),
 *    every line one-sided (L2); it is written, then re-read per branch before commit;
 *  - updates the cached balances (current_balance = debit - credit, for every account class) and writes an audit row.
 *
 * The only exception is the back-fill path (R6 / finding 6): see backfill().
 */
class LedgerService
{
    public const NOT_BALANCED = 'Posting is not balanced.';

    public const MONEY_ONLY = 'Choose a cash, bank or wallet account.';

    public const WRONG_BRANCH = 'A ledger line is on an account of another branch. Nothing was posted.';

    /** Back-fill only: account id per posting-rule reference (see postBackfill). */
    private array $backfillRefs = [];

    public function __construct(private PostingRules $rules, private ChartOfAccounts $chart, private BackfillPlanner $planner) {}

    /**
     * Owner capital (AG-043) into a MAIN account, or into the is_main_account of a non-main branch.
     * Owner's capital exists only in MAIN (Q10): capital into a non-main branch posts 4 lines.
     */
    public function capital(int $accountId, string $amount, string $date, ?string $note = null): Transaction
    {
        $date = $this->date($date);
        $this->assertPositive($amount);

        return DB::transaction(function () use ($accountId, $amount, $date, $note) {
            $acc = $this->moneyAccount($accountId);
            if (! $acc->is_main_account && ! $acc->branch->is_main) {
                throw new LedgerException('Capital can only be added to a Main branch account.');
            }
            [$lines, $locked] = $this->prepare($this->rules->capital($acc->id, $acc->branch_id, Branch::main()->id, Money::toCents($amount)), $date);
            $acc = $locked[$acc->id];
            $this->assertOpenFor($lines);

            $txn = $this->newTxn(Transaction::CAPITAL, $acc->branch_id, $date, $amount, [
                'account_id' => $acc->id, 'description' => $note,
            ]);
            $this->post($txn, $lines);

            return $this->finish($txn);
        });
    }

    /**
     * Move money between two accounts. Covers opening allocation, top-up, fund return,
     * branch-to-branch and internal transfers. The type is derived from the two branches
     * unless $type is given (e.g. opening_allocation). Cross-branch movements go through head office (R3).
     */
    public function transfer(int $fromId, int $toId, string $amount, string $date, ?string $note = null, ?string $type = null): Transaction
    {
        if ($fromId === $toId) {
            throw new LedgerException('Source and destination accounts must be different.');
        }

        $date = $this->date($date);
        $this->assertPositive($amount);

        return DB::transaction(function () use ($fromId, $toId, $amount, $date, $note, $type) {
            $from = $this->moneyAccount($fromId);
            $to = $this->moneyAccount($toId);
            $spec = $this->rules->transfer($from->id, $from->branch_id, $to->id, $to->branch_id, Branch::main()->id, Money::toCents($amount));
            [$lines, $locked] = $this->prepare($spec, $date);
            $from = $locked[$fromId];
            $to = $locked[$toId];

            foreach ([$from, $to] as $a) {
                if ($a->status !== 'active' || $a->branch->status !== 'active') {
                    throw new LedgerException("Account {$a->name} or its branch is inactive.");
                }
            }
            $this->assertOpenFor($lines, headOfficeSide: ! $from->branch->is_main && ! $to->branch->is_main);
            $this->assertFunds($from, $amount);

            $type ??= $this->transferType($from, $to);

            $txn = $this->newTxn($type, $from->branch_id, $date, $amount, [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'related_branch_id' => $to->branch_id,
                'description' => $note,
            ]);
            $this->post($txn, $lines);

            return $this->finish($txn);
        });
    }

    /** Record income (Dr money, Cr income category) or expense (Dr expense category, Cr money). */
    public function incomeOrExpense(string $type, array $data): Transaction
    {
        if (! in_array($type, [Transaction::INCOME, Transaction::EXPENSE], true)) {
            throw new LedgerException('Invalid type.');
        }

        $data['txn_date'] = $this->date((string) ($data['txn_date'] ?? ''));
        $this->assertPositive((string) $data['amount']);

        return DB::transaction(function () use ($type, $data) {
            $acc = $this->moneyAccount((int) $data['account_id']);
            $cat = Category::findOrFail($data['category_id']);
            if ($cat->type !== $type || ! $cat->is_active) {
                throw new LedgerException("Category must be an active {$type} category.");
            }
            if ($cat->branch_id && $cat->branch_id !== $acc->branch_id) {
                throw new LedgerException('Category belongs to another branch.');
            }
            [$lines, $locked] = $this->prepare($this->rules->incomeExpense($type, $acc->id, $acc->branch_id, $cat->id, Money::toCents($data['amount'])), $data['txn_date']);
            $acc = $locked[$acc->id];
            if ($acc->status !== 'active') {
                throw new LedgerException('Account is inactive.');
            }
            $this->assertOpenFor($lines);
            // R10: expense_negative_balance applies to the money account only.
            if ($type === Transaction::EXPENSE && Setting::get('expense_negative_balance', 'block') === 'block') {
                $this->assertFunds($acc, $data['amount']);
            }

            $txn = $this->newTxn($type, $acc->branch_id, $data['txn_date'], $data['amount'], [
                'account_id' => $acc->id,
                'category_id' => $cat->id,
                'payee_payer' => $data['payee_payer'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
            $this->post($txn, $lines);

            return $this->finish($txn);
        });
    }

    /**
     * Change the category of a posted income/expense (R3, finding 4): moves the amount from the CURRENT category to the
     * new one with two `reclass` lines on the original date, and updates transactions.category_id in the same DB
     * transaction. Nothing is posted when the category is unchanged.
     *
     * @return int[]|null the new line ids, or null when nothing changed
     */
    public function reclassify(Transaction $txn, int $categoryId): ?array
    {
        return DB::transaction(function () use ($txn, $categoryId) {
            $txn = Transaction::withoutGlobalScopes()->lockForUpdate()->findOrFail($txn->id);
            if (! in_array($txn->type, [Transaction::INCOME, Transaction::EXPENSE], true) || $txn->status !== 'posted') {
                throw new LedgerException('This entry cannot be edited.');
            }
            if ((int) $txn->category_id === $categoryId) {
                return null;
            }
            if ($txn->category_id === null) {
                throw new LedgerException('This entry has no category to move from.');
            }
            $new = Category::find($categoryId);
            if (! $new || $new->type !== $txn->type || ! $new->is_active || ($new->branch_id && $new->branch_id !== $txn->branch_id)) {
                throw new LedgerException("Choose an active {$txn->type} category of this branch.");
            }
            $date = $txn->txn_date->format('Y-m-d');
            $amount = Money::add($txn->amount);
            $spec = $this->rules->reclass($txn->type, $txn->branch_id, (int) $txn->category_id, $new->id, Money::toCents($amount));
            [$lines] = $this->prepare($spec, $date, 'reclass');
            $this->assertOpenFor($lines);

            $entries = $this->post($txn, $lines);
            $old = (int) $txn->category_id;
            $txn->update(['category_id' => $new->id]);
            $ids = array_map(fn (LedgerEntry $e) => $e->id, $entries);
            AuditLog::record('transaction.reclassified', $txn, ['category_id' => $old], [
                'old_category_id' => $old, 'new_category_id' => $new->id, 'amount' => $amount, 'line_ids' => $ids,
                'ref_no' => $txn->ref_no, 'self' => (int) $txn->created_by === (int) auth()->id(),
            ]);

            return $ids;
        });
    }

    /**
     * Post an exact opposite of a transaction and mark the original as reversed: every line of the original (posting,
     * reclass and back-filled) is mirrored, dated the reversal date.
     * The reversal date must be on or after the original's date and not in the future (controls finding 4),
     * and open for every branch that has a line (for a branch transfer: sender, receiver and MAIN).
     */
    public function reverse(Transaction $original, string $date, ?string $reason = null): Transaction
    {
        $date = $this->date($date);

        return DB::transaction(function () use ($original, $date, $reason) {
            $original = Transaction::withoutGlobalScopes()->lockForUpdate()->findOrFail($original->id);
            if ($original->status !== 'posted' || $original->type === Transaction::REVERSAL) {
                throw new LedgerException('Only posted transactions can be reversed.');
            }
            if ($date < $original->txn_date->format('Y-m-d')) {
                throw new LedgerException('The reversal date cannot be before the original entry date.');
            }
            if ($date > BusinessDate::today()) {
                throw new LedgerException('The reversal date cannot be in the future.');
            }
            $entries = $original->entries()->orderBy('id')->get();
            $accounts = $this->lock($entries->pluck('account_id')->unique()->all());
            $lines = $entries->map(fn (LedgerEntry $e) => $this->line($accounts[$e->account_id], Money::toCents($e->credit), Money::toCents($e->debit), $date))->all();

            $this->assertOpenFor($lines, headOfficeSide: $original->type === Transaction::BRANCH_TRANSFER);
            // Reversing money that came IN must not push a money account negative (net credit per money account).
            $this->assertFundsForNetCredits($lines);

            $txn = $this->newTxn(Transaction::REVERSAL, $original->branch_id, $date, $original->amount, [
                'reversal_of_id' => $original->id,
                'from_account_id' => $original->to_account_id,
                'to_account_id' => $original->from_account_id,
                'account_id' => $original->account_id,
                'related_branch_id' => $original->related_branch_id,
                'category_id' => $original->category_id,
                'description' => $reason ?? "Reversal of {$original->ref_no}",
            ]);
            $this->post($txn, $lines);
            $original->update(['status' => 'reversed']);
            AuditLog::record('transaction.reversed', $original, ['status' => 'posted'], ['status' => 'reversed', 'reversal_id' => $txn->id, 'reversal_date' => $date, 'reason' => $reason, 'self' => (int) $original->created_by === (int) auth()->id()]);

            return $this->finish($txn);
        });
    }

    /**
     * Void a posted income/expense entry (controls spec R1, double-entry finding 3): the original lines are kept and one
     * contra (debit and credit swapped, same account, branch and date, contra_of_id = original) is posted for EVERY
     * line that is not itself a contra. The transaction then has status `void` and nets to zero on every account.
     */
    public function void(Transaction $txn, ?string $reason = null): Transaction
    {
        if (! in_array($txn->type, [Transaction::INCOME, Transaction::EXPENSE], true)) {
            throw new LedgerException('Only income and expense entries can be voided. Use reverse for transfers.');
        }

        return DB::transaction(function () use ($txn, $reason) {
            $txn = Transaction::withoutGlobalScopes()->lockForUpdate()->findOrFail($txn->id);
            if ($txn->status !== 'posted') {
                throw new LedgerException('Only posted entries can be voided.');
            }
            $date = $txn->txn_date->format('Y-m-d');
            $originals = $txn->entries()->whereNull('contra_of_id')->orderBy('id')->get();
            $accounts = $this->lock($originals->pluck('account_id')->unique()->all());
            $lines = $originals->map(fn (LedgerEntry $e) => $this->line($accounts[$e->account_id], Money::toCents($e->credit), Money::toCents($e->debit),
                $e->entry_date->format('Y-m-d'), contraOf: $e->id))->all();
            $this->assertOpen($txn->branch_id, $date);
            $this->assertOpenFor($lines);
            $this->assertFundsForNetCredits($lines);

            $contraIds = array_map(fn (LedgerEntry $e) => $e->id, $this->post($txn, $lines));
            $txn->update(['status' => 'void', 'description' => trim(($txn->description ?? '')."\n[Void] ".($reason ?? ''))]);
            // Finding 5: the row describes what was voided without a join to ledger_entries.
            AuditLog::record('transaction.voided', $txn, ['status' => 'posted'], [
                'status' => 'void',
                'reason' => $reason,
                'ref_no' => $txn->ref_no,
                'type' => $txn->type,
                'amount' => Money::add($txn->amount),
                'account_id' => $txn->account_id,
                'txn_date' => $date,
                'self' => (int) $txn->created_by === (int) auth()->id(),
                'contra_entry_ids' => $contraIds,
            ]);

            return $txn->fresh();
        });
    }

    /**
     * Double-entry back-fill of one existing transaction (R6). This is the back-fill path, the only place that does not
     * assert open periods (R9 exemption, finding 6). It takes no lines and no flags from the caller: it plans the missing
     * side itself (BackfillPlanner) and may insert only `origin = backfill` lines, never on a money account, so no
     * cash / bank / wallet balance can change. The transaction must balance per branch afterwards.
     *
     * The plan is made once, under the transaction's row lock, and returned with the lines written, so the caller
     * (DoubleEntryBackfill) never plans twice (review R2).
     *
     * @return array{status: string, lines: array, entries: LedgerEntry[]} status is a BackfillPlanner status; entries
     *                                                                     are the lines added (none for skip / legacy void)
     */
    public function backfill(Transaction $txn): array
    {
        try {
            return DB::transaction(function () use ($txn) {
                $txn = Transaction::withoutGlobalScopes()->lockForUpdate()->findOrFail($txn->id);
                $plan = $this->planner->plan($txn);
                if ($plan['status'] === BackfillPlanner::ERROR) {
                    throw new LedgerException($plan['error']);
                }
                if ($plan['status'] !== BackfillPlanner::PLAN) {
                    return ['status' => $plan['status'], 'lines' => [], 'entries' => []];
                }

                return ['status' => $plan['status'], 'lines' => $plan['lines'], 'entries' => $this->postBackfill($txn, $plan['lines'])];
            });
        } catch (\Throwable $e) {
            // An account created inside the rolled-back DB transaction no longer exists.
            $this->backfillRefs = [];
            throw $e;
        }
    }

    // ---------------------------------------------------------------- helpers

    /** The private back-fill path: resolve, lock and insert the planned lines without any period-lock check. */
    private function postBackfill(Transaction $txn, array $planned): array
    {
        $ids = [];
        foreach ($planned as $p) {
            if ($p['ref'][0] === PostingRules::MONEY) {
                throw new LedgerException("Back-fill of {$txn->ref_no} refused: it may not post to a money account.");
            }
            // Memoised per reference for the run (review R2): chart accounts are never deleted.
            $key = implode(':', $p['ref']);
            $ids[] = $this->backfillRefs[$key] ??= $this->chart->resolve($p['ref'])->id;
        }
        $accounts = $this->lock(array_unique($ids), false);

        $written = [];
        $net = [];
        foreach ($planned as $i => $p) {
            $acc = $accounts[$ids[$i]];
            if ($acc->isMoney() || (int) $acc->branch_id !== (int) $p['branch_id']) {
                throw new LedgerException("Back-fill of {$txn->ref_no} refused: line on account #{$acc->id} is not allowed.");
            }
            $contraOf = isset($p['contra_of_new']) ? $written[$p['contra_of_new']]->id : null;
            $written[$i] = $this->entry($txn, $this->line($acc, $p['debit'], $p['credit'], $p['date'], $contraOf, 'backfill'), false);
            $net[$acc->id] = ($net[$acc->id] ?? 0) + $p['debit'] - $p['credit'];
        }
        // One balance update per account (review R2); a voided entry's line and its contra net to zero.
        foreach ($net as $id => $cents) {
            $this->applyBalance($accounts[$id], $cents);
        }
        // L1 for the back-fill: the set completes the transaction, so the whole transaction must now balance per branch.
        $this->assertTransactionBalanced($txn->id);

        return array_values($written);
    }

    /**
     * Resolve the account references of a rule set (creating a missing system / category account, R1 safety net),
     * lock every account in id order, and return [lines, locked accounts by id].
     */
    private function prepare(array $spec, string $date, string $origin = 'posting'): array
    {
        $ids = [];
        foreach ($spec as $s) {
            $ids[] = $s['ref'][0] === PostingRules::MONEY ? (int) $s['ref'][1] : $this->chart->resolve($s['ref'])->id;
        }
        $locked = $this->lock(array_unique($ids));
        $lines = [];
        foreach ($spec as $i => $s) {
            $acc = $locked[$ids[$i]];
            if ((int) $acc->branch_id !== (int) $s['branch_id']) {
                throw new LedgerException(self::WRONG_BRANCH);
            }
            $lines[] = $this->line($acc, $s['debit'], $s['credit'], $date, null, $origin);
        }

        return [$lines, $locked];
    }

    private function line(Account $acc, int $debit, int $credit, string $date, ?int $contraOf = null, string $origin = 'posting'): array
    {
        return ['account' => $acc, 'debit' => $debit, 'credit' => $credit, 'date' => $date, 'contra_of' => $contraOf, 'origin' => $origin];
    }

    /** L1 + L2, write, then re-read per branch before commit. @return LedgerEntry[] */
    private function post(Transaction $txn, array $lines): array
    {
        $this->assertBalanced($lines);
        $entries = array_map(fn ($l) => $this->entry($txn, $l), $lines);
        $this->assertWrittenBalanced(array_map(fn (LedgerEntry $e) => $e->id, $entries));

        return $entries;
    }

    /** L2: one side > 0, none negative. L1: debits = credits for the set and for each branch in it (integer cents). */
    private function assertBalanced(array $lines): void
    {
        $byBranch = [];
        foreach ($lines as $l) {
            if ($l['debit'] < 0 || $l['credit'] < 0 || ($l['debit'] > 0) === ($l['credit'] > 0)) {
                throw new LedgerException(self::NOT_BALANCED);
            }
            $b = $l['account']->branch_id;
            $byBranch[$b] = ($byBranch[$b] ?? 0) + $l['debit'] - $l['credit'];
        }
        if (! $lines || array_sum($byBranch) !== 0 || array_filter($byBranch)) {
            throw new LedgerException(self::NOT_BALANCED);
        }
    }

    /** Every line of the transaction, per branch, in one query (back-fill). */
    private function assertTransactionBalanced(int $txnId): void
    {
        $dr = Money::sqlCents('debit');
        $cr = Money::sqlCents('credit');
        $rows = LedgerEntry::where('transaction_id', $txnId)->groupBy('branch_id')->selectRaw("branch_id, SUM($dr) as dr, SUM($cr) as cr")->get();
        foreach ($rows as $r) {
            if ((int) $r->dr !== (int) $r->cr) {
                throw new LedgerException(self::NOT_BALANCED);
            }
        }
    }

    /** L1 step 4: SUM(debit) = SUM(credit) per branch for the written lines, read back inside the DB transaction. */
    private function assertWrittenBalanced(array $ids): void
    {
        $dr = Money::sqlCents('debit');
        $cr = Money::sqlCents('credit');
        $rows = LedgerEntry::whereIn('id', $ids)->groupBy('branch_id')->selectRaw("branch_id, SUM($dr) as dr, SUM($cr) as cr")->get();
        foreach ($rows as $r) {
            if ((int) $r->dr !== (int) $r->cr) {
                throw new LedgerException(self::NOT_BALANCED);
            }
        }
    }

    /** A cash / bank / wallet account (R3 "money accounts only"), any branch, without the branch scope. */
    private function moneyAccount(int $id): Account
    {
        $acc = Account::withoutGlobalScopes()->with('branch')->find($id);
        if (! $acc) {
            throw new LedgerException('Account not found.');
        }
        if (! $acc->isMoney()) {
            throw new LedgerException(self::MONEY_ONLY);
        }

        return $acc;
    }

    /** @return array<int, Account> keyed by id, locked FOR UPDATE in id order to avoid deadlocks */
    private function lock(array $ids, bool $withBranch = true): array
    {
        sort($ids);
        $rows = Account::withoutGlobalScopes()->when($withBranch, fn ($q) => $q->with('branch'))->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($rows->count() !== count(array_unique($ids))) {
            throw new LedgerException('Account not found.');
        }

        return $rows->all();
    }

    /** Accountant sign-off A: one strict YYYY-MM-DD date is both lock-checked and stored. */
    private function date(string $date): string
    {
        try {
            return BusinessDate::normalize($date);
        } catch (\InvalidArgumentException) {
            throw new LedgerException('The date must be in YYYY-MM-DD format.');
        }
    }

    private function assertPositive(string $amount): void
    {
        if (Money::cmp($amount, 0) <= 0) {
            throw new LedgerException('Amount must be greater than zero.');
        }
    }

    /**
     * R9: every (branch, line date) of the set must be open; branches with a money line first, then MAIN.
     * $headOfficeSide: MAIN is only the head-office side of a branch transfer, and the message says so.
     */
    private function assertOpenFor(array $lines, bool $headOfficeSide = false): void
    {
        $checks = [];
        foreach ($lines as $l) {
            $checks[$l['account']->branch_id.'|'.$l['date']] = [$l['account']->branch, $l['date']];
        }
        uasort($checks, fn ($a, $b) => [$a[0]->is_main, $a[1]] <=> [$b[0]->is_main, $b[1]]);
        foreach ($checks as [$branch, $date]) {
            if ($headOfficeSide && $branch->is_main) {
                if (PeriodLock::isLocked($branch->id, $date, share: true)) {
                    throw new LedgerException('The period '.substr($date, 0, 7)." is locked for {$branch->code} (head office side of this transfer).");
                }

                continue;
            }
            $this->assertOpen($branch->id, $date);
        }
    }

    private function assertOpen(int $branchId, string $date): void
    {
        // Locking read: a concurrent period lock for this branch/month waits for this posting (reviewer R5).
        if (PeriodLock::isLocked($branchId, $date, share: true)) {
            throw new LedgerException('The period '.substr($date, 0, 7).' is locked.');
        }
    }

    private function assertFunds(Account $acc, string $amount): void
    {
        if (Money::cmp($acc->current_balance, $amount) < 0) {
            throw new LedgerException("Insufficient balance in {$acc->name} (available {$acc->current_balance}).");
        }
    }

    /** Finding 3: the funds check applies only to a money account being credited, on its net credit in the set. */
    private function assertFundsForNetCredits(array $lines): void
    {
        $net = [];
        foreach ($lines as $l) {
            if ($l['account']->isMoney()) {
                $net[$l['account']->id] = ($net[$l['account']->id] ?? 0) + $l['credit'] - $l['debit'];
            }
        }
        foreach ($lines as $l) {
            $id = $l['account']->id;
            if (isset($net[$id]) && $net[$id] > 0) {
                $this->assertFunds($l['account'], Money::fromCents($net[$id]));
                unset($net[$id]);
            }
        }
    }

    private function transferType(Account $from, Account $to): string
    {
        if ($from->branch_id === $to->branch_id) {
            return Transaction::INTERNAL_TRANSFER;
        }
        if ($from->branch->is_main) {
            return Transaction::FUND_ALLOCATION;
        }
        if ($to->branch->is_main) {
            return Transaction::FUND_RETURN;
        }

        return Transaction::BRANCH_TRANSFER;
    }

    private function newTxn(string $type, int $branchId, string $date, string $amount, array $extra): Transaction
    {
        $this->assertPositive($amount);

        return Transaction::withoutGlobalScopes()->create(array_merge([
            'branch_id' => $branchId,
            'type' => $type,
            'txn_date' => $date,
            'amount' => $amount,
            'status' => 'posted',
            'created_by' => auth()->id(),
        ], $extra));
    }

    /** The only writer of ledger_entries and current_balance. */
    /** Writes the line and, unless $applyBalance is false (the back-fill applies the net per account), the cached balance. */
    private function entry(Transaction $txn, array $l, bool $applyBalance = true): LedgerEntry
    {
        $acc = $l['account'];
        $entry = LedgerEntry::create([
            'transaction_id' => $txn->id,
            'contra_of_id' => $l['contra_of'],
            'account_id' => $acc->id,
            'branch_id' => $acc->branch_id,
            'entry_date' => $l['date'],
            'debit' => Money::fromCents($l['debit']),
            'credit' => Money::fromCents($l['credit']),
            'origin' => $l['origin'],
        ]);
        if ($applyBalance) {
            $this->applyBalance($acc, $l['debit'] - $l['credit']);
        }

        return $entry;
    }

    /** The only writer of accounts.current_balance (CLAUDE.md): debit - credit, in integer cents, on a locked account. */
    private function applyBalance(Account $acc, int $cents): void
    {
        if ($cents === 0) {
            return;
        }
        $acc->current_balance = Money::fromCents(Money::toCents($acc->current_balance) + $cents);
        $acc->save();
    }

    private function finish(Transaction $txn): Transaction
    {
        $prefix = ['capital' => 'CAP', 'opening_allocation' => 'OPN', 'fund_allocation' => 'ALC', 'fund_return' => 'RET',
            'branch_transfer' => 'TRF', 'internal_transfer' => 'INT', 'income' => 'INC', 'expense' => 'EXP', 'reversal' => 'REV'][$txn->type];
        $txn->ref_no = sprintf('%s-%s-%s-%06d', $txn->branch->code, $prefix, substr((string) $txn->txn_date->format('Y'), 0, 4), $txn->id);
        $txn->save();
        AuditLog::record("transaction.{$txn->type}", $txn, null, $txn->only(['ref_no', 'amount', 'txn_date', 'from_account_id', 'to_account_id', 'account_id', 'category_id']));

        return $txn->load('entries');
    }
}
