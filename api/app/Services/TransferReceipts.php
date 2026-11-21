<?php

namespace App\Services;

use App\Events\TransferReceiptChanged;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Settings;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AG-052 transfer receipt (docs/specs/transfer-receipt.md). The receiving branch confirms the cash handover of a
 * between-branch transfer, or reports a discrepancy.
 *
 * A control step only (R1): it writes the five receipt columns of `transactions` and an audit row, and never a ledger
 * line, `accounts.current_balance`, `transactions.status` or `amount`. Money stays with LedgerService, which calls
 * initialStatus() when it posts a transfer (R3) and cancelForReversal() when it reverses one (R7).
 */
class TransferReceipts
{
    /** The four between-branch types (section 1). Not internal transfers, capital, income, expense, reversals. */
    public const TYPES = [Transaction::OPENING_ALLOCATION, Transaction::FUND_ALLOCATION, Transaction::FUND_RETURN, Transaction::BRANCH_TRANSFER];

    public const AWAITING = 'awaiting';

    public const RECEIVED = 'received';

    public const DISPUTED = 'disputed';

    public const CANCELLED = 'cancelled';

    /** R2 labels (screen, PDF, Excel). A null receipt is "Not required". */
    public const LABELS = [
        self::AWAITING => 'Awaiting receipt',
        self::RECEIVED => 'Received',
        self::DISPUTED => 'Discrepancy reported',
        self::CANCELLED => 'Cancelled (reversed)',
    ];

    public const NOT_REQUIRED = 'Not required';

    /** Open receipts: listed in the widget, may still be confirmed. */
    public const OPEN = [self::AWAITING, self::DISPUTED];

    /** Transfers report option (MUST 1): in transit as at the report's To date (R14). */
    public const IN_TRANSIT = 'in_transit';

    public const IN_TRANSIT_LABEL = 'In transit at end date';

    public const WIDGET_ROWS = 50;

    public const NOT_NEEDED = 'This entry does not need a receipt.';

    public const REVERSED = 'This transfer was reversed. No receipt is needed.';

    public const SETTLED_NOTE = 'Say how the discrepancy was settled (10 to 500 characters).';

    public const REMOTE_NOTE = 'From All branches, say what you checked, e.g. the bank statement date (10 to 500 characters).';

    public const CHANGED = 'This receipt was changed by someone else. Reload and try again.';

    public const DISPUTE_NOTE = 'Describe the discrepancy (10 to 500 characters).';

    public static function label(?string $state): string
    {
        return self::LABELS[$state] ?? self::NOT_REQUIRED;
    }

    /** R3: called by LedgerService::transfer inside the DB transaction that creates the transfer. */
    public function initialStatus(string $type): ?string
    {
        return in_array($type, self::TYPES, true) && app(Settings::class)->transferReceiptRequired() ? self::AWAITING : null;
    }

    /**
     * R7: called by LedgerService::reverse inside its DB transaction, with the original locked. An open receipt becomes
     * "Cancelled (reversed)" (receipt_by / at / note kept); a received one stays received.
     */
    public function cancelForReversal(Transaction $original, Transaction $reversal): void
    {
        $old = $original->receipt_status;
        if (! in_array($old, self::OPEN, true)) {
            return;
        }
        $original->update(['receipt_status' => self::CANCELLED]);
        AuditLog::record('transfer.receipt_cancelled', $original, ['receipt_status' => $old],
            ['receipt_status' => self::CANCELLED, 'reversal_id' => $reversal->id, 'reversal_ref' => $reversal->ref_no], (int) $original->related_branch_id);
        $userId = auth()->id();
        DB::afterCommit(fn () => TransferReceiptChanged::dispatch($original->id, $old, self::CANCELLED, $userId));
    }

