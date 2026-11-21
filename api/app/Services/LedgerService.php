<?php

namespace App\Services;

use App\Events\ExpenseApprovalRequested;
use App\Events\TransferApprovalRequested;
use App\Events\TransferReceiptRequested;
use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
use App\Models\YearEndClose;
use App\Notifications\YearEndNotification;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\FiscalYear;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
 * The only exceptions are the back-fill path (R6 / finding 6, see backfill()) and the year-end close path
 * (settings spec R8, see closeYear() / reopenYear() and postClose()). Neither takes a bypass flag from the caller,
 * and neither writes in a closed fiscal year except the close itself.
 */
class LedgerService
{
    public const NOT_BALANCED = 'Posting is not balanced.';

    public const MONEY_ONLY = 'Choose a cash, bank or wallet account.';

    /**
     * Review R3b: a posting that loses a deadlock (MySQL 1213 / 40001) or a lock wait is retried as a whole, up to
     * this many attempts, instead of answering 500. Nested in an outer DB transaction, the outer one decides.
     */
    public const ATTEMPTS = 3;

    public const WRONG_BRANCH = 'A ledger line is on an account of another branch. Nothing was posted.';

    /** Settings spec API table: edit, void and reverse of a closing transaction. */
    public const YEAR_END_LOCKED = 'Year-end closing entries change only by reopening the year.';

    /** Back-fill only: account id per posting-rule reference (see postBackfill). */
    private array $backfillRefs = [];

