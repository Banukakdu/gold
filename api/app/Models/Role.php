<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Per-branch role (spatie team = branch). branch_id NULL = global template.
 * Inactive roles stay assigned but grant nothing (see User::roles()).
 */
class Role extends SpatieRole
{
    /** A role with both keys makes its holders branch administrators (last-admin rule B7). */
    public const ADMIN_KEYS = ['user.edit', 'role.manage'];

    protected $attributes = ['status' => 'active'];

    /**
     * Spatie treats a template (branch_id NULL) as a clash for every branch, which would make
     * "Copy role templates" impossible. Names only clash within the same branch (or among templates).
     */
    public static function create(array $attributes = [])
    {
        $attributes['guard_name'] ??= 'web';
        if (! array_key_exists('branch_id', $attributes)) {
            $attributes['branch_id'] = getPermissionsTeamId();
        }
        $exists = static::query()->where('name', $attributes['name'])->where('guard_name', $attributes['guard_name'])
            ->when($attributes['branch_id'], fn ($q, $b) => $q->where('branch_id', $b), fn ($q) => $q->whereNull('branch_id'))
            ->exists();
        if ($exists) {
            throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        return static::query()->create($attributes);
    }

    public function isTemplate(): bool
    {
        return $this->branch_id === null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return list<string> sorted permission keys */
    public function permissionKeys(): array
    {
        return $this->permissions()->pluck('name')->sort()->values()->all();
    }

    /** Old/new snapshot used in audit rows. */
    public function snapshot(): array
    {
        return ['role_id' => $this->id, 'role_name' => $this->name, 'permissions' => $this->permissionKeys()];
    }

    public static function grantsAdmin(array $permissionKeys, string $status = 'active'): bool
    {
        return $status === 'active' && ! array_diff(self::ADMIN_KEYS, $permissionKeys);
    }

    /** Ids of active users holding this role in its branch (members of that branch). */
    public function activeHolderIds(): array
    {
        if ($this->branch_id === null) {
            return [];
        }

        return DB::table('model_has_roles as mr')
            ->join('users', 'users.id', '=', 'mr.model_id')
            ->join('branch_user as bu', fn ($j) => $j->on('bu.user_id', '=', 'users.id')->on('bu.branch_id', '=', 'mr.branch_id'))
            ->where('mr.model_type', User::class)->where('mr.role_id', $this->id)->where('mr.branch_id', $this->branch_id)
            ->where('users.status', 'active')
            ->orderBy('users.id')->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
