<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Branch memberships with the role held in each branch (active or not). */
class Memberships
{
    /**
     * @param  iterable<int>  $userIds
     * @param  int|null  $onlyBranch  limit to one branch (B1: non-Super Admins see only the active branch)
     * @return Collection<int, Collection> keyed by user id
     */
    public static function forUsers(iterable $userIds, ?int $onlyBranch = null): Collection
    {
        return DB::table('branch_user as bu')
            ->join('branches as b', 'b.id', '=', 'bu.branch_id')
            ->leftJoin('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'bu.user_id')
                ->on('mr.branch_id', '=', 'bu.branch_id')->where('mr.model_type', User::class))
            ->leftJoin('roles as r', 'r.id', '=', 'mr.role_id')
            ->whereIn('bu.user_id', collect($userIds)->all())
            ->when($onlyBranch, fn ($q, $b) => $q->where('bu.branch_id', $b))
            ->orderByDesc('b.is_main')->orderBy('b.code')
            ->get(['bu.user_id', 'b.id as branch_id', 'b.code as branch_code', 'b.name as branch_name', 'r.id as role_id', 'r.name as role_name', 'r.status as role_status'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'branch_id' => (int) $r->branch_id, 'branch_code' => $r->branch_code, 'branch_name' => $r->branch_name,
                'role_id' => $r->role_id ? (int) $r->role_id : null, 'role_name' => $r->role_name, 'role_status' => $r->role_status,
            ])->values());
    }
}
