<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\AccessAlerts;
use App\Support\BranchAdmins;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles are per branch (spatie team = branch). Roles with branch_id NULL are global templates (Super Admin).
 * Roles are deactivated, never deleted (AG-022).
 */
class RoleController extends Controller
{
    public function permissions()
    {
        return Permission::orderBy('name')->pluck('name')
            ->groupBy(fn ($p) => explode('.', $p)[0]);
    }

    /** E5: branch roles + templates (branch mode) or templates only (All branches). */
    public function index()
    {
        $branchId = app(BranchContext::class)->branchId;

        return Role::with('permissions:id,name')
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id is null desc')->orderBy('name')->get()
            ->map(fn (Role $r) => $this->present($r));
    }

    public function store(Request $request)
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null && ! $request->user()->is_super_admin, 403);
        $data = $request->validate(['name' => 'required|string|max:60', 'permissions' => 'array', 'permissions.*' => 'string|distinct|exists:permissions,name']);
        $this->assertUniqueName($data['name'], $branchId);
        $perms = $data['permissions'] ?? [];
        $this->assertCanGrant($request, $perms, $branchId);

        $role = DB::transaction(function () use ($data, $perms, $branchId) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'branch_id' => $branchId, 'status' => 'active']);
            $role->syncPermissions($perms);
            AuditLog::record('role.created', $role, null, $this->state($role), $branchId);

            return $role;
        });
        AccessAlerts::notify('role.created', $branchId, "role {$role->name}", null, $this->state($role));

        return response()->json($this->present($role->load('permissions:id,name')), 201);
    }

    /** E6: rename, change permissions, deactivate / activate. */
    public function update(Request $request, Role $role)
    {
        $me = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        if ($role->isTemplate()) {
            abort_unless($me->is_super_admin, 403, 'Only a Super Admin can edit role templates.');
        } else {
            abort_unless($role->branch_id === $branchId, 403, 'This role belongs to another branch.');
        }
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:60',
            'permissions' => 'sometimes|array', 'permissions.*' => 'string|distinct|exists:permissions,name',
            'status' => 'sometimes|in:active,inactive',
        ]);
        if (isset($data['name']) && mb_strtolower($data['name']) !== mb_strtolower($role->name)) {
            $this->assertUniqueName($data['name'], $role->branch_id, $role->id);
        }
        if (! $me->is_super_admin) {
            $mine = $me->permissionKeysIn($branchId);
            $missing = array_diff($role->permissionKeys(), $mine);
            abort_if($missing, 403, 'You cannot edit a role that has permissions you do not have: '.implode(', ', $missing));
        }
        $this->assertCanGrant($request, $data['permissions'] ?? [], $branchId);

        $old = $this->state($role);
        $newPerms = array_key_exists('permissions', $data) ? array_values(array_unique($data['permissions'])) : $old['permissions'];
        $newStatus = $data['status'] ?? $role->status;
        $statusChanged = $newStatus !== $role->status;
        DB::transaction(function () use ($role, $data, $old, $statusChanged, $branchId, $newPerms, $newStatus) {
            // B7: the role stops making its holders branch administrators (checked under lock).
            if (! $role->isTemplate() && Role::grantsAdmin($old['permissions'], $old['status']) && ! Role::grantsAdmin($newPerms, $newStatus)) {
                BranchAdmins::assertNotLast($role->branch_id, $role->activeHolderIds());
            }
            $holders = $role->activeHolderIds();
            $role->update(array_intersect_key($data, array_flip(['name', 'status'])));
            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions']);
            }
            $new = $this->state($role->fresh());
            if ($statusChanged) {
                $event = $role->status === 'inactive' ? 'role.deactivated' : 'role.activated';
                AuditLog::record($event, $role, $old + ['holders' => $holders], $new + ['holders' => $holders], $role->branch_id ?? $branchId);
            } else {
                AuditLog::record('role.updated', $role, $old, $new, $role->branch_id ?? $branchId);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $new = $this->state($role->fresh());
        if ($old['permissions'] !== $new['permissions']) {
            AccessAlerts::notify('role.updated', $role->branch_id, "role {$role->name}", $old, $new);
        }

        return $this->present($role->fresh()->load('permissions:id,name'));
    }

    /** N3: copy the global templates into the active branch; existing names are skipped (AG-023). */
    public function copyTemplates()
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null, 400, 'Select the branch to copy the templates into.');
        $created = [];
        $skipped = [];
        DB::transaction(function () use ($branchId, &$created, &$skipped) {
            foreach (Role::whereNull('branch_id')->orderBy('id')->get() as $tpl) {
                if ($this->nameTaken($tpl->name, $branchId)) {
                    $skipped[] = $tpl->name;

                    continue;
                }
                $perms = $tpl->permissionKeys();
                $copy = Role::create(['name' => $tpl->name, 'guard_name' => 'web', 'branch_id' => $branchId, 'status' => 'active']);
                $copy->syncPermissions($perms);
                AuditLog::record('role.copied_from_templates', $copy, null, ['template_id' => $tpl->id, 'name' => $copy->name, 'permissions' => $perms], $branchId);
                $created[] = $copy->name;
            }
        });

        return response()->json(['created' => $created, 'skipped' => $skipped], 201);
    }

    private function present(Role $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'branch_id' => $r->branch_id, 'is_template' => $r->isTemplate(),
            'status' => $r->status ?? 'active', 'users_count' => count($r->activeHolderIds()),
            'permissions' => $r->permissions->pluck('name')->sort()->values(),
        ];
    }

    /** Audit snapshot (accounting review finding 1). */
    private function state(Role $role): array
    {
        return ['name' => $role->name, 'status' => $role->status ?? 'active', 'permissions' => $role->permissionKeys()];
    }

    private function nameTaken(string $name, ?int $branchId, ?int $exceptId = null): bool
    {
        return Role::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /** B11 / E7: names are unique per branch (templates among templates), trimmed, case-insensitive. */
    private function assertUniqueName(string $name, ?int $branchId, ?int $exceptId = null): void
    {
        if ($this->nameTaken($name, $branchId, $exceptId)) {
            throw ValidationException::withMessages(['name' => $branchId ? 'A role with this name already exists in this branch.' : 'A template with this name already exists.']);
        }
    }

    /** No privilege escalation: you can only grant permissions you hold in the active branch. */
    private function assertCanGrant(Request $request, array $perms, ?int $branchId): void
    {
        $user = $request->user();
        if ($user->is_super_admin) {
            return;
        }
        $missing = array_values(array_diff($perms, $user->permissionKeysIn($branchId)));
        abort_if($missing, 403, 'You cannot grant permissions you do not have: '.implode(', ', $missing));
    }
}
