<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AG-005 section 2 / R8: who must use two-factor sign-in when "Require two-factor sign-in for admins" is on.
 * An admin is an active user who is a Super Admin, or who holds an ACTIVE role in a branch they are a member of
 * (branch_user row) whose permissions include at least one of ADMIN_KEYS. Checked per person across every branch, not
 * per active branch (R18: model_has_roles with each membership's branch_id directly, like BranchAdmins).
 */
final class TwoFactorPolicy
{
    /**
     * Keys that let someone create logins or change access, plus the second person of the maker-checker controls
     * (accounting review 1). A later key that grants access or approval is added here only.
     */
    public const ADMIN_KEYS = ['user.create', 'user.edit', 'role.manage', 'transfer.approve', 'expense.approve'];

    public const PENDING_LIMIT = 50;

    public static function isAdmin(User $user): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        return $user->is_super_admin || self::adminRoleHolders()->where('mr.model_id', $user->id)->exists();
    }

    /** Setting on and isAdmin (R8). */
    public static function requiredFor(User $user): bool
    {
        return app(Settings::class)->twoFactorRequiredForAdmins() && self::isAdmin($user);
    }

    /**
     * S6 / R8: active admins without 2FA (inactive users are never pending), by name, at most 50 listed.
     *
     * @return array{count: int, users: list<array{id: int, name: string, email: string}>}
     */
    public static function pendingAdmins(): array
    {
        $q = DB::table('users')->where('users.status', 'active')->whereNull('users.two_factor_confirmed_at')
            ->where(fn ($w) => $w->where('users.is_super_admin', true)
                ->orWhereIn('users.id', self::adminRoleHolders()->select('mr.model_id')));
        $count = (clone $q)->count();
        $users = $q->orderBy('users.name')->orderBy('users.id')->limit(self::PENDING_LIMIT)->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email])->all();

        return ['count' => $count, 'users' => $users];
    }

    /** model_has_roles rows of a member of the role's branch, on an active role holding any admin key. */
    private static function adminRoleHolders(): Builder
    {
        return DB::table('model_has_roles as mr')
            ->join('branch_user as bu', fn ($j) => $j->on('bu.user_id', '=', 'mr.model_id')->on('bu.branch_id', '=', 'mr.branch_id'))
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', User::class)->where('r.status', 'active')
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('role_has_permissions as rp')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->whereColumn('rp.role_id', 'r.id')->whereIn('p.name', self::ADMIN_KEYS));
    }
}
