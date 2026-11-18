<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles are per branch (spatie team = branch). Roles with branch_id NULL are global templates (Super Admin).
 */
class RoleController extends Controller
{
    public function permissions()
    {
        return Permission::orderBy('name')->pluck('name')
            ->groupBy(fn ($p) => explode('.', $p)[0]);
    }

    public function index()
    {
        $branchId = app(BranchContext::class)->branchId;

        return Role::with('permissions:id,name')
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id is null desc')->orderBy('name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'branch_id' => $r->branch_id, 'is_template' => $r->branch_id === null,
                'permissions' => $r->permissions->pluck('name')]);
    }

    public function store(Request $request)
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null && ! $request->user()->is_super_admin, 403);
        $data = $request->validate(['name' => 'required|string|max:60', 'permissions' => 'array', 'permissions.*' => 'string|exists:permissions,name']);
        abort_if(Role::where('name', $data['name'])->where('branch_id', $branchId)->exists(), 422, 'A role with this name already exists in this branch.');
        $this->assertCanGrant($request, $data['permissions'] ?? []);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'branch_id' => $branchId]);
        $role->syncPermissions($data['permissions'] ?? []);
        AuditLog::record('role.created', $role, null, $data, $branchId);

        return response()->json($role->load('permissions:id,name'), 201);
    }

    public function update(Request $request, Role $role)
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_unless($role->branch_id === $branchId || ($role->branch_id === null && $request->user()->is_super_admin), 403, 'This role belongs to another branch.');
        $data = $request->validate(['name' => 'sometimes|string|max:60', 'permissions' => 'array', 'permissions.*' => 'string|exists:permissions,name']);
        $this->assertCanGrant($request, $data['permissions'] ?? []);
        $old = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')];
        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }
        AuditLog::record('role.updated', $role, $old, $data, $branchId);

        return $role->load('permissions:id,name');
    }

    /** No privilege escalation: you can only grant permissions you hold. */
    private function assertCanGrant(Request $request, array $perms): void
    {
        $user = $request->user();
        if ($user->is_super_admin) {
            return;
        }
        $missing = array_diff($perms, $user->getAllPermissions()->pluck('name')->all());
        abort_if($missing, 403, 'You cannot grant permissions you do not have: '.implode(', ', $missing));
    }
}
