<?php

namespace App\Services;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The only place money is posted. Every posting:
 *  - runs in one DB transaction with row locks on the affected accounts,
 *  - writes balanced ledger entries (debit = money in, credit = money out),
 *  - updates the cached account balances,
 *  - writes an audit log entry.
 */
class LedgerService
{
    /** Owner capital into a Main account (one-off opening equity). */
    public function capital(int $accountId, string $amount, string $date, ?string $note = null): Transaction
    {
        return DB::transaction(function () use ($accountId, $amount, $date, $note) {
            $acc = $this->lock([$accountId])[$accountId];
            if (! $acc->is_main_account && ! $acc->branch->is_main) {
                throw new LedgerException('Capital can only be added to a Main branch account.');
            }
            $this->assertOpen($acc->branch_id, $date);

            $txn = $this->newTxn(Transaction::CAPITAL, $acc->branch_id, $date, $amount, [
                'account_id' => $acc->id, 'description' => $note,
            ]);
            $this->entry($txn, $acc, debit: $amount);

            return $this->finish($txn);
        });
    }

    /**
     * Move money between two accounts. Covers opening allocation, top-up, fund return,
     * branch-to-branch and internal transfers. The type is derived from the two branches
     * unless $type is given (e.g. opening_allocation).
     */
    public function transfer(int $fromId, int $toId, string $amount, string $date, ?string $note = null, ?string $type = null): Transaction
    {
        if ($fromId === $toId) {
            throw new LedgerException('Source and destination accounts must be different.');
        }

        return DB::transaction(function () use ($fromId, $toId, $amount, $date, $note, $type) {
            $locked = $this->lock([$fromId, $toId]);
            $from = $locked[$fromId];
            $to = $locked[$toId];

            foreach ([$from, $to] as $a) {
                if ($a->status !== 'active' || $a->branch->status !== 'active') {
                    throw new LedgerException("Account {$a->name} or its branch is inactive.");
                }
            }
            $this->assertOpen($from->branch_id, $date);
            $this->assertOpen($to->branch_id, $date);
            $this->assertFunds($from, $amount);

            $type ??= $this->transferType($from, $to);

            $txn = $this->newTxn($type, $from->branch_id, $date, $amount, [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'related_branch_id' => $to->branch_id,
                'description' => $note,
            ]);
            $this->entry($txn, $from, credit: $amount);
            $this->entry($txn, $to, debit: $amount);

            return $this->finish($txn);
        });
    }

    /** Record income (money in) or expense (money out) against a category. */
    public function incomeOrExpense(string $type, array $data): Transaction
    {
        if (! in_array($type, [Transaction::INCOME, Transaction::EXPENSE], true)) {
            throw new LedgerException('Invalid type.');
        }

        return DB::transaction(function () use ($type, $data) {
            $acc = $this->lock([(int) $data['account_id']])[(int) $data['account_id']];
            if ($acc->status !== 'active') {
                throw new LedgerException('Account is inactive.');
            }
            $cat = Category::findOrFail($data['category_id']);
            if ($cat->type !== $type || ! $cat->is_active) {
                throw new LedgerException("Category must be an active {$type} category.");
            }
            if ($cat->branch_id && $cat->branch_id !== $acc->branch_id) {
                throw new LedgerException('Category belongs to another branch.');
            }
            $this->assertOpen($acc->branch_id, $data['txn_date']);
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
            $type === Transaction::INCOME
                ? $this->entry($txn, $acc, debit: $data['amount'])
                : $this->entry($txn, $acc, credit: $data['amount']);

            return $this->finish($txn);
        });
    }

