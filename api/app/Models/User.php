<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles { roles as allRolesInTeam; }

    protected $fillable = ['name', 'email', 'phone', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            // AG-094 R7: the approval-request email (only this email); changed through PUT /notifications/preferences.
            'approval_request_email' => 'boolean',
        ];
    }

    /** B11: emails are stored lower-case. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => $v === null ? null : mb_strtolower(trim($v)));
    }

    /**
     * Roles in the current team (branch) that grant permissions: inactive roles are ignored (B10).
     * Spatie's permission checks all go through this relation.
     */
    public function roles(): BelongsToMany
    {
        return $this->allRolesInTeam()->where('roles.status', 'active');
    }

    /**
     * Sign-in lookup (reviewer R3). MySQL's accent-insensitive collation would match "ú@x.com" to "u@x.com", so a
     * typed variant could reach an account while the rate limits (keyed on the typed text) count it separately.
     * The row must equal the typed email byte for byte (emails are stored lower-case, B11); anything else is
     * treated as an unknown email. The same rule on SQLite and MySQL, for login, reset, set-password and the console.
     */
    public static function findByEmail(string $email): ?self
    {
        $email = mb_strtolower(trim($email));
        // lower(?) on both sides mirrors how the column collation compares (and lets the SQLite tests fold accents).
        $user = static::whereRaw('lower(email) = lower(?)', [$email])->first();

        return $user && hash_equals(mb_strtolower((string) $user->email), $email) ? $user : null;
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('is_default')->withTimestamps();
    }

    public function belongsToBranch(int $branchId): bool
    {
        return $this->is_super_admin || $this->isMemberOf($branchId);
    }

    /** Real membership (branch_user row), ignoring the Super Admin flag. */
    public function isMemberOf(int $branchId): bool
    {
        return $this->branches()->where('branches.id', $branchId)->exists();
    }

    /** Permission check within the currently active branch (spatie team). */
    public function canDo(string $permission): bool
    {
        return $this->is_super_admin || $this->hasPermissionTo($permission);
    }

    /** The user's role in a branch, active or not (one role per branch). */
    public function roleInBranch(int $branchId): ?Role
    {
        $roleId = DB::table('model_has_roles')->where('model_type', self::class)
            ->where('model_id', $this->id)->where('branch_id', $branchId)->value('role_id');

        return $roleId ? Role::find($roleId) : null;
    }

    /** Effective permission keys in a branch (does not change the active team). */
    public function permissionKeysIn(int $branchId): array
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($branchId);
        $this->unsetRelation('roles')->unsetRelation('permissions');
        $keys = $this->getAllPermissions()->pluck('name')->unique()->values()->all();
        setPermissionsTeamId($previous);
        $this->unsetRelation('roles')->unsetRelation('permissions');

        return $keys;
    }
}
