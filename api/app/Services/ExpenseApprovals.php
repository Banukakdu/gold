<?php

namespace App\Services;

use App\Events\ExpenseApprovalDecided;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AG-064 expense approvals (docs/specs/expense-approvals.md). An expense above its branch's limit, recorded by a
 * non-Super-Admin, waits for approval by that branch's `expense.approve` holders or a Super Admin before it posts.
 *
 * A control step only: a pending request is a `transactions` row with no ledger lines. States, reject / cancel / expiry
 * and the R9 "already decided" message are shared with AG-051 (ApprovalRequests). Approval posts through
 * LedgerService::postApproved (the only writer of ledger lines and balances); LedgerService::incomeOrExpense consults
 * rule() when the expense is recorded.
 */
class ExpenseApprovals extends ApprovalRequests
{
    public const TYPES = [Transaction::EXPENSE];

    /** R2 / R2b hold reasons (API `hold_reason`). */
    public const THRESHOLD = 'threshold';

    public const SAME_DAY_TOTAL = 'same_day_total';

    public const OWN_REQUEST = 'You recorded this expense. Someone else must approve it.';

    public const SUPER_ADMIN_ONLY = "This expense was recorded by a user who manages this branch's users. Only a Super Admin can approve it.";

    public const MISSING_PERMISSION = 'Missing permission: expense.approve';

    /** MUST 4 (Q4): approving a request with no file needs a reason. */
    public const NO_RECEIPT = 'Say why there is no receipt (10-500 characters).';

    /** R19: evidence of a decided request that never posted is frozen. */
    public const FILES_FROZEN = 'This request was not posted; its files cannot be changed.';

    /** R4a: holding any of these through a role in the expense's branch makes the requester a branch user manager. */
    public const USER_MANAGER_KEYS = ['user.create', 'user.edit', 'role.manage'];

    /** R4b: audit events by which a user brings someone into a branch, and the look-back window. */
    public const ONBOARD_EVENTS = ['user.created', 'user.invited', 'user.role_assigned'];

    public const ONBOARD_DAYS = 90;

    public function types(): array
    {
        return self::TYPES;
    }

    protected function auditPrefix(): string
    {
        return 'expense';
    }

    protected function dispatchDecided(int $transactionId, string $state, ?int $userId): void
    {
        ExpenseApprovalDecided::dispatch($transactionId, $state, $userId);
    }

    // ------------------------------------------------------------------ R2 / R2b / R14 / R17 (LedgerService)

    /**
     * R2 / R2b, called by LedgerService::incomeOrExpense inside its transaction after every check, with the account
     * locked. decision: HOLD, NOT_REQUIRED_SUPER_ADMIN (a Super Admin above the limit, audit flag) or null.
     *
     * @return array{decision: ?string, reason: ?string, threshold: ?int, same_day: int}
     */
    public function rule(?User $user, Account $acc, int $categoryId, string $date, int $cents): array
    {
        $threshold = Branch::whereKey($acc->branch_id)->value('expense_approval_threshold_cents');
        $threshold = $threshold === null ? null : (int) $threshold;
        $out = ['decision' => null, 'reason' => null, 'threshold' => $threshold, 'same_day' => 0];
        if ($threshold === null || $user === null) {
            return $out;
        }
        if ($user->is_super_admin) {
            return ['decision' => $cents > $threshold ? self::NOT_REQUIRED_SUPER_ADMIN : null] + $out;
        }
        if ($cents > $threshold) {
            return ['decision' => self::HOLD, 'reason' => self::THRESHOLD] + $out;
        }
        $sameDay = $this->sameDayTotal($user, (int) $acc->branch_id, $categoryId, $date);
        if ($sameDay + $cents > $threshold) {
            return ['decision' => self::HOLD, 'reason' => self::SAME_DAY_TOTAL, 'same_day' => $sameDay] + $out;
        }

        return $out;
    }

    /**
     * R2b: cents of the user's own posted expenses (not approved requests) in this branch and category dated $date.
     * The user's row is locked first, so two parallel requests by the same user run one after the other and the second
     * sees the first; the sum itself is a locking read (accounting review MUST 3).
     */
    private function sameDayTotal(User $user, int $branchId, int $categoryId, string $date): int
    {
        User::whereKey($user->id)->lockForUpdate()->value('id');

        return (int) $this->sameDayQuery((int) $user->id, $branchId, $categoryId, $date)->value('c');
    }

