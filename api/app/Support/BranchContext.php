<?php

namespace App\Support;

/**
 * Holds the active branch for the current request.
 * branchId === null means "all branches" (Super Admin consolidated mode) or no branch selected.
 */
class BranchContext
{
    public ?int $branchId = null;

    public bool $allBranches = false;

    public function set(?int $branchId, bool $allBranches = false): void
    {
        $this->branchId = $branchId;
        $this->allBranches = $allBranches;
    }

    public function isScoped(): bool
    {
        return $this->branchId !== null;
    }
}