    /**
     * POST /transactions/{id}/receipt. Checks in the order of R6; nothing is written and no audit row on a refusal.
     * Allowed when the transfer's month is locked or its year closed (R8): it posts nothing.
     */
    public function act(int $id, array $input, User $user): Transaction
    {
        $ctx = app(BranchContext::class);
        if (is_string($input['note'] ?? null)) {
            $input['note'] = trim($input['note']) === '' ? null : trim($input['note']);
        }

        return DB::transaction(function () use ($id, $input, $user, $ctx) {
            // R4: the transfer belongs to the sending branch, so it is read without the branch scope; access is checked
            // here (the active branch must be the receiving branch, or All mode).
            $t = Transaction::withoutGlobalScopes()->with(['branch:id,code', 'receiptBy:id,name'])->lockForUpdate()->find($id);
            abort_unless($t && $this->visible($t, $ctx), 404);
            $this->refuse($t, $user);

            $action = validator($input, ['action' => 'required|in:received,discrepancy'],
                ['action.in' => 'Action must be received or discrepancy.'])->validate()['action'];
            // R6 concurrency (review finding 1): the state the user saw. A confirm typed against "awaiting" must never
            // settle a discrepancy reported meanwhile; the user reloads and decides again.
            $expected = validator($input, ['expected_status' => ['nullable', 'string', 'in:'.implode(',', self::OPEN)]])
                ->validate()['expected_status'] ?? null;
            if ($expected !== null && $expected !== $t->receipt_status) {
                abort(422, self::CHANGED);
            }
            if ($action === 'discrepancy' && $t->receipt_status === self::DISPUTED) {
                abort(422, 'A discrepancy is already reported. Confirm receipt when the money arrives, or ask '.$t->branch->code.' to reverse the transfer.');
            }
            $d = $this->validated($t, $action, $input, $ctx);

            $old = $t->receipt_status;
            $now = now();
            $new = $action === 'received' ? self::RECEIVED : self::DISPUTED;
            $cols = ['receipt_status' => $new, 'receipt_note' => $d['note'], 'receipt_by' => $user->id, 'receipt_at' => $now, 'updated_at' => $now]
                + ($action === 'received' ? ['received_on' => $d['received_on']] : []);
            // The row is locked (lockForUpdate above), so the state checked is the state written over.
            Transaction::withoutGlobalScopes()->whereKey($t->id)->update($cols);
            $t->refresh();
            $values = ['receipt_status' => $new, 'receipt_at' => $now->toIso8601String(), 'note' => $d['note'], 'all_branches' => ! $ctx->isScoped()]
                + ($action === 'received' ? ['received_on' => $d['received_on']] : []);
            AuditLog::record($action === 'received' ? 'transfer.receipt_received' : 'transfer.receipt_disputed', $t,
                ['receipt_status' => $old], $values, (int) $t->related_branch_id);
            DB::afterCommit(fn () => TransferReceiptChanged::dispatch($t->id, $old, $new, $user->id));

            return $t;
        }, LedgerService::ATTEMPTS);
    }

    /** R6 items 2-5 (item 1, visibility, is checked before). */
    private function refuse(Transaction $t, User $user): void
    {
        if (! in_array($t->type, self::TYPES, true) || $t->receipt_status === null) {
            abort(422, self::NOT_NEEDED);
        }
        if ($t->receipt_status === self::CANCELLED || $t->status !== 'posted') {
            abort(422, self::REVERSED);
        }
        if ((int) $t->created_by === (int) $user->id) {
            $code = DB::table('branches')->where('id', $t->related_branch_id)->value('code');
            abort(403, "You sent this transfer. Someone in {$code} must confirm receipt.");
        }
        if ($t->receipt_status === self::RECEIVED) {
            abort(422, 'Receipt was already confirmed by '.($t->receiptBy?->name ?? 'another user').' on '.$t->received_on?->format('Y-m-d').'.');
        }
    }

    /** R6 item 7. */
    private function validated(Transaction $t, string $action, array $input, BranchContext $ctx): array
    {
        $date = $t->txn_date->format('Y-m-d');
        $today = BusinessDate::today();
        if ($action === 'discrepancy') {
            $message = self::DISPUTE_NOTE;
        } elseif ($t->receipt_status === self::DISPUTED) {
            $message = self::SETTLED_NOTE; // review finding 3
        } elseif (! $ctx->isScoped()) {
            $message = self::REMOTE_NOTE; // review finding 4
        } else {
            $message = null;
        }
        $rules = ['note' => $message ? ['required', 'string', 'min:10', 'max:500'] : ['nullable', 'string', 'max:500']];
        if ($action === 'received') {
            $rules['received_on'] = ['required', 'date_format:Y-m-d', 'after_or_equal:'.$date, 'before_or_equal:'.$today];
        }
        $d = validator($input, $rules, [
            'note.required' => $message, 'note.min' => $message, 'note.max' => $message ?? 'The note may not be longer than 500 characters.',
            'received_on.required' => 'Enter the date the money was received.',
            'received_on.date_format' => 'Received on must be a date (YYYY-MM-DD).',
            'received_on.after_or_equal' => "Received on cannot be before the transfer date ({$date}).",
            'received_on.before_or_equal' => 'Received on cannot be in the future.',
        ])->validate();

        return ['note' => $d['note'] ?? null, 'received_on' => $d['received_on'] ?? null];
    }

