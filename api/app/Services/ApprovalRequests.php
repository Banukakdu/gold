<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The approval states and the parts of a decision that do not depend on what is held (AG-051 R1, R7-R10, reused by
 * AG-064 expense approvals, docs/specs/expense-approvals.md section 5 "Code shape"). A pending request is a
 * `transactions` row with no ledger lines; this class writes only approval columns and audit rows. Approval posts
 * through LedgerService::postApproved, the only writer of ledger lines and balances.
 *
 * A subclass names the types it holds (types()), its audit event prefix ("transfer", "expense") and dispatches its own
 * "decided" event.
 */
abstract class ApprovalRequests
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** R1 labels. A null approval_status is "Not required". */
    public const LABELS = [
        self::PENDING => 'Pending approval',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
        self::CANCELLED => 'Cancelled by requester',
        self::EXPIRED => 'Expired',
    ];

    public const NOT_REQUIRED = 'Not required';

    /** R1: final states that never posted (transactions.status = rejected). */
    public const CLOSED = [self::REJECTED, self::CANCELLED, self::EXPIRED];

    /** R8 */
    public const EXPIRY_DAYS = 30;

    public const WIDGET_ROWS = 50;

    /** R16: the window of the "posted below threshold" line, today included. */
    public const BELOW_DAYS = 7;

    /** R2 results. */
    public const HOLD = 'hold';

    public const NOT_REQUIRED_SUPER_ADMIN = 'not_required_super_admin';

    public const NOT_WAITING = 'This entry is not waiting for approval.';

    public const CANCEL_OTHER = 'Only the requester can cancel this request.';

    public const REJECT_COMMENT = 'Give the reason, sent to the requester (10 to 500 characters).';

    public const CHANGED = 'This request was changed by someone else. Reload and try again.';

    /** @return string[] the transaction types this kind of approval holds */
    abstract public function types(): array;

    /** Audit event prefix: "transfer" or "expense". */
    abstract protected function auditPrefix(): string;

    /** Dispatch the "decided" event of this kind (called after commit). */
    abstract protected function dispatchDecided(int $transactionId, string $state, ?int $userId): void;

    public static function label(?string $state): string
    {
        return self::LABELS[$state] ?? self::NOT_REQUIRED;
    }

    public static function expiresOn(?string $requestedOn): ?string
    {
        return $requestedOn ? (new DateTimeImmutable($requestedOn))->modify('+'.self::EXPIRY_DAYS.' days')->format('Y-m-d') : null;
    }

    /** R9 item 2: a request of this kind (an approval_status, and a type this class holds). */
    protected function isRequest(Transaction $t): bool
    {
        return $t->approval_status !== null && in_array($t->type, $this->types(), true);
    }

    /** R9: "This request was already ...". */
    protected function assertPending(Transaction $t): void
    {
        if ($t->approval_status === self::PENDING) {
            return;
        }
        $on = $t->decided_at?->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d') ?? '';
        $by = $t->decided_by ? (User::find($t->decided_by)?->name ?? 'another user') : 'another user';
        abort(422, 'This request was already '.match ($t->approval_status) {
            self::APPROVED => "approved by {$by} on {$on}",
            self::REJECTED => "rejected by {$by} on {$on}",
            self::CANCELLED => "cancelled by the requester on {$on}",
            default => "expired on {$on}",
        }.'.');
    }

    /** The trimmed comment; required = 10-500 characters with $message. */
    protected function comment(array $input, bool $required, string $message = self::REJECT_COMMENT): ?string
    {
        $v = $input['comment'] ?? null;
        $input['comment'] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $rules = ['comment' => $required ? ['required', 'string', 'min:10', 'max:500'] : ['nullable', 'string', 'max:500']];
        $d = validator($input, $rules, $required
            ? ['comment.required' => $message, 'comment.min' => $message, 'comment.max' => $message, 'comment.string' => $message]
            : ['comment.max' => 'The comment may not be longer than 500 characters.'])->validate();

        return $d['comment'] ?? null;
    }

    /** POST .../cancel (R7): only the requester. R9 order: 404, not waiting, not the requester, already decided. */
    public function cancel(int $id, array $input, User $user): Transaction
    {
        return DB::transaction(function () use ($id, $input, $user) {
            $t = Transaction::withoutGlobalScopes()->lockForUpdate()->find($id);
            abort_unless($t && $this->visible($t, $user), 404);
            abort_unless($this->isRequest($t), 422, self::NOT_WAITING);
            abort_if((int) $t->created_by !== (int) $user->id, 403, self::CANCEL_OTHER);
            $this->assertPending($t);
            $comment = $this->comment($input, required: false);

            return $this->close($t, self::CANCELLED, $comment, $user->id, $this->auditPrefix().'.approval_cancelled');
        }, LedgerService::ATTEMPTS);
    }

    /** 404 unless the row is one this user may see in this mode (R3 / R9 item 1). */
    abstract public function visible(Transaction $t, User $user, ?BranchContext $ctx = null): bool;

    /**
     * Reject, cancel or expire (R7, R8): one conditional update on approval_status = pending (R10), one audit row, and
     * the event after commit. $userId null = the expiry job.
     */
    protected function close(Transaction $t, string $state, ?string $comment, ?int $userId, string $event): Transaction
    {
        $now = now();
        $done = Transaction::withoutGlobalScopes()->whereKey($t->id)->where('approval_status', self::PENDING)->update([
            'status' => 'rejected', 'approval_status' => $state, 'approval_note' => $comment,
            'decided_by' => $userId, 'decided_at' => $now, 'updated_at' => $now,
        ]);
        abort_unless($done === 1, 422, self::CHANGED);
        $t->refresh();
        $new = ['approval_status' => $state, 'comment' => $comment, 'ref_no' => $t->ref_no];
        if ($state === self::EXPIRED) {
            $new = ['approval_status' => $state, 'expired_on' => BusinessDate::today(), 'ref_no' => $t->ref_no];
        } else {
            $new['all_branches'] = ! app(BranchContext::class)->isScoped();
        }
        AuditLog::record($event, $t, ['approval_status' => self::PENDING], $new, null,
            $userId === null ? ['user_id' => null, 'ip' => null, 'user_agent' => 'console'] : null);
        $id = $t->id;
        DB::afterCommit(fn () => $this->dispatchDecided($id, $state, $userId));

        return $t;
    }

    /** R8: every request of this kind pending since requested_on <= today - 30 becomes expired, row by row. */
    public function expire(): int
    {
        $cutoff = (new DateTimeImmutable(BusinessDate::today()))->modify('-'.self::EXPIRY_DAYS.' days')->format('Y-m-d');
        $ids = Transaction::withoutGlobalScopes()->where('approval_status', self::PENDING)->whereIn('type', $this->types())
            ->whereDate('requested_on', '<=', $cutoff)->orderBy('id')->pluck('id');
        $n = 0;
        foreach ($ids as $id) {
            $n += DB::transaction(function () use ($id) {
                $t = Transaction::withoutGlobalScopes()->lockForUpdate()->find($id);
                if (! $t || $t->approval_status !== self::PENDING) {
                    return 0; // decided meanwhile (R10)
                }
                $this->close($t, self::EXPIRED, null, null, $this->auditPrefix().'.approval_expired');

                return 1;
            }, LedgerService::ATTEMPTS);
        }

        return $n;
    }
}
