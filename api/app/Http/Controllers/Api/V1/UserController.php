<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $branchId = app(BranchContext::class)->branchId;
        $q = User::with('branches:id,code,name')->orderBy('name');
        if ($branchId) {
            $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
        }

        return $q->paginate(50)->through(function (User $u) use ($branchId) {
            if ($branchId) {
                setPermissionsTeamId($branchId);
                $u->unsetRelation('roles');
                $u->setAttribute('roles_in_branch', $u->getRoleNames());
            }

            return $u;
        });
    }

    /** Create a user in the active branch with a role (AG-020 / AG-021). */
    public function store(Request $request)
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null, 400, 'Select the branch the user will belong to.');
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'role_id' => 'required|integer',
        ]);
        $role = $this->assignableRole($request, $data['role_id'], $branchId);

        return DB::transaction(function () use ($data, $role, $branchId) {
            $user = User::create($data + ['status' => 'active']);
            $user->branches()->attach($branchId, ['is_default' => true]);
            setPermissionsTeamId($branchId);
            $user->assignRole($role);
            AuditLog::record('user.created', $user, null, ['email' => $user->email, 'role' => $role->name], $branchId);

            return response()->json($user, 201);
        });
    }

    /** Add an existing user to the active branch, or change their role there (AG-020). */
    public function assignRole(Request $request, User $user)
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null, 400, 'Select a branch.');
        $role = $this->assignableRole($request, $request->validate(['role_id' => 'required|integer'])['role_id'], $branchId);

        $user->branches()->syncWithoutDetaching([$branchId]);
        setPermissionsTeamId($branchId);
        $user->unsetRelation('roles');
        $user->syncRoles([$role]);
        AuditLog::record('user.role_assigned', $user, null, ['branch_id' => $branchId, 'role' => $role->name], $branchId);

        return ['ok' => true];
    }

    public function update(Request $request, User $user)
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_unless($request->user()->is_super_admin || $user->branches()->where('branches.id', $branchId)->exists(), 403);
        abort_if($user->is_super_admin && ! $request->user()->is_super_admin, 403);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
            'status' => 'sometimes|in:active,inactive',
        ]);
        $old = $user->only(array_keys($data));
        $user->update($data);
        if (($data['status'] ?? null) === 'inactive') {
            $user->tokens()->delete(); // AG-024: immediate lock-out
        }
        AuditLog::record('user.updated', $user, $old, $data, $branchId);

        return $user;
    }

    private function assignableRole(Request $request, int $roleId, int $branchId): Role
    {
        $role = Role::with('permissions')->findOrFail($roleId);
        abort_unless($role->branch_id === null || $role->branch_id === $branchId, 422, 'Role belongs to another branch.');
        $me = $request->user();
        if (! $me->is_super_admin) {
            $missing = $role->permissions->pluck('name')->diff($me->getAllPermissions()->pluck('name'));
            abort_if($missing->isNotEmpty(), 403, 'You cannot assign a role with more permissions than you have.');
        }

        return $role;
    }
}