    /** R4: branch mode = the receiving branch only; All mode (Super Admin) = any. */
    public function visible(Transaction $t, BranchContext $ctx): bool
    {
        return $ctx->isScoped() ? (int) $t->related_branch_id === $ctx->branchId : $ctx->allBranches;
    }

    /** R4 + R5 + state: the buttons of the widget and the Receipt panel. */
    public function canAcknowledge(Transaction $t, User $user, ?bool $hasKey = null): bool
    {
        return in_array($t->type, self::TYPES, true) && $t->status === 'posted' && in_array($t->receipt_status, self::OPEN, true)
            && $this->visible($t, app(BranchContext::class)) && (int) $t->created_by !== (int) $user->id
            && ($hasKey ?? $user->canDo('transfer.acknowledge'));
    }

    /**
     * S3 (QA O2): the discrepancy that a confirmation settled, from its audit row (the receipt columns then hold the
     * settlement). Null when the transfer was never disputed.
     */
    public function lastDiscrepancy(Transaction $t): ?array
    {
        if ($t->receipt_status === null) {
            return null;
        }
        $a = AuditLog::with('user:id,name')->where('event', 'transfer.receipt_disputed')->where('auditable_type', Transaction::class)
            ->where('auditable_id', $t->id)->latest('id')->first();

        return $a ? ['note' => $a->new_values['note'] ?? null, 'by' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            'at' => $a->created_at?->toIso8601String()] : null;
    }

    /** S2 / S3: the confirm dialog requires a note (review findings 3 and 4). */
    public function noteRequired(Transaction $t): bool
    {
        return $t->receipt_status === self::DISPUTED || ! app(BranchContext::class)->isScoped();
    }

    // ------------------------------------------------------------------ R14 in transit as at a date