    public function __construct(private PostingRules $rules, private ChartOfAccounts $chart, private BackfillPlanner $planner, private YearEnd $yearEnd) {}

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
        }, self::ATTEMPTS);
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
                    throw new LedgerException('Account '.trim(($a->branch?->code ?? '').' '.$a->name).' or its branch is inactive.');
                }
            }
            // MAIN is only the head-office side of a branch transfer or a fund return (fund return G2).
            $this->assertOpenFor($lines, headOfficeOf: match (true) {
                $from->branch->is_main => null,
                $to->branch->is_main => 'fund return',
                default => 'transfer',
            });
            $this->assertFunds($from, $amount);

            $type ??= $this->transferType($from, $to);

            // AG-051 R2 / R5: above the threshold a non-Super-Admin's transfer waits for Main's approval. Every check
            // above has run; the request posts nothing.
            $approvals = app(TransferApprovals::class);
            $rule = $approvals->rule(auth()->user(), $type, Money::toCents($amount));
            if ($rule === TransferApprovals::HOLD) {
                return $this->request($type, $from, $to, $date, $amount, $note);
            }

            $txn = $this->newTxn($type, $from->branch_id, $date, $amount, [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'related_branch_id' => $to->branch_id,
                'description' => $note,
                // AG-052 R3: the setting at this moment decides; the receipt posts nothing (R1).
                'receipt_status' => app(TransferReceipts::class)->initialStatus($type),
            ]);
            $this->post($txn, $lines);
            if ($txn->receipt_status === TransferReceipts::AWAITING) {
                $userId = auth()->id();
                DB::afterCommit(fn () => TransferReceiptRequested::dispatch($txn->id, $userId));
            }

            // AG-051 accounting review finding 6: a Super Admin's movement above the threshold is flagged in the audit.
            return $this->finish($txn, $rule === TransferApprovals::NOT_REQUIRED_SUPER_ADMIN ? ['approval' => $rule] : []);
        }, self::ATTEMPTS);
    }

    /**
     * AG-051 R5: the pending request. A `transactions` row with status pending and no ledger lines; the accounts are
     * locked by the caller, so the pending total read here (R17 warning) is consistent with the balance.
     */
    private function request(string $type, Account $from, Account $to, string $date, string $amount, ?string $note): Transaction
    {
        $approvals = app(TransferApprovals::class);
        $other = $approvals->pendingFromAccount($from->id, current: true);
        $threshold = app(Settings::class)->transferApprovalThresholdCents();
        $txn = $this->newTxn($type, $from->branch_id, $date, $amount, [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'related_branch_id' => $to->branch_id,
            'description' => $note,
            'status' => 'pending',
            'approval_status' => TransferApprovals::PENDING,
            'requested_date' => $date,
            'requested_on' => BusinessDate::today(),
            'approval_threshold' => Money::fromCents((int) $threshold),
            // AG-052: a receipt starts only after approval (R5).
            'receipt_status' => null,
        ]);
        $txn->ref_no = $this->refNo($txn);
        $txn->save();
        AuditLog::record('transfer.approval_requested', $txn, null, [
            'ref_no' => $txn->ref_no, 'type' => $type, 'amount' => Money::add($amount), 'requested_date' => $date,
            'requested_on' => BusinessDate::today(), 'from_account_id' => $from->id, 'to_account_id' => $to->id,
            'threshold' => Money::fromCents((int) $threshold), 'other_pending_from_account' => Money::fromCents($other), 'status' => 'pending',
        ]);
        $id = $txn->id;
        $userId = auth()->id();
        DB::afterCommit(fn () => TransferApprovalRequested::dispatch($id, $userId));

        // QA nit: a request has no lines, so the response carries no `entries` key.
        $txn = $txn->fresh();
        $txn->requestWarning = TransferApprovals::overCommitWarning((string) $from->current_balance, $amount, $other);

        return $txn;
    }

    /**
     * AG-051 R5 / R6: post an approved request. Locks the row and the accounts, repeats every check of transfer() for
     * the approval date (BusinessDate::today(), never the requested date: accounting review MUST 1) against the current
     * balances, writes the lines dated that day and moves the row to posted with one conditional update on
     * approval_status = pending (R10). The ref_no is kept. Audit: transfer.approved, then transaction.<type>.
     * The caller (TransferApprovals::approve) has checked who may approve (R3, R4, R9).
     */
    public function postApproved(Transaction $pending, ?string $comment = null, array $auditExtra = []): Transaction
    {
        return DB::transaction(function () use ($pending, $comment, $auditExtra) {
            $t = Transaction::withoutGlobalScopes()->lockForUpdate()->findOrFail($pending->id);
            $types = [...TransferApprovals::TYPES, ...ExpenseApprovals::TYPES];
            if ($t->status !== 'pending' || $t->approval_status !== TransferApprovals::PENDING || ! in_array($t->type, $types, true)) {
                throw new LedgerException(TransferApprovals::CHANGED);
            }
            if ($t->type === Transaction::EXPENSE) {
                return $this->postApprovedExpense($t, $comment, $auditExtra);
            }
            $date = BusinessDate::today();
            $from = $this->moneyAccount((int) $t->from_account_id);
            $to = $this->moneyAccount((int) $t->to_account_id);
            $amount = Money::add($t->amount);
            $spec = $this->rules->transfer($from->id, $from->branch_id, $to->id, $to->branch_id, Branch::main()->id, Money::toCents($amount));
            [$lines, $locked] = $this->prepare($spec, $date);
            $from = $locked[$from->id];
            $to = $locked[$to->id];
            foreach ([$from, $to] as $a) {
                if ($a->status !== 'active' || $a->branch->status !== 'active') {
                    throw new LedgerException('Account '.trim(($a->branch?->code ?? '').' '.$a->name).' or its branch is inactive.');
                }
            }
            if ($this->transferType($from, $to) !== $t->type) {
                throw new LedgerException('The accounts of this request no longer match its type. Reject it and request again.');
            }
            $this->assertOpenFor($lines, headOfficeOf: $t->type === Transaction::FUND_RETURN ? 'fund return' : 'transfer');
            $availableBefore = Money::add($from->current_balance);
            $this->assertFunds($from, $amount);

            $now = now();
            $receipt = app(TransferReceipts::class)->initialStatus($t->type);
            $done = Transaction::withoutGlobalScopes()->whereKey($t->id)->where('approval_status', TransferApprovals::PENDING)->update([
                'status' => 'posted', 'approval_status' => TransferApprovals::APPROVED, 'txn_date' => $date, 'approval_note' => $comment,
                'decided_by' => auth()->id(), 'decided_at' => $now, 'approved_by' => auth()->id(), 'approved_at' => $now,
                'receipt_status' => $receipt, 'updated_at' => $now,
            ]);
            if ($done !== 1) {
                throw new LedgerException(TransferApprovals::CHANGED);
            }
            $t->refresh();
            AuditLog::record('transfer.approved', $t, ['approval_status' => TransferApprovals::PENDING], [
                'approval_status' => TransferApprovals::APPROVED, 'posting_date' => $date, 'requested_date' => $t->requested_date?->format('Y-m-d'),
                'comment' => $comment, 'all_branches' => ! app(BranchContext::class)->isScoped(), 'available_before' => $availableBefore,
                // Review m1: the branch the approver worked in ('MAIN' for a Main approver, any branch for a Super Admin).
                'approver_branch' => ($ctx = app(BranchContext::class))->isScoped() ? Branch::whereKey($ctx->branchId)->value('code') : 'all',
                'ref_no' => $t->ref_no,
            ]);
            $this->post($t, $lines);
            if ($receipt === TransferReceipts::AWAITING) {
                $id = $t->id;
                $userId = auth()->id();
                DB::afterCommit(fn () => TransferReceiptRequested::dispatch($id, $userId));
            }

            return $this->finish($t, keepRef: true);
        }, self::ATTEMPTS);
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
            $this->assertEntryCategory($type, $cat, $acc);
            [$lines, $locked] = $this->prepare($this->rules->incomeExpense($type, $acc->id, $acc->branch_id, $cat->id, Money::toCents($data['amount'])), $data['txn_date']);
            $acc = $locked[$acc->id];
            if ($acc->status !== 'active') {
                throw new LedgerException('Account is inactive.');
            }
            $this->assertOpenFor($lines);
            // R10: expense_negative_balance applies to the money account only.
            if ($type === Transaction::EXPENSE && app(Settings::class)->expenseNegativeBalance() === 'block') {
                $this->assertFunds($acc, $data['amount']);
            }

            // AG-064 R2 / R2b / R5: above the branch's limit a non-Super-Admin's expense waits for approval. Every check
            // above has run; the request posts nothing.
            $rule = null;
            if ($type === Transaction::EXPENSE) {
                $rule = app(ExpenseApprovals::class)->rule(auth()->user(), $acc, $cat->id, $data['txn_date'], Money::toCents($data['amount']));
                if ($rule['decision'] === ExpenseApprovals::HOLD) {
                    return $this->expenseRequest($acc, $cat, $data, $rule);
                }
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

            // AG-064 R2b compensating control: a Super Admin's expense above a set limit is flagged in the audit.
            return $this->finish($txn, ($rule['decision'] ?? null) === ExpenseApprovals::NOT_REQUIRED_SUPER_ADMIN ? ['approval' => $rule['decision']] : []);
        }, self::ATTEMPTS);
    }

    /** Categories spec R6 (the category and its parent active, of the entry's type) and the branch rule. */
    private function assertEntryCategory(string $type, Category $cat, Account $acc): void
    {
        // Categories spec R6: the category and its parent must be active (new money only, finding 4).
        if ($cat->type !== $type || ! $cat->isEffectivelyActive()) {
            throw new LedgerException("Category must be an active {$type} category.");
        }
        if ($cat->branch_id && $cat->branch_id !== $acc->branch_id) {
            throw new LedgerException('Category belongs to another branch.');
        }
    }

    /**
     * AG-064 R5: the pending expense request. A `transactions` row with status pending and no ledger lines, every entry
     * field kept, the ref_no of the requested date's year. The account is locked by the caller, so the pending total read
     * here (R17 warning) is consistent with the balance.
     *
     * @param  array{decision: ?string, reason: ?string, threshold: ?int, same_day: int}  $rule
     */
    private function expenseRequest(Account $acc, Category $cat, array $data, array $rule): Transaction
    {
        $approvals = app(ExpenseApprovals::class);
        $other = $approvals->pendingOnAccount($acc->id, current: true);
        $superAdminOnly = $approvals->isBranchUserManager((int) auth()->id(), (int) $acc->branch_id);
        $txn = $this->newTxn(Transaction::EXPENSE, $acc->branch_id, $data['txn_date'], $data['amount'], [
            'account_id' => $acc->id,
            'category_id' => $cat->id,
            'payee_payer' => $data['payee_payer'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'pending',
            'approval_status' => ExpenseApprovals::PENDING,
            'requested_date' => $data['txn_date'],
            'requested_on' => BusinessDate::today(),
            'approval_threshold' => Money::fromCents((int) $rule['threshold']),
        ]);
        $txn->ref_no = $this->refNo($txn);
        $txn->save();
        AuditLog::record('expense.approval_requested', $txn, null, [
            'ref_no' => $txn->ref_no, 'amount' => Money::add($data['amount']), 'requested_date' => $data['txn_date'],
            'requested_on' => BusinessDate::today(), 'account_id' => $acc->id, 'category_id' => $cat->id,
            'threshold' => Money::fromCents((int) $rule['threshold']), 'hold_reason' => $rule['reason'],
            'same_day_total' => Money::fromCents($rule['same_day']), 'super_admin_only' => $superAdminOnly,
            'other_pending_from_account' => Money::fromCents($other), 'files_count' => 0, 'status' => 'pending',
        ]);
        $id = $txn->id;
        $userId = auth()->id();
        DB::afterCommit(fn () => ExpenseApprovalRequested::dispatch($id, $userId));

        $txn = $txn->fresh();
        $txn->requestWarning = ExpenseApprovals::overCommitWarning((string) $acc->current_balance, (string) $data['amount'], $other);
        $txn->holdReason = $rule['reason'];
        $txn->sameDayTotal = $rule['reason'] === ExpenseApprovals::SAME_DAY_TOTAL ? Money::fromCents($rule['same_day']) : null;

        return $txn;
    }

    /**
     * AG-064 R5 / R6: post an approved expense request on the approval date against the current balance and the current
     * category, account and branch state, with every check of incomeOrExpense repeated. Lines Dr the category's expense
     * account, Cr the money account; the ref_no is kept. Audit: expense.approved, then transaction.expense. The row is
     * already locked by postApproved; the caller (ExpenseApprovals::approve) has checked who may approve (R3, R4, R4a, R9).
     */
    private function postApprovedExpense(Transaction $t, ?string $comment, array $auditExtra): Transaction
    {
        $date = BusinessDate::today();
        $acc = $this->moneyAccount((int) $t->account_id);
        $cat = Category::find($t->category_id);
        if (! $cat) {
            throw new LedgerException('Category must be an active expense category.');
        }
        $this->assertEntryCategory(Transaction::EXPENSE, $cat, $acc);
        $amount = Money::add($t->amount);
        [$lines, $locked] = $this->prepare($this->rules->incomeExpense(Transaction::EXPENSE, $acc->id, $acc->branch_id, $cat->id, Money::toCents($amount)), $date);
        $acc = $locked[$acc->id];
        // R15: an account or branch deactivated while the request waited.
        if ($acc->status !== 'active') {
            throw new LedgerException('Account is inactive.');
        }
        if ($acc->branch->status !== 'active') {
            throw new LedgerException("Branch {$acc->branch->code} is inactive.");
        }
        $this->assertOpenFor($lines);
        $availableBefore = Money::add($acc->current_balance);
        if (app(Settings::class)->expenseNegativeBalance() === 'block') {
            $this->assertFunds($acc, $amount);
        }

        $now = now();
        $done = Transaction::withoutGlobalScopes()->whereKey($t->id)->where('approval_status', ExpenseApprovals::PENDING)->update([
            'status' => 'posted', 'approval_status' => ExpenseApprovals::APPROVED, 'txn_date' => $date, 'approval_note' => $comment,
            'decided_by' => auth()->id(), 'decided_at' => $now, 'approved_by' => auth()->id(), 'approved_at' => $now, 'updated_at' => $now,
        ]);
        if ($done !== 1) {
            throw new LedgerException(ExpenseApprovals::CHANGED);
        }
        $t->refresh();
        $ctx = app(BranchContext::class);
        AuditLog::record('expense.approved', $t, ['approval_status' => ExpenseApprovals::PENDING], [
            'approval_status' => ExpenseApprovals::APPROVED, 'posting_date' => $date, 'requested_date' => $t->requested_date?->format('Y-m-d'),
            'comment' => $comment, 'all_branches' => ! $ctx->isScoped(),
            'approver_branch' => $ctx->isScoped() ? Branch::whereKey($ctx->branchId)->value('code') : 'all',
            'available_before' => $availableBefore, 'ref_no' => $t->ref_no,
        ] + $auditExtra);
        $this->post($t, $lines);

        return $this->finish($t, keepRef: true);
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
            if ($txn->type === Transaction::YEAR_END_CLOSE) {
                throw new LedgerException(self::YEAR_END_LOCKED);
            }
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
            // Categories spec R6: the TARGET must be effectively active; the source may be inactive (finding 4).
            if (! $new || $new->type !== $txn->type || ! $new->isEffectivelyActive() || ($new->branch_id && $new->branch_id !== $txn->branch_id)) {
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
            ] + ($txn->approval_status === ExpenseApprovals::APPROVED ? ['after_approval' => true] : []));

            return $ids;
        }, self::ATTEMPTS);
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
            if ($original->type === Transaction::YEAR_END_CLOSE) {
                throw new LedgerException(self::YEAR_END_LOCKED);
            }
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

            $this->assertOpenFor($lines, headOfficeOf: match ($original->type) {
                Transaction::BRANCH_TRANSFER => 'transfer',
                Transaction::FUND_RETURN => 'fund return',
                default => null,
            });
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
            $txn = $this->finish($txn);
            // AG-052 R7: an open receipt is cancelled in the same DB transaction (the reversal row has none, Q3).
            app(TransferReceipts::class)->cancelForReversal($original, $txn);

            return $txn;
        }, self::ATTEMPTS);
    }

    /**
     * Void a posted income/expense entry (controls spec R1, double-entry finding 3): the original lines are kept and one
     * contra (debit and credit swapped, same account, branch and date, contra_of_id = original) is posted for EVERY
     * line that is not itself a contra. The transaction then has status `void` and nets to zero on every account.
     */
    public function void(Transaction $txn, ?string $reason = null): Transaction
    {
        if ($txn->type === Transaction::YEAR_END_CLOSE) {
            throw new LedgerException(self::YEAR_END_LOCKED);
        }
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
            // AG-033 R5: a line ticked on a bank reconciliation is never voided (locking read after the account locks, so a
            // tick and a void cannot race: the marks endpoint locks the same account row first).
            if ($refusal = BankReconciliation::voidRefusal($originals->pluck('id')->all(), $txn->ref_no ?? "#{$txn->id}")) {
                throw new LedgerException($refusal);
            }
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
        }, self::ATTEMPTS);
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

    /** Drop the back-fill account memo (after a rolled-back batch: an account created in it no longer exists). */
    public function forgetBackfillMemo(): void
    {
        $this->backfillRefs = [];
    }

    // ---------------------------------------------------------------- year-end close (settings spec R7-R11)

    /**
     * Close fiscal year $startYear for all branches (R8): checks 1-6 under the branch row locks, one closing transaction
     * per branch with income or expense balances (R7), the close's own global lock for month(E) (review finding 1), the
     * `year_end_closes` row (closed_at after the lines, finding 7), audit and the Super Admin notification (finding 5).
     *
     * @return array{close: YearEndClose, transactions: Transaction[]}
     */
    public function closeYear(int $startYear, string $confirm): array
    {
        $fy = $this->yearEnd->year($startYear);

        try {
            $out = DB::transaction(function () use ($fy, $confirm) {
                $this->lockBranches();
                // Review S1: take the active period locks FOR UPDATE before the plan. A posting holds a shared lock on
                // the lock rows it checked until it commits, so an in-flight posting finishes before the plan is read.
                PeriodLock::active()->orderBy('id')->lockForUpdate()->get(['id']);
                $plan = $this->yearEnd->plan($fy);
                if ($checks = $this->yearEnd->checks($fy, $plan)) {
                    throw new LedgerException($checks[0]['message']);
                }
                if (trim($confirm) !== $fy->shortLabel()) {
                    throw new LedgerException("Type {$fy->shortLabel()} to confirm.");
                }

                $txns = [];
                $byBranch = [];
                foreach ($plan['branches'] as $p) {
                    $b = $p['branch'];
                    $entry = ['income' => Money::fromCents($p['income']), 'expense' => Money::fromCents($p['expense']), 'net' => Money::fromCents($p['net'])];
                    if (! $p['spec']) {
                        $byBranch[$b->code] = $entry + ['transaction_id' => null, 'ref_no' => null, 'lines' => 0];

                        continue;
                    }
                    [$lines] = $this->prepare($p['spec'], $fy->end(), 'close');
                    $debits = array_sum(array_map(fn ($l) => (int) $l['account']->branch_id === $b->id ? $l['debit'] : 0, $lines));
                    $txn = $this->newTxn(Transaction::YEAR_END_CLOSE, $b->id, $fy->end(), Money::fromCents($debits), [
                        'description' => "Year-end close {$fy->label()}",
                    ]);
                    $this->postClose($txn, $lines, $fy);
                    $txn = $this->finish($txn);
                    $txns[] = $txn;
                    $byBranch[$b->code] = $entry + ['transaction_id' => $txn->id, 'ref_no' => $txn->ref_no, 'lines' => count($lines)];
                }

                // Finding 1: an active global lock for exactly month(E), kept on the close.
                $lock = PeriodLock::active()->whereNull('branch_id')->where('period', $fy->endPeriod())->lockForUpdate()->first();
                $created = false;
                if (! $lock) {
                    $lock = PeriodLock::create(['branch_id' => null, 'period' => $fy->endPeriod(), 'locked_by' => auth()->id()]);
                    AuditLog::record('period.locked', $lock, null, ['period' => $fy->endPeriod(), 'branch_id' => null, 'source' => 'year_end_close']);
                    $created = true;
                }

                $total = Money::fromCents($plan['total_net']);
                // Finding 7: closed_at is taken after the closing lines are written.
                $close = YearEndClose::create([
                    'fiscal_year' => $fy->startYear, 'period_start' => $fy->start(), 'period_end' => $fy->end(), 'status' => YearEndClose::CLOSED,
                    'closed_by' => auth()->id(), 'closed_at' => now(), 'period_lock_id' => $lock->id,
                    'summary' => ['by_branch' => $byBranch, 'total_net' => $total],
                ]);
                AuditLog::record('year.closed', $close, null, ['fiscal_year' => $fy->startYear, 'period_start' => $fy->start(), 'period_end' => $fy->end(),
                    'by_branch' => $byBranch, 'total_net' => $total, 'global_lock_created' => $created]);
                $this->notifyYearEnd('closed', $fy, $total);

                return ['close' => $close, 'transactions' => $txns];
            }, self::ATTEMPTS);
        } catch (QueryException $e) {
            // Two simultaneous closes: the unique active_key refuses the second row (R8 check 2). Only that index
            // (review S2): any other integrity error is rethrown as it is.
            if (YearEnd::isActiveKeyViolation($e)) {
                throw new LedgerException("{$fy->label()} is already closed.");
            }
            throw $e;
        }

        return $out;
    }

    /**
     * Reopen the latest closed year (R11): one contra per close line through postClose (dated E, sides swapped,
     * contra_of_id set), the closing transactions become void, the row becomes `reopened`. No lock changes.
     */
    public function reopenYear(int $startYear, string $reason): YearEndClose
    {
        $fy = $this->yearEnd->year($startYear);

        return DB::transaction(function () use ($fy, $reason) {
            $this->lockBranches();
            // Finding 9: read FOR UPDATE and re-check inside the transaction; the loser of two reopens gets "not closed".
            $close = $this->yearEnd->activeClose($fy->startYear, lock: true);
            if (! $close) {
                throw new LedgerException("{$fy->label()} is not closed.");
            }
            $later = YearEndClose::active()->where('fiscal_year', '>', $fy->startYear)->orderByDesc('fiscal_year')->first();
            if ($later) {
                throw new LedgerException("Reopen {$this->yearEnd->year((int) $later->fiscal_year)->label()} first.");
            }

            $allContras = [];
            $ids = $close->transactionIds();
            foreach ($ids as $id) {
                $txn = Transaction::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);
                $originals = $txn->entries()->whereNull('contra_of_id')->orderBy('id')->get();
                $accounts = $this->lock($originals->pluck('account_id')->unique()->all());
                $lines = $originals->map(fn (LedgerEntry $e) => $this->line($accounts[$e->account_id], Money::toCents($e->credit), Money::toCents($e->debit),
                    $e->entry_date->format('Y-m-d'), $e->id, 'close'))->all();
                $contras = array_map(fn (LedgerEntry $e) => $e->id, $this->postClose($txn, $lines, $fy));
                $txn->update(['status' => 'void']);
                // Finding 6: the branch's auditors see why its closing entry became void.
                AuditLog::record('transaction.year_end_reopened', $txn, ['status' => 'posted'],
                    ['status' => 'void', 'reason' => $reason, 'contra_line_ids' => $contras, 'ref_no' => $txn->ref_no], $txn->branch_id);
                $allContras = array_merge($allContras, $contras);
            }

            $old = ['closed_by' => $close->closed_by, 'closed_at' => $close->closed_at?->toIso8601String(), 'total_net' => $close->totalNet()];
            $close->update(['status' => YearEndClose::REOPENED, 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => $reason]);
            AuditLog::record('year.reopened', $close, $old, ['reason' => $reason, 'contra_line_ids' => $allContras, 'transaction_ids' => $ids]);
            $this->notifyYearEnd('reopened', $fy, $close->totalNet(), $reason);

            return $close->fresh();
        }, self::ATTEMPTS);
    }

    /**
     * The private close path (R8 "Lock exemption"): skips assertOpen, reached only from closeYear / reopenYear, no flag.
     * Only `origin = close` lines dated E of the year being closed or reopened, only on income, expense, head office,
     * branch current and retained earnings accounts (never a money account), and never below a later active close.
     *
     * @return LedgerEntry[]
     */
    private function postClose(Transaction $txn, array $lines, FiscalYear $fy): array
    {
        if ($txn->type !== Transaction::YEAR_END_CLOSE) {
            throw new LedgerException('Only a year-end close transaction can take closing lines.');
        }
        $through = YearEnd::closedThrough(share: true);
        foreach ($lines as $l) {
            $acc = $l['account'];
            $okAccount = ! $acc->isMoney() && in_array($acc->type, Account::CLOSE_TYPES, true)
                && ($acc->type !== 'category' || in_array($acc->class, ['income', 'expense'], true));
            if ($l['origin'] !== 'close' || ! $okAccount || $l['date'] !== $fy->end() || ($through !== null && $through > $fy->end())) {
                throw new LedgerException("Closing line on account #{$acc->id} refused: not allowed for the year-end close of {$fy->label()}.");
            }
        }

        return $this->post($txn, $lines);
    }

    /** R8: every branch row, in id order (as PeriodLockController), so a close and a lock change run one after the other. */
    private function lockBranches(): void
    {
        Branch::lockAll();
    }

    private function notifyYearEnd(string $event, FiscalYear $fy, string $total, ?string $reason = null): void
    {
        $admins = User::where('is_super_admin', true)->where('status', 'active')->get();
        $actor = auth()->user();
        if ($admins->isEmpty()) {
            return;
        }
        try {
            Notification::send($admins, new YearEndNotification($event, $fy->label(), $actor ? "{$actor->name} <{$actor->email}>" : 'System',
                BusinessDate::now()->format('Y-m-d H:i'), Money::format($total), $reason));
        } catch (\Throwable $e) {
            report($e);
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
            // Settings review finding 4: the lock exemption of this path never reaches a closed fiscal year.
            if ($closed = $this->yearEnd->closedYearOf($p['date'], share: true)) {
                throw new LedgerException('Back-fill of '.($txn->ref_no ?? "#{$txn->id}")." refused: {$closed->label()} is closed.");
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
     * $headOfficeOf: MAIN is only the head-office side of this kind of movement ("transfer", "fund return"), and the
     * lock message says so. Null: MAIN gets the plain message. The closed-year check runs first for every branch.
     */
    private function assertOpenFor(array $lines, ?string $headOfficeOf = null): void
    {
        $checks = [];
        foreach ($lines as $l) {
            $checks[$l['account']->branch_id.'|'.$l['date']] = [$l['account']->branch, $l['date']];
        }
        uasort($checks, fn ($a, $b) => [$a[0]->is_main, $a[1]] <=> [$b[0]->is_main, $b[1]]);
        foreach ($checks as [$branch, $date]) {
            $this->assertYearOpen($date);
            if ($headOfficeOf !== null && $branch->is_main) {
                if (PeriodLock::isLocked($branch->id, $date, share: true)) {
                    throw new LedgerException('The period '.substr($date, 0, 7)." is locked for {$branch->code} (head office side of this {$headOfficeOf}).");
                }

                continue;
            }
            $this->assertOpen($branch->id, $date);
        }
    }

    /** Settings spec R8 "While a year is closed": no posting dated <= E of the latest active close (backs up the locks). */
    private function assertYearOpen(string $date): void
    {
        if ($closed = $this->yearEnd->closedYearOf($date, share: true)) {
            throw new LedgerException("{$closed->label()} is closed.");
        }
    }

    private function assertOpen(int $branchId, string $date): void
    {
        $this->assertYearOpen($date);
        // Locking read: a concurrent period lock for this branch/month waits for this posting (reviewer R5).
        if (PeriodLock::isLocked($branchId, $date, share: true)) {
            throw new LedgerException('The period '.substr($date, 0, 7).' is locked.');
        }
    }

    private function assertFunds(Account $acc, string $amount): void
    {
        if (Money::cmp($acc->current_balance, $amount) < 0) {
            // AG-051 QA B4: every branch's cash account is "Cash", so the branch is named; the amount is formatted.
            $code = $acc->branch?->code;
            throw new LedgerException('Insufficient balance in '.trim("{$code} {$acc->name}").' (available '.Money::format($acc->current_balance).').');
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
        // Settings review finding 3: Retained earnings is written only by the year-end close.
        if ($acc->type === Account::RETAINED_EARNINGS && $l['origin'] !== 'close') {
            throw new LedgerException('Retained earnings can only be posted by the year-end close.');
        }
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

    private function refNo(Transaction $txn): string
    {
        $prefix = ['capital' => 'CAP', 'opening_allocation' => 'OPN', 'fund_allocation' => 'ALC', 'fund_return' => 'RET',
            'branch_transfer' => 'TRF', 'internal_transfer' => 'INT', 'income' => 'INC', 'expense' => 'EXP', 'reversal' => 'REV', 'year_end_close' => 'YEC'][$txn->type];

        return sprintf('%s-%s-%s-%06d', $txn->branch->code, $prefix, substr((string) $txn->txn_date->format('Y'), 0, 4), $txn->id);
    }

    /**
     * The creation audit row (and the ref_no, unless $keepRef: an approved request keeps the ref_no of its request,
     * AG-051 R5). $auditExtra adds keys to the audit row (AG-051 finding 6).
     */
    private function finish(Transaction $txn, array $auditExtra = [], bool $keepRef = false): Transaction
    {
        if (! $keepRef) {
            $txn->ref_no = $this->refNo($txn);
            $txn->save();
        }
        // AG-052 R12: the creation row of a between-branch transfer carries its receipt status.
        $keys = ['ref_no', 'amount', 'txn_date', 'from_account_id', 'to_account_id', 'account_id', 'category_id'];
        if (in_array($txn->type, TransferReceipts::TYPES, true)) {
            $keys[] = 'receipt_status';
        }
        $values = $txn->only($keys);
        // A re-read row (an approved request, AG-051) holds a Carbon date: the audit keeps Y-m-d, as for a new row.
        if ($values['txn_date'] instanceof \DateTimeInterface) {
            $values['txn_date'] = $values['txn_date']->format('Y-m-d');
        }
        AuditLog::record("transaction.{$txn->type}", $txn, null, $values + $auditExtra);

        return $txn->load('entries');
    }
}
