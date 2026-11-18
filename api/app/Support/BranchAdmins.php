<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B7: a branch administrator is an active member holding an active role of that branch
 * with both user.edit and role.manage. Super Admin is not counted.
 */
class BranchAdmins
{
    /** @return list<int> */
    public static function ids(int $branchId): array
    {
        $adminRoleIds = DB::table('role_has_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->where('r.status', 'active')->whereIn('p.name', Role::ADMIN_KEYS)
            ->groupBy('rp.role_id')->havingRaw('count(distinct p.name) = ?', [count(Role::ADMIN_KEYS)])
            ->pluck('rp.role_id');

        // Lock the counted assignments (and joined users) so two concurrent changes cannot both pass.
        // Callers run this inside their DB transaction; SQLite ignores the lock.

        return DB::table('model_has_roles as mr')
            ->join('users', 'users.id', '=', 'mr.model_id')
            ->join('branch_user as bu', fn ($j) => $j->on('bu.user_id', '=', 'users.id')->on('bu.branch_id', '=', 'mr.branch_id'))
            ->where('mr.model_type', User::class)->where('mr.branch_id', $branchId)->whereIn('mr.role_id', $adminRoleIds)
            ->where('users.status', 'active')->where('users.is_super_admin', false)
            ->lockForUpdate()->pluck('users.id')->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    /** Branch ids where the user is currently a branch administrator. */
    public static function branchesOf(User $user): array
    {
        return $user->branches()->pluck('branches.id')
            ->filter(fn ($b) => in_array($user->id, self::ids((int) $b), true))->values()->all();
    }

    /**
     * Reject a change that would leave the branch with zero administrators.
     * Does not block when the branch already had none. Call inside the transaction that makes the change.
     */
    public static function assertNotLast(int $branchId, array $losing, array $gaining = []): void
    {
        // Serialise all access changes within this branch: lock the branch row first.
        Branch::whereKey($branchId)->lockForUpdate()->first();
        $before = self::ids($branchId);
        if (! $before) {
            return;
        }
        $after = array_unique(array_merge(array_diff($before, $losing), $gaining));
        if (! $after) {
            $name = Branch::find($branchId)?->name ?? 'this branch';
            throw ValidationException::withMessages(['access' => "This is the last Branch Admin of {$name}. Give another user that role first."]);
        }
    }
}