    /** Post an exact opposite of a transaction and mark the original as reversed. */
    public function reverse(Transaction $original, string $date, ?string $reason = null): Transaction
    {
        return DB::transaction(function () use ($original, $date, $reason) {
            $original = Transaction::withoutGlobalScopes()->lockForUpdate()->findOrFail($original->id);
            if ($original->status !== 'posted' || $original->type === Transaction::REVERSAL) {
                throw new LedgerException('Only posted transactions can be reversed.');
            }
            $entries = $original->entries()->get();
            $accounts = $this->lock($entries->pluck('account_id')->unique()->all());

            foreach ($entries as $e) {
                $this->assertOpen($e->branch_id, $date);
                // Reversing money that came IN must not push the account negative.
                if ((float) $e->debit > 0) {
                    $this->assertFunds($accounts[$e->account_id], $e->debit);
                }
            }

            $txn = $this->newTxn(Transaction::REVERSAL, $original->branch_id, $date, $original->amount, [
                'reversal_of_id' => $original->id,
                'from_account_id' => $original->to_account_id,
                'to_account_id' => $original->from_account_id,
                'account_id' => $original->account_id,
                'related_branch_id' => $original->related_branch_id,
                'category_id' => $original->category_id,
                'description' => $reason ?? "Reversal of {$original->ref_no}",
            ]);
            foreach ($entries as $e) {
                $this->entry($txn, $accounts[$e->account_id], debit: $e->credit, credit: $e->debit);
            }
            $original->update(['status' => 'reversed']);
            AuditLog::record('transaction.reversed', $original, ['status' => 'posted'], ['status' => 'reversed', 'reversal_id' => $txn->id, 'self' => (int) $original->created_by === (int) auth()->id()]);

            return $this->finish($txn);
        });
    }

    /** Void a posted income/expense entry: removes its effect but keeps the record. */
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
            $this->assertOpen($txn->branch_id, $txn->txn_date->format('Y-m-d'));
            $acc = $this->lock([$txn->account_id])[$txn->account_id];
            if ($txn->type === Transaction::INCOME) {
                $this->assertFunds($acc, $txn->amount);
            }
            $txn->entries()->delete();
            $acc->current_balance = $acc->ledgerBalance();
            $acc->save();
            $txn->update(['status' => 'void', 'description' => trim(($txn->description ?? '')."\n[Void] ".($reason ?? ''))]);
            AuditLog::record('transaction.voided', $txn, ['status' => 'posted'], ['status' => 'void', 'reason' => $reason, 'self' => (int) $txn->created_by === (int) auth()->id()]);

            return $txn->fresh();
        });
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int, Account> keyed by id, locked FOR UPDATE in id order to avoid deadlocks */
    private function lock(array $ids): array
    {
        sort($ids);
        $rows = Account::withoutGlobalScopes()->with('branch')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($rows->count() !== count(array_unique($ids))) {
            throw new LedgerException('Account not found.');
        }

        return $rows->all();
    }

    private function assertOpen(int $branchId, string $date): void
    {
        if (PeriodLock::isLocked($branchId, $date)) {
            throw new LedgerException('The period '.substr($date, 0, 7).' is locked.');
        }
    }

    private function assertFunds(Account $acc, string $amount): void
    {
        if (Money::cmp($acc->current_balance, $amount) < 0) {
            throw new LedgerException("Insufficient balance in {$acc->name} (available {$acc->current_balance}).");
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
        if (Money::cmp($amount, 0) <= 0) {
            throw new LedgerException('Amount must be greater than zero.');
        }

        return Transaction::withoutGlobalScopes()->create(array_merge([
            'branch_id' => $branchId,
            'type' => $type,
            'txn_date' => $date,
            'amount' => $amount,
            'status' => 'posted',
            'created_by' => auth()->id(),
        ], $extra));
    }

    private function entry(Transaction $txn, Account $acc, string|float $debit = 0, string|float $credit = 0): void
    {
        LedgerEntry::create([
            'transaction_id' => $txn->id,
            'account_id' => $acc->id,
            'branch_id' => $acc->branch_id,
            'entry_date' => $txn->txn_date,
            'debit' => $debit,
            'credit' => $credit,
        ]);
        $acc->current_balance = Money::fromCents(Money::toCents($acc->current_balance) - Money::toCents($credit) + Money::toCents($debit));
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
