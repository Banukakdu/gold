<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BackgroundExports;
use App\Services\ExpenseApprovals;
use App\Services\LedgerService;
use App\Services\TransactionExporter;
use App\Services\TransactionSearch;
use App\Services\TransferApprovals;
use App\Services\TransferReceipts;
use App\Services\YearEnd;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    /** Eager loads of the list and the export (R13: no N+1). */
    public const LIST_RELATIONS = ['branch:id,code,name', 'category:id,name,parent_id,is_active', 'category.parent:id,name,is_active',
        'account:id,name,branch_id', 'fromAccount:id,name,branch_id', 'fromAccount.branch:id,code', 'toAccount:id,name,branch_id',
        'toAccount.branch:id,code', 'creator:id,name,status,is_super_admin', 'reversalOf:id,ref_no', 'reversedBy:id,ref_no,reversal_of_id',
        // AG-052: serialises as receipt_by: {id, name}.
        'receiptBy:id,name',
        // AG-051: serialises as decided_by: {id, name}.
        'decidedBy:id,name'];

    public function __construct(private LedgerService $ledger, private TransactionSearch $search) {}

    /** AG-065 (docs/specs/transaction-search.md): filterable, sortable, paginated list over the scope set (R10). */
    public function index(Request $request)
    {
        $p = $this->search->params($request->all());
        $page = $this->search->query($p)
            ->with(self::LIST_RELATIONS)
            ->withCount(self::attachmentCounts())->withCount('entries')
            ->paginate($p->perPage, ['*'], 'page', $p->page);
        $rows = $page->getCollection();
        $this->tagAuthors($rows);
        $this->markLegacyVoids($rows);
        foreach ($rows as $t) {
            // R10 / accounting review finding 5: incoming rows are read-only (actions follow transactions.branch_id).
            $t->setAttribute('in_active_branch', $p->inActiveBranch($t));
            $t->setAttribute('direction', $p->isIncoming($t) ? 'in' : null);
            $t->setAttribute('reversal_of_ref', $t->reversalOf?->ref_no);
            $t->setAttribute('reversed_by_ref', $t->reversedBy?->ref_no);
        }

        return $page;
    }

    /** AG-065 R14: the filtered list as xlsx (transaction.view + report.export), same filters, scope and sort. */
    public function export(Request $request, TransactionExporter $exporter)
    {
        $params = $this->search->params($request->all(), export: true);

        // AG-087 R1: more than exports.sync_rows rows is queued (202).
        return $exporter->download($params, $request->user(), fn (int $rows) => app(BackgroundExports::class)->queueTransactions($request, $params, $rows));
    }

    /**
     * Double-entry R10 / finding 10: resolved without the branch scope, then allowed when the active branch is the
     * entry's branch or the branch of any of its lines (All mode: any). Otherwise 404, as before.
     */
    public function show(int $transactionId)
    {
        $ctx = app(BranchContext::class);
        $transaction = Transaction::withoutGlobalScopes()->find($transactionId);
        $approvals = app(TransferApprovals::class);
        // AG-051 R13: a request has no lines yet; the receiving branch and Main's approvers see it too.
        $allowed = $transaction && (! $ctx->isScoped() || (int) $transaction->branch_id === $ctx->branchId
            || $transaction->entries()->where('branch_id', $ctx->branchId)->exists()
            || ($transaction->approval_status !== null && in_array($transaction->status, ['pending', 'rejected'], true)
                && in_array($transaction->type, TransferApprovals::TYPES, true)
                && ((int) $transaction->related_branch_id === $ctx->branchId || $approvals->isApprover(request()->user(), $ctx))));
        abort_unless($allowed, 404);

        $transaction->load(['entries' => fn ($q) => $q->orderBy('id'), 'entries.account:id,code,name,class,type', 'entries.branch:id,code',
            'branch', 'category.parent', 'account', 'fromAccount.branch', 'toAccount.branch', 'creator:id,name,status,is_super_admin',
            'receiptBy:id,name,is_super_admin', 'decidedBy:id,name']);
        $dr = $cr = 0;
        foreach ($transaction->entries as $e) {
            $dr += Money::toCents($e->debit);
            $cr += Money::toCents($e->credit);
        }
        $transaction->setAttribute('balanced', $transaction->entries->isNotEmpty() && $dr === $cr && $this->balancedPerBranch($transaction));
        $transaction->setAttribute('totals', ['debit' => Money::fromCents($dr), 'credit' => Money::fromCents($cr)]);
        // Edit, void, reverse and files follow transactions.branch_id (R10): a head-office reader only reads.
        $transaction->setAttribute('in_active_branch', ! $ctx->isScoped() || (int) $transaction->branch_id === $ctx->branchId);
        $transaction->setAttribute('backfilled_at', $transaction->entries->firstWhere('origin', 'backfill')?->created_at?->setTimezone('Asia/Colombo')->format('Y-m-d'));
        $transaction->setAttribute('entries_count', $transaction->entries->count());
        // AG-052 S3: the Receipt panel's buttons and whether its confirm dialog needs a note.
        $receipts = app(TransferReceipts::class);
        $transaction->setAttribute('can_acknowledge', $receipts->canAcknowledge($transaction, request()->user()));
        $transaction->setAttribute('receipt_note_required', $receipts->noteRequired($transaction));
        $transaction->setAttribute('receipt_discrepancy', $receipts->lastDiscrepancy($transaction));
        // AG-051 / AG-064 S5: the Approval panel's buttons, by the kind of request.
        $user = request()->user();
        $expenses = app(ExpenseApprovals::class);
        $isExpense = $transaction->type === Transaction::EXPENSE;
        $transaction->setAttribute('can_approve', $isExpense ? $expenses->canApprove($transaction, $user) : $approvals->canApprove($transaction, $user));
        $transaction->setAttribute('can_cancel', $isExpense ? $expenses->canCancel($transaction, $user) : $approvals->canCancel($transaction, $user));
        if ($isExpense && $transaction->approval_status !== null) {
            // AG-064 S3 / S5: what the approve dialog needs (R4a, R4b, MUST 4, SHOULD 7) and R21 "Changed after approval".
            $pending = $transaction->approval_status === ExpenseApprovals::PENDING;
            $superOnly = $pending && $expenses->superAdminOnly($transaction);
            $transaction->setAttribute('super_admin_only', $superOnly);
            $transaction->setAttribute('approver_onboarded_requester', $pending && $transaction->getAttribute('can_approve')
                && $expenses->onboardedRequester($transaction, (int) $user->id));
            $transaction->setAttribute('requested_period_locked', $pending && $expenses->requestedPeriodLocked($transaction));
            $transaction->setAttribute('changed_after_approval', AuditLog::where('auditable_type', Transaction::class)
                ->where('auditable_id', $transaction->id)->whereIn('event', ['transaction.updated', 'transaction.reclassified'])
                ->where('new_values->after_approval', true)->exists());
        }
        // QA B2: the approve dialog says "Requester is inactive" here too (S5 "buttons as S3").
        $transaction->setAttribute('requester_inactive', $transaction->approval_status !== null && $transaction->creator !== null
            && $transaction->creator->status !== 'active');
        $transaction->setAttribute('expires_on', $transaction->approval_status === TransferApprovals::PENDING
            ? TransferApprovals::expiresOn($transaction->requested_on?->format('Y-m-d')) : null);
        $transaction->loadCount(self::attachmentCounts());
        $this->tagAuthors(collect([$transaction]));
        $this->markLegacyVoids(collect([$transaction]));

        return $transaction;
    }

    private function balancedPerBranch(Transaction $t): bool
    {
        $net = [];
        foreach ($t->entries as $e) {
            $net[$e->branch_id] = ($net[$e->branch_id] ?? 0) + Money::toCents($e->debit) - Money::toCents($e->credit);
        }

        return ! array_filter($net);
    }

    /** AG-062: active attachments (R9) and removed ones (finding 6). */
    private static function attachmentCounts(): array
    {
        return [
            'attachments as attachments_count' => fn ($q) => $q->whereNull('removed_at'),
            'attachments as attachments_removed_count' => fn ($q) => $q->whereNotNull('removed_at'),
        ];
    }

    /**
     * Options for the "Created by" filter: everyone who created an entry in the scope set (R7, R10, branches[] included),
     * former members included (finding 3).
     */
    public function creators(Request $request)
    {
        $p = $this->search->params($request->only('branches'));
        $rows = $this->search->creatorRows($p);
        $ids = $rows->pluck('created_by')->unique()->values();
        $members = $this->memberships($ids->all());
        $users = User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'status', 'is_super_admin']);

        return $users->map(function (User $u) use ($rows, $members) {
            $branches = $rows->where('created_by', $u->id)->pluck('branch_id');
            $tag = $u->status !== 'active' ? 'inactive'
                : (! $u->is_super_admin && $branches->contains(fn ($b) => ! isset($members[$u->id.':'.$b])) ? 'no longer in this branch' : null);

            return ['id' => $u->id, 'name' => $u->name, 'tag' => $tag];
        })->values();
    }

    public function storeIncome(Request $request)
    {
        return response()->json($this->ledger->incomeOrExpense(Transaction::INCOME, $this->validated($request)), 201);
    }

    public function storeExpense(Request $request)
    {
        $txn = $this->ledger->incomeOrExpense(Transaction::EXPENSE, $this->validated($request));
        // AG-064 section 5: status pending | posted decides the toast; R2b hold reason; R17 warning (never a block).
        $txn->setAttribute('hold_reason', $txn->holdReason);
        $txn->setAttribute('same_day_total', $txn->sameDayTotal);
        $txn->setAttribute('warning', $txn->requestWarning);

        return response()->json($txn, 201);
    }

    /** AG-063: edit descriptive fields (amount/account changes = void and re-enter, keeps the ledger simple and auditable). */
    public function update(Request $request, Transaction $transaction)
    {
        abort_if($transaction->type === Transaction::YEAR_END_CLOSE, 422, LedgerService::YEAR_END_LOCKED);
        abort_unless(in_array($transaction->type, [Transaction::INCOME, Transaction::EXPENSE]) && $transaction->status === 'posted', 422, 'This entry cannot be edited.');
        $date = $transaction->txn_date->format('Y-m-d');
        if ($refused = app(YearEnd::class)->closedOrLockedMessage($transaction->branch_id, $date)) {
            abort(422, $refused);
        }
        $request->user()->canDo("{$transaction->type}.edit") || abort(403, "Missing permission: {$transaction->type}.edit");

        $data = $request->validate([
            'payee_payer' => 'nullable|string|max:255', 'payment_method' => 'nullable|string|max:30',
            'reference' => 'nullable|string|max:255', 'description' => 'nullable|string|max:2000',
            // Controls R5: an active category of the same type, global or of the entry's branch; categories spec R6: its
            // parent must be active too.
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')
                ->where('type', $transaction->type)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $transaction->branch_id))
                ->where(fn ($q) => $q->whereNull('parent_id')->orWhereIn('parent_id', fn ($s) => $s->select('id')->from('categories')->where('is_active', true)))],
        ], ['category_id.exists' => "Choose an active {$transaction->type} category of this branch."]);
        DB::transaction(function () use ($transaction, $data, $date) {
            // Retried on a deadlock (review R3b): each attempt starts from the stored row.
            $transaction->refresh();
            // Re-check with a locking read inside the write (reviewer R5), like LedgerService::assertOpen.
            if ($refused = app(YearEnd::class)->closedOrLockedMessage($transaction->branch_id, $date, share: true)) {
                abort(422, $refused);
            }
            $fields = Arr::except($data, ['category_id']);
            if ($fields) {
                $old = $transaction->only(array_keys($fields));
                $transaction->update($fields);
                // AG-064 R21 (SHOULD 9): a change to an approved expense is flagged for the Audit log `approval` filter.
                AuditLog::record('transaction.updated', $transaction, $old,
                    $fields + ($transaction->approval_status === ExpenseApprovals::APPROVED ? ['after_approval' => true] : []));
            }
            // Double-entry R3 / finding 4: a category change is a reclassification posted by LedgerService
            // (audit transaction.reclassified); the same category posts nothing.
            if (array_key_exists('category_id', $data)) {
                $this->ledger->reclassify($transaction, (int) $data['category_id']);
            }
        }, LedgerService::ATTEMPTS);

        return $transaction->fresh();
    }

    public function void(Request $request, Transaction $transaction)
    {
        if ($transaction->type === Transaction::YEAR_END_CLOSE) {
            return response()->json(['message' => LedgerService::YEAR_END_LOCKED], 422);
        }
        // Type first: transfers have no *.void permission, and the answer for them is "use reverse" (AC 5).
        if (! in_array($transaction->type, [Transaction::INCOME, Transaction::EXPENSE], true)) {
            return response()->json(['message' => 'Only income and expense entries can be voided. Use reverse for transfers.'], 422);
        }
        $request->user()->canDo("{$transaction->type}.void") || abort(403, "Missing permission: {$transaction->type}.void");

        return $this->ledger->void($transaction, $request->validate(['reason' => 'required|string|max:500'])['reason']);
    }

    /** Finding 3 / AC 42: authors stay visible, tagged "(inactive)" or "(no longer in this branch)". */
    private function tagAuthors($txns): void
    {
        $members = $this->memberships($txns->pluck('created_by')->unique()->all());
        foreach ($txns as $t) {
            $u = $t->creator;
            $tag = null;
            if ($u && $u->status !== 'active') {
                $tag = 'inactive';
            } elseif ($u && ! $u->is_super_admin && ! isset($members[$u->id.':'.$t->branch_id])) {
                $tag = 'no longer in this branch';
            }
            $t->setAttribute('creator_tag', $tag);
        }
    }

    /** Controls S4 / R6: a void with no ledger lines was voided before contra entries existed. */
    private function markLegacyVoids($txns): void
    {
        $legacy = $txns->filter(fn ($t) => $t->status === 'void' && (int) $t->entries_count === 0);
        if ($legacy->isEmpty()) {
            return;
        }
        $since = AuditLog::where('event', 'ledger.legacy_voids_identified')->oldest('id')->value('created_at');
        $note = 'Voided before '.($since ? substr((string) $since, 0, 10) : 'the ledger change').'; ledger lines were not kept.';
        $legacy->each(fn ($t) => $t->setAttribute('legacy_void_note', $note));
    }

    /** @return array<string, true> keyed "user_id:branch_id" */
    private function memberships(array $userIds): array
    {
        return DB::table('branch_user')->whereIn('user_id', $userIds)->get(['user_id', 'branch_id'])
            ->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->branch_id => true])->all();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'account_id' => 'required|integer',
            'category_id' => 'required|exists:categories,id',
            'txn_date' => 'required|date_format:Y-m-d|before_or_equal:'.BusinessDate::today(),
            'amount' => 'required|numeric|gt:0|decimal:0,2',
            'payee_payer' => 'nullable|string|max:255',
            'payment_method' => 'nullable|in:cash,bank_transfer,cheque,card,online',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);
        // account must belong to the active branch (branch scope -> 404 otherwise)
        Account::findOrFail($data['account_id']);
        $data['amount'] = (string) $data['amount'];

        return $data;
    }
}
