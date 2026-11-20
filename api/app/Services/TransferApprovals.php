<?php

namespace App\Services;

use App\Events\TransferApprovalDecided;
use App\Models\Branch;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Settings;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AG-051 transfer approvals (docs/specs/transfer-approvals.md). Branch transfers and fund returns above the threshold,
 * made by a non-Super-Admin, wait for Main's approval before they post.
 *
 * A control step only: a pending request is a `transactions` row with no ledger lines. This class writes the approval
 * columns and audit rows of reject, cancel and expiry; approval posts through LedgerService::postApproved (the only
 * writer of ledger lines and balances), which LedgerService::transfer also consults (rule()) when a request is made.
 */
class TransferApprovals extends ApprovalRequests
{
    /** R2: only these types are ever held. */
    public const TYPES = [Transaction::BRANCH_TRANSFER, Transaction::FUND_RETURN];

    public const BY_MAIN = 'Transfers are approved by Main.';

    public const OWN_REQUEST = 'You requested this transfer. Someone else in Main must approve it.';

    public function types(): array
    {
        return self::TYPES;
    }

    protected function auditPrefix(): string
    {
        return 'transfer';
    }

    protected function dispatchDecided(int $transactionId, string $state, ?int $userId): void
    {
        TransferApprovalDecided::dispatch($transactionId, $state, $userId);
    }

    // ------------------------------------------------------------------ R2 / R17 (called by LedgerService::transfer)

    /**
     * R2: HOLD when this transfer must wait for approval; NOT_REQUIRED_SUPER_ADMIN when only a Super Admin's being the
     * poster lets it through (accounting review finding 6); null otherwise. The setting at this moment decides.
     */
    public function rule(?User $user, string $type, int $cents): ?string
    {
        $threshold = app(Settings::class)->transferApprovalThresholdCents();
        if ($threshold === null || ! in_array($type, self::TYPES, true) || $cents <= $threshold) {
            return null;
        }

        return $user?->is_super_admin ? self::NOT_REQUIRED_SUPER_ADMIN : self::HOLD;
    }