    /**
     * The R2b locking read itself (public so the MySQL plan test EXPLAINs exactly this query). Review 3: a sargable
     * half-open range (not DATE()), so MySQL locks only this user's rows of that category and day through
     * (created_by, category_id, txn_date). Portable: also matches "Y-m-d 00:00:00" in SQLite.
     */
    public function sameDayQuery(int $userId, int $branchId, int $categoryId, string $date): QueryBuilder
    {
        return DB::table('transactions')->where('type', Transaction::EXPENSE)->where('status', 'posted')->whereNull('approval_status')
            ->where('created_by', $userId)->where('branch_id', $branchId)->where('category_id', $categoryId)
            ->where('txn_date', '>=', $date)->where('txn_date', '<', CarbonImmutable::parse($date)->addDay()->toDateString())
            ->lockForUpdate()->selectRaw('COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c');
    }

    /**
     * R14: cents now pending on the money account: expense requests on it plus transfer requests from it, except
     * $exceptId. $current: a locking read (made inside the request transaction, the account already locked).
     */
    public function pendingOnAccount(int $accountId, ?int $exceptId = null, bool $current = false): int
    {
        return (int) $this->pendingOnAccountsQuery([$accountId])
            ->when($exceptId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->when($current, fn ($q) => $q->sharedLock())
            ->selectRaw('COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c')->value('c');
    }

    private function pendingOnAccountsQuery(array $accountIds)
    {
        return DB::table('transactions')->where('approval_status', self::PENDING)->where(fn ($w) => $w
            ->where(fn ($x) => $x->where('type', Transaction::EXPENSE)->whereIn('account_id', $accountIds))
            ->orWhere(fn ($x) => $x->whereIn('type', TransferApprovals::TYPES)->whereIn('from_account_id', $accountIds)));
    }

    /** R14 for every account of a page: @return array<int, int> cents by account id */
    private function pendingByAccount(array $accountIds): array
    {
        if (! $accountIds) {
            return [];
        }
        $x = Money::sqlCents('amount');
        $rows = $this->pendingOnAccountsQuery($accountIds)
            ->selectRaw("CASE WHEN type = ? THEN account_id ELSE from_account_id END as acc, COALESCE(SUM($x), 0) as c", [Transaction::EXPENSE])
            // Group by the alias: MySQL's only_full_group_by cannot match two separately bound CASE expressions.
            ->groupBy('acc')->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->acc => (int) $r->c])->all();
    }

    /** R17: a warning, never a block. $otherCents = what already waits on the account before this request. */
    public static function overCommitWarning(string $balance, string $amount, int $otherCents): ?string
    {
        if ($otherCents === 0 || Money::toCents($amount) + $otherCents <= Money::toCents($balance)) {
            return null;
        }

        return 'Other requests on this account already wait for '.Money::format(Money::fromCents($otherCents)).'; together they exceed the balance.';
    }

    // ------------------------------------------------------------------ R3 / R4 / R4a / R4b

    /** R4a: the user holds user.create, user.edit or role.manage through a role in the branch (a branch user manager). */
    public function isBranchUserManager(int $userId, int $branchId): bool
    {
        return $userId > 0 && app(InAppNotifications::class)->holds($userId, self::USER_MANAGER_KEYS, $branchId);
    }

    /** R4a: stored at request time in the audit row, or true now; either makes the request Super Admin only. */
    public function superAdminOnly(Transaction $t, ?array $stored = null, ?bool $managerNow = null): bool
    {
        $stored ??= $this->requestAudits([$t->id])[$t->id] ?? [];

        return ($stored['super_admin_only'] ?? false) === true
            || ($managerNow ?? $this->isBranchUserManager((int) $t->created_by, (int) $t->branch_id));
    }

    /** @return array<int, array> the expense.approval_requested audit values by transaction id */
    private function requestAudits(array $ids): array
    {
        return AuditLog::where('event', 'expense.approval_requested')->where('auditable_type', Transaction::class)
            ->whereIn('auditable_id', $ids ?: [0])->get(['auditable_id', 'new_values'])
            ->mapWithKeys(fn ($r) => [(int) $r->auditable_id => (array) $r->new_values])->all();
    }

    /**
     * R4b: the approver created, invited or assigned a role to the requester in the expense's branch within 90 days
     * before the request's requested_on (Asia/Colombo days). Never for a Super Admin approver (accountant sign-off MUST 6:
     * the owner side approving a Branch Admin's request is the R4a control working, not a dependence).
     */
    public function onboardedRequester(Transaction $t, User $approver): bool
    {
        return $this->onboardedIn($t, $this->onboardings(collect([$t]), $approver));
    }

    /** [from, to) in UTC of the R4b window of a request made on $on (Colombo days). */
    private static function onboardWindow(string $on): array
    {
        $tz = BusinessDate::timezone();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $on, $tz);

        return [$day->subDays(self::ONBOARD_DAYS)->setTimezone('UTC')->format('Y-m-d H:i:s'), $day->addDay()->setTimezone('UTC')->format('Y-m-d H:i:s')];
    }

    /** Review 6: the approver's onboarding audit rows for the requesters of $rows, in one query. */
    private function onboardings(Collection $rows, User $approver): Collection
    {
        $approverId = (int) $approver->id;
        $rows = $rows->filter(fn ($t) => $t->created_by && $t->requested_on);
        if ($rows->isEmpty() || $approver->is_super_admin) {
            return collect();
        }
        $windows = $rows->map(fn ($t) => self::onboardWindow($t->requested_on->format('Y-m-d')));

        return AuditLog::whereIn('event', self::ONBOARD_EVENTS)->where('auditable_type', User::class)
            ->whereIn('auditable_id', $rows->pluck('created_by')->unique()->values()->all())
            ->whereIn('branch_id', $rows->pluck('branch_id')->unique()->values()->all())->where('user_id', $approverId)
            ->where('created_at', '>=', $windows->min(fn ($w) => $w[0]))->where('created_at', '<', $windows->max(fn ($w) => $w[1]))
            ->get(['auditable_id', 'branch_id', 'created_at']);
    }

    private function onboardedIn(Transaction $t, Collection $audits): bool
    {
        if (! $t->created_by || ! $t->requested_on) {
            return false;
        }
        [$from, $to] = self::onboardWindow($t->requested_on->format('Y-m-d'));

        return $audits->contains(fn ($a) => (int) $a->auditable_id === (int) $t->created_by && (int) $a->branch_id === (int) $t->branch_id
            && ($at = substr((string) $a->getRawOriginal('created_at'), 0, 19)) >= $from && $at < $to);
    }

    /** R3: Super Admin (any mode), or `expense.approve` in the active branch (and, when given, that is the expense's). */
    public function isApprover(User $user, ?BranchContext $ctx = null, ?int $branchId = null): bool
    {
        $ctx ??= app(BranchContext::class);
        if ($user->is_super_admin) {
            return true;
        }

        return $ctx->isScoped() && ($branchId === null || $branchId === (int) $ctx->branchId) && $user->canDo('expense.approve');
    }

    /** R3 / R9 item 1: the expense's branch is the active branch, or a Super Admin (All mode included). */
    public function visible(Transaction $t, User $user, ?BranchContext $ctx = null): bool
    {
        $ctx ??= app(BranchContext::class);
        if ($user->is_super_admin) {
            return $ctx->isScoped() || $ctx->allBranches;
        }

        return $ctx->isScoped() && (int) $t->branch_id === (int) $ctx->branchId;
    }

    /** POST /expense-approvals/{id}/approve: R9 order, then LedgerService::postApproved (R5, R6). */
    public function approve(int $id, array $input, User $user): Transaction
    {
        return DB::transaction(function () use ($id, $input, $user) {
            $t = $this->lockedForDecision($id, $user);
            $files = $this->filesCount($t->id);
            $comment = $this->comment($input, required: $files === 0, message: self::NO_RECEIPT);
            $paid = (bool) (validator($input, ['paid_before_approval' => ['nullable', 'boolean']])->validate()['paid_before_approval'] ?? false);
            $flag = $this->onboardedRequester($t, $user);

            $t = app(LedgerService::class)->postApproved($t, $comment, [
                'files_count' => $files, 'paid_before_approval' => $paid, 'approver_onboarded_requester' => $flag,
            ]);
            $tid = $t->id;
            DB::afterCommit(fn () => ExpenseApprovalDecided::dispatch($tid, self::APPROVED, $user->id));

            return $t;
        }, LedgerService::ATTEMPTS);
    }

    /** POST /expense-approvals/{id}/reject (R7): approval columns only, never ledger or balances. */
    public function reject(int $id, array $input, User $user): Transaction
    {
        return DB::transaction(function () use ($id, $input, $user) {
            $t = $this->lockedForDecision($id, $user);
            $comment = $this->comment($input, required: true);

            return $this->close($t, self::REJECTED, $comment, $user->id, 'expense.rejected');
        }, LedgerService::ATTEMPTS);
    }

    /** R9 items 1-5 for approve / reject, with the row locked FOR UPDATE (R10). */
    private function lockedForDecision(int $id, User $user): Transaction
    {
        $ctx = app(BranchContext::class);
        $t = Transaction::withoutGlobalScopes()->lockForUpdate()->find($id);
        abort_unless($t && $this->visible($t, $user, $ctx), 404);
        abort_unless($this->isRequest($t), 422, self::NOT_WAITING);
        abort_unless($this->isApprover($user, $ctx, (int) $t->branch_id), 403, self::MISSING_PERMISSION);
        abort_if((int) $t->created_by === (int) $user->id, 403, self::OWN_REQUEST);
        abort_if(! $user->is_super_admin && $this->superAdminOnly($t), 403, self::SUPER_ADMIN_ONLY);
        $this->assertPending($t);

        return $t;
    }

    /** Active (not removed) files on the entry. */
    public function filesCount(int $transactionId): int
    {
        return DB::table('attachments')->where('transaction_id', $transactionId)->whereNull('removed_at')->count();
    }

    // ------------------------------------------------------------------ S5 detail

    public function canApprove(Transaction $t, User $user): bool
    {
        return $t->approval_status === self::PENDING && $t->type === Transaction::EXPENSE && (int) $t->created_by !== (int) $user->id
            && $this->isApprover($user, null, (int) $t->branch_id) && ($user->is_super_admin || ! $this->superAdminOnly($t));
    }

    public function canCancel(Transaction $t, User $user): bool
    {
        return $t->approval_status === self::PENDING && $t->type === Transaction::EXPENSE && (int) $t->created_by === (int) $user->id
            && $user->canDo('expense.create');
    }

    /** S3 / S5: the requested date is in a locked month or a closed year now. */
    public function requestedPeriodLocked(Transaction $t): bool
    {
        $d = $t->requested_date?->format('Y-m-d');

        return $d !== null && app(YearEnd::class)->closedOrLockedMessage((int) $t->branch_id, $d) !== null;
    }

    // ------------------------------------------------------------------ R18 branch warning

    /** R18: an active non-Super-Admin member of the branch holds expense.approve through an active role there. */
    public function branchHasApprover(Branch $branch): bool
    {
        return app(InAppNotifications::class)->holders('expense.approve', (int) $branch->id)
            ->contains(fn (User $u) => ! $u->is_super_admin);
    }

    public static function noApproverWarning(Branch $branch): string
    {
        return "Only the Super Admin can approve {$branch->code}'s expenses above the limit.";
    }

    // ------------------------------------------------------------------ S3 / S4 widget (GET /expense-approvals)

    public function widget(User $user): array
    {
        $ctx = app(BranchContext::class);
        $today = BusinessDate::today();
        $approver = $this->isApprover($user, $ctx);

        $pending = fn () => Transaction::withoutGlobalScopes()->where('approval_status', self::PENDING)->where('type', Transaction::EXPENSE);
        $page = fn ($q) => $q->with(['account:id,name,branch_id,current_balance', 'branch:id,code', 'category:id,name,parent_id', 'category.parent:id,name',
            'creator:id,name,status,is_super_admin'])
            ->withCount(['attachments as files_count' => fn ($a) => $a->whereNull('removed_at')])
            ->orderBy('requested_on')->orderBy('id')->limit(self::WIDGET_ROWS)->get();

        $toApprove = ['data' => [], 'total' => 0, 'amount' => '0.00'];
        if ($approver) {
            $q = $pending()->when($ctx->isScoped(), fn ($q) => $q->where('branch_id', $ctx->branchId));
            $toApprove['total'] = (clone $q)->count();
            $toApprove['amount'] = Money::fromCents((int) (clone $q)->toBase()->selectRaw('COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c')->value('c'));
            $rows = $page($q);
            $data = $this->pageData($rows, $today, $user);
            $toApprove['data'] = $rows->map(fn (Transaction $t) => $this->row($t, $user, true, $data))->all();
        }

        $mine = ['data' => [], 'total' => 0];
        if ($ctx->isScoped()) {
            $q = $pending()->where('created_by', $user->id)->where('branch_id', $ctx->branchId);
            $mine['total'] = (clone $q)->count();
            $rows = $page($q);
            $data = $this->pageData($rows, $today, $approver ? $user : null);
            $mine['data'] = $rows->map(fn (Transaction $t) => $this->row($t, $user, $approver, $data))->all();
        }

        $threshold = $ctx->isScoped() ? Branch::whereKey($ctx->branchId)->value('expense_approval_threshold_cents') : null;

        return [
            'threshold' => $threshold === null ? null : Money::fromCents((int) $threshold),
            // S3: in warn mode the approve dialog says when the account will go below zero (block mode refuses instead).
            'negative_balance' => app(Settings::class)->expenseNegativeBalance(),
            'to_approve' => $toApprove,
            'my_requests' => $mine,
        ];
    }

    /** The per-page figures (R14, R16, R4a, R4b, locks, memberships): a few grouped queries per page, none per row. */
    private function pageData(Collection $rows, string $today, ?User $approver = null): array
    {
        $accounts = $rows->pluck('account_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $notifications = app(InAppNotifications::class);
        $managers = [];
        foreach ($rows->pluck('branch_id')->map(fn ($v) => (int) $v)->unique() as $branchId) {
            $managers[$branchId] = array_flip($notifications->holderIdsOf(self::USER_MANAGER_KEYS, $branchId));
        }
        $locked = [];
        foreach ($rows as $t) {
            $d = $t->requested_date?->format('Y-m-d');
            $key = $t->branch_id.':'.substr((string) $d, 0, 7); // locks are monthly and fiscal years start on a month
            if ($d !== null && ! array_key_exists($key, $locked)) {
                $locked[$key] = app(YearEnd::class)->closedOrLockedMessage((int) $t->branch_id, $d) !== null;
            }
        }

        return [
            'managers' => $managers,
            'locked' => $locked,
            'onboardings' => $approver ? $this->onboardings($rows, $approver) : collect(),
            'today' => $today,
            'pending' => $this->pendingByAccount($accounts),
            'below' => $this->belowThreshold($rows, $today),
            'audits' => $this->requestAudits($rows->pluck('id')->all()),
            'members' => DB::table('branch_user')->whereIn('user_id', $rows->pluck('created_by')->unique()->all() ?: [0])->get(['user_id', 'branch_id'])
                ->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->branch_id => true])->all(),
        ];
    }

    private function row(Transaction $t, User $user, bool $approver, array $page): array
    {
        $acc = (int) $t->account_id;
        $requestedOn = $t->requested_on?->format('Y-m-d');
        $mine = (int) $t->created_by === (int) $user->id;
        $superOnly = $this->superAdminOnly($t, $page['audits'][$t->id] ?? [], isset($page['managers'][(int) $t->branch_id][(int) $t->created_by]));
        $u = $t->creator;
        $decides = $approver && ! $mine && ($user->is_super_admin || ! $superOnly);

        return [
            'id' => $t->id,
            'ref_no' => $t->ref_no,
            'branch' => ['id' => $t->branch_id, 'code' => $t->branch?->code],
            'requested_date' => $t->requested_date?->format('Y-m-d'),
            'requested_on' => $requestedOn,
            'age_days' => $requestedOn ? TransferReceipts::ageDays($requestedOn, $page['today']) : 0,
            'expires_on' => self::expiresOn($requestedOn),
            'account' => ['id' => $acc, 'name' => $t->account?->name, 'available' => Money::fromCents(Money::toCents($t->account?->current_balance))],
            'category_path' => self::categoryPath($t->category),
            'payee_payer' => $t->payee_payer,
            'amount' => Money::fromCents(Money::toCents($t->amount)),
            'other_pending_from_account' => Money::fromCents(($page['pending'][$acc] ?? 0) - Money::toCents($t->amount)),
            'files_count' => (int) $t->files_count,
            'below_threshold_7d' => $page['below'][$t->branch_id.':'.$t->category_id] ?? ['count' => 0, 'amount' => '0.00'],
            'requested_by' => $u ? ['id' => $u->id, 'name' => $u->name] : null,
            'requested_by_you' => $mine,
            'requester_inactive' => $u !== null && $u->status !== 'active',
            'requester_left_branch' => $u !== null && ! $u->is_super_admin && ! isset($page['members'][$u->id.':'.$t->branch_id]),
            'super_admin_only' => $superOnly,
            'approver_onboarded_requester' => $decides && $this->onboardedIn($t, $page['onboardings']),
            'requested_period_locked' => $page['locked'][$t->branch_id.':'.$t->requested_date?->format('Y-m')] ?? false,
            'can_approve' => $decides,
            'can_cancel' => $mine && $user->canDo('expense.create'),
        ];
    }

    /**
     * R16 (detective): per (branch, category) of the page, posted expenses dated in the last 7 days at or below the
     * branch's current limit, not themselves approved requests. Keyed "branch_id:category_id".
     */
    private function belowThreshold(Collection $rows, string $today): array
    {
        $pairs = $rows->map(fn ($t) => [(int) $t->branch_id, (int) $t->category_id])->unique(fn ($p) => $p[0].':'.$p[1]);
        if ($pairs->isEmpty()) {
            return [];
        }
        $since = (new DateTimeImmutable($today))->modify('-'.(self::BELOW_DAYS - 1).' days')->format('Y-m-d');
        $x = Money::sqlCents('t.amount');

        return DB::table('transactions as t')->join('branches as b', 'b.id', '=', 't.branch_id')
            ->whereNotNull('b.expense_approval_threshold_cents')
            ->whereIn('t.branch_id', $pairs->pluck(0)->unique()->all())->whereIn('t.category_id', $pairs->pluck(1)->unique()->all())
            ->where('t.type', Transaction::EXPENSE)->where('t.status', 'posted')->whereNull('t.approval_status')
            ->whereDate('t.txn_date', '>=', $since)->whereDate('t.txn_date', '<=', $today)
            ->whereRaw("$x <= b.expense_approval_threshold_cents")
            ->groupBy('t.branch_id', 't.category_id')
            ->selectRaw("t.branch_id, t.category_id, COUNT(*) as n, COALESCE(SUM($x), 0) as c")->get()
            ->mapWithKeys(fn ($r) => [$r->branch_id.':'.$r->category_id => ['count' => (int) $r->n, 'amount' => Money::fromCents((int) $r->c)]])->all();
    }

    /** "Utilities > Electricity" */
    public static function categoryPath(?Category $c): string
    {
        if (! $c) {
            return '';
        }
        $parent = $c->relationLoaded('parent') ? $c->parent : ($c->parent_id ? Category::find($c->parent_id) : null);

        return $parent ? "{$parent->name} > {$c->name}" : (string) $c->name;
    }

    // ------------------------------------------------------------------ S7 / S8 summaries

    /**
     * Expense requests pending NOW, dated (requested_date) in [$from, $to], of $branchIds (null = all). Information only.
     *
     * @param  int[]|null  $branchIds
     */
    public function pendingQuery(?string $from, string $to, ?array $branchIds = null): Builder
    {
        return Transaction::withoutGlobalScopes()->where('approval_status', self::PENDING)->where('type', Transaction::EXPENSE)
            ->when($from, fn ($q, $v) => $q->whereDate('requested_date', '>=', $v))
            ->whereDate('requested_date', '<=', $to)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds));
    }

    /** S8: count and amount (period-lock review, year-end preview). */
    public function pendingSummary(?string $from, string $to, ?array $branchIds = null): array
    {
        $r = $this->pendingQuery($from, $to, $branchIds)->toBase()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM('.Money::sqlCents('amount').'), 0) as c')->first();

        return ['expenses_pending_approval' => (int) $r->n, 'expenses_pending_approval_amount' => Money::fromCents((int) $r->c)];
    }

    /** S7 rows (Income & Expense report) and the S8 year-end list (SHOULD 7), oldest requested date first. */
    public function pendingRows(?string $from, string $to, ?array $branchIds = null): array
    {
        $rows = $this->pendingQuery($from, $to, $branchIds)->with(['branch:id,code', 'category:id,name,parent_id', 'category.parent:id,name'])
            ->orderBy('requested_date')->orderBy('id')->get();
        $data = $rows->map(fn (Transaction $t) => [
            'id' => $t->id,
            'ref_no' => $t->ref_no,
            'branch' => ['id' => $t->branch_id, 'code' => $t->branch?->code],
            'requested_date' => $t->requested_date?->format('Y-m-d'),
            'requested_on' => $t->requested_on?->format('Y-m-d'),
            'category_path' => self::categoryPath($t->category),
            'amount' => Money::fromCents(Money::toCents($t->amount)),
        ])->all();
        // (S7 rows carry both dates: "Requested on" = requested_on, "Requested for" = requested_date, SHOULD 10.)

        return ['data' => $data, 'count' => count($data), 'amount' => Money::fromCents(array_sum(array_map(fn ($r) => Money::toCents($r['amount']), $data)))];
    }
}