    /**
     * R14 (accounting review MUST 1), on a query over `transactions as {$t}`: a between-branch transfer with a receipt,
     * dated <= D, not reversed on or before D, and open now or received after D. The one definition used by the
     * Period locks review, the Year-end preview and the Transfers report option "In transit at end date".
     */
    public function whereInTransit(Builder $q, string $date, string $t = 't'): Builder
    {
        return $q->whereIn("{$t}.type", self::TYPES)->whereNotNull("{$t}.receipt_status")
            ->whereDate("{$t}.txn_date", '<=', $date)
            ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('transactions as rv')->whereColumn('rv.reversal_of_id', "{$t}.id")
                ->where('rv.type', Transaction::REVERSAL)->where('rv.status', 'posted')->whereDate('rv.txn_date', '<=', $date))
            ->where(fn ($w) => $w->whereIn("{$t}.receipt_status", [self::AWAITING, self::DISPUTED, self::CANCELLED])
                ->orWhere(fn ($x) => $x->where("{$t}.receipt_status", self::RECEIVED)->whereDate("{$t}.received_on", '>', $date)));
    }

    /**
     * Count and amount in transit at D (review finding 2: amount too, disputed separately), sent or received by one of
     * $branchIds (null = every branch). Information only: it never blocks a lock or the close (R8).
     *
     * @param  int[]|null  $branchIds
     */
    public function inTransitSummary(string $date, ?array $branchIds = null): array
    {
        $q = $this->whereInTransit(DB::table('transactions as t'), $date);
        if ($branchIds !== null) {
            $q->where(fn ($w) => $w->whereIn('t.branch_id', $branchIds)->orWhereIn('t.related_branch_id', $branchIds));
        }
        $x = Money::sqlCents('t.amount');
        $disputed = "CASE WHEN t.receipt_status = '".self::DISPUTED."'";
        $r = $q->selectRaw("COUNT(*) as n, COALESCE(SUM($x), 0) as cents, COALESCE(SUM($disputed THEN 1 ELSE 0 END), 0) as dn,
            COALESCE(SUM($disputed THEN $x ELSE 0 END), 0) as dcents")->first();

        return [
            'transfers_awaiting_receipt' => (int) $r->n,
            'transfers_awaiting_receipt_amount' => Money::fromCents((int) $r->cents),
            'transfers_disputed' => (int) $r->dn,
            'transfers_disputed_amount' => Money::fromCents((int) $r->dcents),
        ];
    }

    // ------------------------------------------------------------------ S2 widget (GET /transfer-receipts)

    /** R9 / R10. @param  string[]  $states  subset of OPEN */
    public function widget(array $states, User $user): array
    {
        $ctx = app(BranchContext::class);
        $today = BusinessDate::today();
        $hasKey = $user->canDo('transfer.acknowledge');
        // The rows belong to the sending branch: read without the branch scope, limited here to the receiving branch.
        $open = fn () => Transaction::withoutGlobalScopes()->whereIn('type', self::TYPES)->where('status', 'posted');
        $incoming = $open()->whereIn('receipt_status', $states)
            ->when($ctx->isScoped(), fn ($q) => $q->where('related_branch_id', $ctx->branchId));

        $buckets = array_fill_keys(['0_1', '2_3', '4_7', '8_plus'], [0, 0]);
        foreach ((clone $incoming)->get(['id', 'txn_date', 'amount']) as $t) {
            $key = self::bucket(self::ageDays($t->txn_date->format('Y-m-d'), $today));
            $buckets[$key][0]++;
            $buckets[$key][1] += Money::toCents($t->amount);
        }

        $list = function ($q) use ($user, $today, $hasKey) {
            $total = (clone $q)->count();
            $rows = $q->with(['branch:id,code', 'fromAccount:id,name,branch_id', 'fromAccount.branch:id,code', 'toAccount:id,name,branch_id',
                'toAccount.branch:id,code', 'creator:id,name', 'receiptBy:id,name'])
                ->orderBy('txn_date')->orderBy('id')->limit(self::WIDGET_ROWS)->get();

            return ['data' => $rows->map(fn (Transaction $t) => $this->row($t, $user, $today, $hasKey))->all(), 'total' => $total];
        };
        $outgoing = $ctx->isScoped()
            ? $list($open()->where('receipt_status', self::DISPUTED)->where('branch_id', $ctx->branchId))
            : ['data' => [], 'total' => 0];

        return [
            'required' => app(Settings::class)->transferReceiptRequired(),
            'summary' => [
                'buckets' => array_map(fn ($k, $v) => ['key' => $k, 'count' => $v[0], 'amount' => Money::fromCents($v[1])], array_keys($buckets), $buckets),
                'count' => array_sum(array_column($buckets, 0)),
                'amount' => Money::fromCents(array_sum(array_column($buckets, 1))),
            ],
            'incoming' => $list($incoming),
            'outgoing_disputed' => $outgoing,
        ];
    }

    private function row(Transaction $t, User $user, string $today, bool $hasKey): array
    {
        return [
            'id' => $t->id,
            'ref_no' => $t->ref_no,
            'type' => $t->type,
            'txn_date' => $t->txn_date->format('Y-m-d'),
            'age_days' => self::ageDays($t->txn_date->format('Y-m-d'), $today),
            'from' => ['branch_code' => $t->fromAccount?->branch?->code, 'account' => $t->fromAccount?->name],
            'to' => ['branch_code' => $t->toAccount?->branch?->code, 'account' => $t->toAccount?->name],
            'amount' => Money::fromCents(Money::toCents($t->amount)),
            'receipt_status' => $t->receipt_status,
            'receipt_note' => $t->receipt_note,
            'receipt_by' => $t->receiptBy ? ['id' => $t->receiptBy->id, 'name' => $t->receiptBy->name] : null,
            'receipt_at' => $t->receipt_at?->toIso8601String(),
            'created_by' => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name] : null,
            'sent_by_you' => (int) $t->created_by === (int) $user->id,
            'can_acknowledge' => $this->canAcknowledge($t, $user, $hasKey),
            'note_required' => $this->noteRequired($t),
        ];
    }

    /** R9: calendar days in the business timezone (both dates are business dates). */
    public static function ageDays(string $txnDate, string $today): int
    {
        return max(0, (int) (new DateTimeImmutable($txnDate))->diff(new DateTimeImmutable($today))->format('%r%a'));
    }

    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 1 => '0_1',
            $days <= 3 => '2_3',
            $days <= 7 => '4_7',
            default => '8_plus',
        };
    }

    /** The `receipt` / `receipt[]` filter values (transaction list, Transfers report). */
    public static function filterRule(array $allowed): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($allowed) {
            if (! is_array($value) && ! is_string($value)) {
                $fail('Receipt must be a list of receipt statuses.');

                return;
            }
            foreach (is_array($value) ? $value : explode(',', $value) as $v) {
                if (! is_string($v)) {
                    $fail('Receipt must be a list of receipt statuses.');

                    return;
                }
                $v = trim($v);
                if ($v !== '' && ! in_array($v, $allowed, true)) {
                    $fail("Unknown receipt status: {$v}.");

                    return;
                }
            }
        };
    }
}