    /**
     * R14: cents of the requests now pending from this account, except $exceptId. $current: a locking (current) read,
     * so a request made right after another one from the same account sees it under MySQL REPEATABLE READ (review m2).
     */
    public function pendingFromAccount(int $accountId, ?int $exceptId = null, bool $current = false): int
    {
        return (int) DB::table('transactions')->where('approval_status', self::PENDING)->whereIn('type', self::TYPES)->where('from_account_id', $accountId)
            ->when($exceptId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->when($current, fn ($q) => $q->sharedLock())
            ->selectRaw('COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c')->value('c');
    }

    /**
     * Review m8: R14 for every account of a page in one query. @return array<int, int> cents by from_account_id
     *
     * @param  int[]  $accountIds
     */
    private function pendingByAccount(array $accountIds): array
    {
        return DB::table('transactions')->where('approval_status', self::PENDING)->whereIn('type', self::TYPES)->whereIn('from_account_id', $accountIds ?: [0])
            ->groupBy('from_account_id')->selectRaw('from_account_id, COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c')
            ->pluck('c', 'from_account_id')->map(fn ($v) => (int) $v)->all();
    }

    /** R17: a warning, never a block. $otherCents = the other requests pending from the account before this one. */
    public static function overCommitWarning(string $balance, string $amount, int $otherCents): ?string
    {
        if ($otherCents === 0 || Money::toCents($amount) + $otherCents <= Money::toCents($balance)) {
            return null;
        }

        return 'Other requests from this account already wait for '.Money::format(Money::fromCents($otherCents)).'; together they exceed the balance.';
    }

    // ------------------------------------------------------------------ R3 / R4 / R9: approve, reject, cancel

    /** R3: Super Admin (any mode), or `transfer.approve` while the active branch is MAIN. */
    public function isApprover(User $user, ?BranchContext $ctx = null): bool
    {
        $ctx ??= app(BranchContext::class);
        if ($user->is_super_admin) {
            return true;
        }

        return $ctx->isScoped() && (int) $ctx->branchId === (int) Branch::main()->id && $user->canDo('transfer.approve');
    }

    /** POST /transfer-approvals/{id}/approve: R9 order, then LedgerService::postApproved (R5, R6). */
    public function approve(int $id, array $input, User $user): Transaction
    {
        return DB::transaction(function () use ($id, $input, $user) {
            $t = $this->lockedForDecision($id, $user);
            $comment = $this->comment($input, required: false);

            $t = app(LedgerService::class)->postApproved($t, $comment);
            DB::afterCommit(fn () => TransferApprovalDecided::dispatch($t->id, self::APPROVED, $user->id));

            return $t;
        }, LedgerService::ATTEMPTS);
    }

    /** POST /transfer-approvals/{id}/reject (R7): approval columns only, never ledger or balances. */
    public function reject(int $id, array $input, User $user): Transaction
    {
        return DB::transaction(function () use ($id, $input, $user) {
            $t = $this->lockedForDecision($id, $user);
            $comment = $this->comment($input, required: true);

            return $this->close($t, self::REJECTED, $comment, $user->id, 'transfer.rejected');
        }, LedgerService::ATTEMPTS);
    }

    /** R9 items 1-4 for approve / reject, with the row locked FOR UPDATE (R10). */
    private function lockedForDecision(int $id, User $user): Transaction
    {
        $ctx = app(BranchContext::class);
        $t = Transaction::withoutGlobalScopes()->lockForUpdate()->find($id);
        abort_unless($t && $this->visible($t, $user, $ctx), 404);
        abort_unless($this->isRequest($t), 422, self::NOT_WAITING);
        abort_unless($this->isApprover($user, $ctx), 403, self::BY_MAIN);
        abort_if((int) $t->created_by === (int) $user->id, 403, self::OWN_REQUEST);
        $this->assertPending($t);

        return $t;
    }

    /** 404 unless the row is one this user may see in this mode: own branch (either side), or Main's approver (R3). */
    public function visible(Transaction $t, User $user, ?BranchContext $ctx = null): bool
    {
        $ctx ??= app(BranchContext::class);
        if (! $ctx->isScoped()) {
            return $ctx->allBranches;
        }

        // Main's approver sees other branches' transfer requests only; another kind of row of another branch is a 404
        // (AG-064 review 7: no existence leak through the transfer routes).
        return (int) $t->branch_id === $ctx->branchId || (int) $t->related_branch_id === $ctx->branchId
            || (in_array($t->type, $this->types(), true) && $this->isApprover($user, $ctx));
    }

    // ------------------------------------------------------------------ R18 settings warning

    /** R18: an active non-Super-Admin user holds transfer.approve through a MAIN role. */
    public function mainHasApprover(): bool
    {
        $main = Branch::main();

        return DB::table('model_has_roles as mr')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', fn ($j) => $j->on('r.id', '=', 'mr.role_id')->where('r.status', '=', 'active'))
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->join('branch_user as bu', fn ($j) => $j->on('bu.user_id', '=', 'u.id')->where('bu.branch_id', '=', $main->id))
            ->where('mr.model_type', User::class)->where('mr.branch_id', $main->id)
            ->where('p.name', 'transfer.approve')->where('u.status', 'active')->where('u.is_super_admin', false)
            ->exists();
    }

    // ------------------------------------------------------------------ S3 / S4 widget (GET /transfer-approvals)

    public function widget(User $user): array
    {
        $ctx = app(BranchContext::class);
        $today = BusinessDate::today();
        $threshold = app(Settings::class)->transferApprovalThresholdCents();
        $approver = $this->isApprover($user, $ctx);

        $pending = fn () => Transaction::withoutGlobalScopes()->where('approval_status', self::PENDING)->whereIn('type', self::TYPES)
            ->with(['fromAccount:id,name,branch_id,current_balance', 'fromAccount.branch:id,code', 'toAccount:id,name,branch_id', 'toAccount.branch:id,code',
                'creator:id,name,status'])
            ->orderBy('requested_on')->orderBy('id');

        $toApprove = ['data' => [], 'total' => 0, 'amount' => '0.00'];
        if ($approver) {
            $q = $pending();
            $toApprove['total'] = (clone $q)->count();
            $toApprove['amount'] = Money::fromCents((int) (clone $q)->toBase()->selectRaw('COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c')->value('c'));
            $rows = $q->limit(self::WIDGET_ROWS)->get();
            $ctxData = $this->pageData($rows, $today, $threshold);
            $toApprove['data'] = $rows->map(fn (Transaction $t) => $this->row($t, $user, $today, $threshold, true, $ctxData))->all();
        }

        $mine = ['data' => [], 'total' => 0];
        if ($ctx->isScoped()) {
            $q = $pending()->where('created_by', $user->id)->where('branch_id', $ctx->branchId);
            $mine['total'] = (clone $q)->count();
            $rows = $q->limit(self::WIDGET_ROWS)->get();
            $ctxData = $this->pageData($rows, $today, $threshold);
            $mine['data'] = $rows->map(fn (Transaction $t) => $this->row($t, $user, $today, $threshold, $approver, $ctxData))->all();
        }

        return [
            'threshold' => $threshold === null ? null : Money::fromCents($threshold),
            'to_approve' => $toApprove,
            'my_requests' => $mine,
        ];
    }

    /** Review m8: the per-account figures of a page of rows, two grouped queries in all. */
    private function pageData($rows, string $today, ?int $threshold): array
    {
        $ids = $rows->pluck('from_account_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        return ['pending' => $this->pendingByAccount($ids), 'below' => $this->belowThresholdByAccount($ids, $today, $threshold)];
    }

    private function row(Transaction $t, User $user, string $today, ?int $threshold, bool $approver, array $page): array
    {
        $acc = (int) $t->from_account_id;
        $requestedOn = $t->requested_on?->format('Y-m-d');
        $mine = (int) $t->created_by === (int) $user->id;

        return [
            'id' => $t->id,
            'ref_no' => $t->ref_no,
            'type' => $t->type,
            'requested_date' => $t->requested_date?->format('Y-m-d'),
            'requested_on' => $requestedOn,
            'age_days' => $requestedOn ? TransferReceipts::ageDays($requestedOn, $today) : 0,
            'expires_on' => self::expiresOn($requestedOn),
            'from' => ['branch_code' => $t->fromAccount?->branch?->code, 'account' => $t->fromAccount?->name,
                'available' => Money::fromCents(Money::toCents($t->fromAccount?->current_balance))],
            'to' => ['branch_code' => $t->toAccount?->branch?->code, 'account' => $t->toAccount?->name],
            'amount' => Money::fromCents(Money::toCents($t->amount)),
            'other_pending_from_account' => Money::fromCents(($page['pending'][$acc] ?? 0) - Money::toCents($t->amount)),
            'below_threshold_7d' => $page['below'][$acc] ?? ['count' => 0, 'amount' => '0.00'],
            'note' => $t->description,
            'requested_by' => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name] : null,
            'requested_by_you' => $mine,
            'requester_inactive' => $t->creator !== null && $t->creator->status !== 'active',
            'can_approve' => $approver && ! $mine,
            'can_cancel' => $mine,
        ];
    }

    /**
     * R16 (accounting review finding 4, detective): posted branch transfers / fund returns from the account in the last
     * 7 days at or below the current threshold, not themselves approved requests.
     */
    public function belowThresholdByAccount(array $accountIds, string $today, ?int $threshold): array
    {
        if ($threshold === null || ! $accountIds) {
            return [];
        }
        $since = (new DateTimeImmutable($today))->modify('-'.(self::BELOW_DAYS - 1).' days')->format('Y-m-d');
        $x = Money::sqlCents('amount');

        return DB::table('transactions')->whereIn('from_account_id', $accountIds)->whereIn('type', self::TYPES)->whereIn('status', ['posted', 'reversed'])
            ->whereNull('approval_status')->whereDate('txn_date', '>=', $since)->whereDate('txn_date', '<=', $today)
            ->whereRaw("$x <= ?", [$threshold])->groupBy('from_account_id')
            ->selectRaw("from_account_id, COUNT(*) as n, COALESCE(SUM($x), 0) as c")->get()
            ->mapWithKeys(fn ($r) => [(int) $r->from_account_id => ['count' => (int) $r->n, 'amount' => Money::fromCents((int) $r->c)]])->all();
    }

    /** S5 / API: the detail's buttons. */
    public function canApprove(Transaction $t, User $user): bool
    {
        return $t->approval_status === self::PENDING && in_array($t->type, self::TYPES, true) && (int) $t->created_by !== (int) $user->id
            && $this->isApprover($user);
    }

    public function canCancel(Transaction $t, User $user): bool
    {
        return $t->approval_status === self::PENDING && in_array($t->type, self::TYPES, true) && (int) $t->created_by === (int) $user->id
            && $user->canDo('transfer.create');
    }

    // ------------------------------------------------------------------ S7 / S8 summaries

    /**
     * Requests pending NOW, dated (requested_date) in [$from, $to], sent or received by one of $branchIds (null = all).
     * Information only.
     *
     * @param  int[]|null  $branchIds
     */
    public function pendingQuery(?string $from, string $to, ?array $branchIds = null): Builder
    {
        return Transaction::withoutGlobalScopes()->where('approval_status', self::PENDING)->whereIn('type', self::TYPES)
            ->when($from, fn ($q, $v) => $q->whereDate('requested_date', '>=', $v))
            ->whereDate('requested_date', '<=', $to)
            ->when($branchIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $branchIds)->orWhereIn('related_branch_id', $branchIds)));
    }

    /** S8: count and amount (period-lock review, year-end preview). */
    public function pendingSummary(?string $from, string $to, ?array $branchIds = null): array
    {
        $r = $this->pendingQuery($from, $to, $branchIds)->toBase()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c')->first();

        return ['transfers_pending_approval' => (int) $r->n, 'transfers_pending_approval_amount' => Money::fromCents((int) $r->c)];
    }

    /** S7: the rows of the Transfers report section "Pending approval now". */
    public function pendingRows(string $to, array $branchIds): array
    {
        $rows = $this->pendingQuery(null, $to, $branchIds)
            ->with(['fromAccount:id,name,branch_id', 'fromAccount.branch:id,code', 'toAccount:id,name,branch_id', 'toAccount.branch:id,code'])
            ->orderBy('requested_date')->orderBy('id')->get();
        $data = $rows->map(fn (Transaction $t) => [
            'id' => $t->id,
            'ref_no' => $t->ref_no,
            'type' => $t->type,
            'requested_date' => $t->requested_date?->format('Y-m-d'),
            'from' => ['branch_code' => $t->fromAccount?->branch?->code, 'account' => $t->fromAccount?->name],
            'to' => ['branch_code' => $t->toAccount?->branch?->code, 'account' => $t->toAccount?->name],
            'amount' => Money::fromCents(Money::toCents($t->amount)),
        ])->all();

        return ['data' => $data, 'count' => count($data), 'amount' => Money::fromCents(array_sum(array_map(fn ($r) => Money::toCents($r['amount']), $data)))];
    }
}
