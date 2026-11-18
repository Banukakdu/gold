<?php

namespace App\Models\Concerns;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts queries to the active branch so a forgotten filter can never leak another branch's data.
 */
trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $ctx = app(BranchContext::class);
            if ($ctx->isScoped()) {
                $query->where($query->getModel()->getTable().'.branch_id', $ctx->branchId);
            }
        });
    }
}
