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
     * Accounting review finding 6 (export Direction column): In = received from outside the scope set, Out = sent to a
     * branch outside it, null = no other branch (income, expense, capital, internal) or both sides in scope.
     */
    public function direction(Transaction $t): ?string
    {
        if ($this->isIncoming($t)) {
            return 'In';
        }
        $related = $t->related_branch_id === null ? null : (int) $t->related_branch_id;
        if ($this->scope !== null && $related !== null && $related !== (int) $t->branch_id && ! in_array($related, $this->scope, true)) {
            return 'Out';
        }

        return null;
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
