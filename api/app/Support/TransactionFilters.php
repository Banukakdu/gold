<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Transaction;

/** Validated filters of the transaction list and its export, with the scope set resolved from BranchContext (AG-065 R10). */
final class TransactionFilters
{
    /**
     * @param  array<string, mixed>  $filters  non-empty filter values as given (branches as ints)
     * @param  int[]|null  $scope  branch ids in scope; null = every branch (All mode, no branch filter)
     */
    public function __construct(
        public readonly array $filters,
        public readonly string $sort,
        public readonly string $dir,
        public readonly ?array $scope,
        public readonly bool $allMode,
        public readonly ?int $activeBranchId,
        public readonly int $perPage = 25,
        public readonly int $page = 1,
    ) {}

    public function get(string $key): mixed
    {
        return $this->filters[$key] ?? null;
    }

    /** The resolved scope set as ids, ascending (every branch when not narrowed). */
    public function scopeIds(): array
    {
        $ids = $this->scope ?? Branch::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
        sort($ids);

        return array_values($ids);
    }

    /** R10: a row whose sending branch is not in the scope set was received by a branch in it. */
    public function isIncoming(Transaction $t): bool
    {
        return $this->scope !== null && ! in_array((int) $t->branch_id, $this->scope, true);
    }

    /**
     * Export Direction column (accounting review finding 6, sign-off C1): the way the money moved for the branches in
     * scope. The sender is branch_id and the receiver related_branch_id, swapped on a reversal row (it sends the money
     * back). In = only the receiver is in scope, Out = only the sender is, null = no other branch (income, expense,
     * capital, internal transfer, year-end close) or both sides in scope (All mode without branches[]).
     */
    public function direction(Transaction $t): ?string
    {
        if ($this->scope === null || $t->related_branch_id === null) {
            return null;
        }
        [$sender, $receiver] = [(int) $t->branch_id, (int) $t->related_branch_id];
        if ($t->type === Transaction::REVERSAL) {
            [$sender, $receiver] = [$receiver, $sender];
        }
        $senderIn = in_array($sender, $this->scope, true);
        $receiverIn = in_array($receiver, $this->scope, true);

        return match (true) {
            $receiverIn && ! $senderIn => 'In',
            $senderIn && ! $receiverIn => 'Out',
            default => null,
        };
    }

    /** Void / Reverse / Edit / Files follow transactions.branch_id (double-entry R10). */
    public function inActiveBranch(Transaction $t): bool
    {
        return $this->allMode || (int) $t->branch_id === $this->activeBranchId;
    }

    /** Accounting review finding 4: the filters as given plus sort, dir and the resolved scope. */
    public function auditParams(): array
    {
        return $this->filters + ['sort' => $this->sort, 'dir' => $this->dir, 'scope_branch_ids' => $this->scopeIds()];
    }

    /** Branch of the audit row: the active branch, or the single branches[] id in All mode, else null. */
    public function auditBranchId(): ?int
    {
        if (! $this->allMode) {
            return $this->activeBranchId;
        }

        return $this->scope !== null && count($this->scope) === 1 ? $this->scope[0] : null;
    }
}
