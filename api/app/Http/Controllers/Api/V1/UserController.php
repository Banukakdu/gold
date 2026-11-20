<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\AccessAlerts;
use App\Support\BranchAdmins;
use App\Support\BranchContext;
use App\Support\Memberships;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Users, branch memberships and per-branch roles (AG-020, AG-021, AG-024).
 * Users are global; non-Super Admins only see and change members of the active branch (B1).
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $me = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        $f = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,inactive']);

        $q = User::query()->orderBy('name')
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->when($f['q'] ?? null, function ($q, $v) {
                // Escape LIKE wildcards; '!' works as escape character on MySQL and SQLite.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v).'%';
                $q->where(fn ($w) => $w->whereRaw("name like ? escape '!'", [$like])->orWhereRaw("email like ? escape '!'", [mb_strtolower($like)]));
            })
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        $page = $q->paginate(50);
        $memberships = Memberships::forUsers($page->getCollection()->pluck('id'), $me->is_super_admin ? null : $branchId);

        return $page->through(fn (User $u) => $this->present($u, $memberships->get($u->id, collect()), $branchId));
    }

    /** N1 */
    public function show(Request $request, User $user)
    {
        $me = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        $this->assertVisible($me, $user, $branchId);
        $memberships = Memberships::forUsers([$user->id], $me->is_super_admin ? null : $branchId);

        // Spec 4.3: the UI disables profile editing up front, with the reason (B5 / B6 / B2).
        return $this->present($user, $memberships->get($user->id, collect()), $branchId)
            + ['profile_locked_reason' => $this->profileLockReason($me, $user)];
    }

    /** Create a user in the active branch with a role and send the invitation (AG-020 / AG-021, E2). */
    public function store(Request $request)
    {
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null, 400, 'Select the branch the user will belong to.');
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'password' => ['sometimes', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', function ($attr, $value, $fail) {
                // Accounting review finding 2: nobody sets another person's password outside local/testing.
                if (! app()->environment(['local', 'testing'])) {
                    $fail('Passwords are set by the user through the invitation.');
                }
            }],
            'role_id' => 'required|integer',
        ]);
        $data['email'] = mb_strtolower($data['email']);
        if ($existing = User::whereRaw('lower(email) = ?', [$data['email']])->first()) {
            return response()->json([
                'message' => 'This email already has an account.',
                'errors' => ['email' => ['This email already has an account.']],
                'existing_user_id' => $request->user()->is_super_admin ? $existing->id : null,
            ], 422);
        }
        $role = $this->assignableRole($request, $data['role_id'], $branchId);
        $invite = ! array_key_exists('password', $data);

        $user = DB::transaction(function () use ($data, $role, $branchId, $invite) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'status' => 'active',
                // E2: without a password the account gets a random one nobody knows until the invitation is used.
                'password' => $invite ? Str::password(48) : $data['password'],
            ]);
            $user->branches()->attach($branchId, ['is_default' => true]);
            setPermissionsTeamId($branchId);
            $user->assignRole($role);
            AuditLog::record('user.created', $user, null, ['email' => $user->email, 'name' => $user->name, 'branch_id' => $branchId] + $role->snapshot(), $branchId);

            return $user;
        });
        if ($invite) {
            // Audit first: a mail failure must never lose the record (mail is queued and failures are reported).
            AuditLog::record('user.invited', $user, null, ['user_id' => $user->id, 'email' => $user->email, 'branch_id' => $branchId] + $role->snapshot(), $branchId);
            $this->sendInvitation($user);
        }
        AccessAlerts::notify('user.created', $branchId, "{$user->name} <{$user->email}>", null, $role->snapshot());

        return response()->json($user, 201);
    }

    /** Add an existing user to the active branch, or change their role there (AG-020, E3, B3). */
    public function assignRole(Request $request, User $user)
    {
        $me = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null, 400, 'Select a branch.');
        $this->assertNotSelf($me, $user);
        $this->assertSuperAdminTarget($me, $user);
        if (! $me->is_super_admin) {
            // Q4: a Branch Admin cannot pull in users from other branches.
            abort_unless($user->isMemberOf($branchId), 404, 'User not found.');
        }
        $role = $this->assignableRole($request, $request->validate(['role_id' => 'required|integer'])['role_id'], $branchId);
        $this->assertTargetNotAbove($me, $user, $branchId);
        $old = $user->roleInBranch($branchId)?->snapshot();
        $new = $role->snapshot();
        DB::transaction(function () use ($user, $branchId, $role, $old, $new) {
            BranchAdmins::assertNotLast($branchId, [$user->id], Role::grantsAdmin($new['permissions']) ? [$user->id] : []);
            if (! $user->isMemberOf($branchId)) {
                $user->branches()->attach($branchId, ['is_default' => ! $user->branches()->wherePivot('is_default', true)->exists()]);
            }
            setPermissionsTeamId($branchId);
            $user->unsetRelation('roles');
            $user->syncRoles([$role]);
            AuditLog::record('user.role_assigned', $user, $old, $new, $branchId);
        });
        AccessAlerts::notify('user.role_assigned', $branchId, "{$user->name} <{$user->email}>", $old, $new);

        return ['ok' => true];
    }

    /** N2: remove the membership of the active branch; the user record and their entries stay. */
    public function removeFromBranch(Request $request, User $user)
    {
        $me = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        abort_if($branchId === null, 400, 'Select a branch.');
        $this->assertNotSelf($me, $user);
        $this->assertSuperAdminTarget($me, $user);
        abort_unless($user->isMemberOf($branchId), 404, 'User not found.');
        $this->assertTargetNotAbove($me, $user, $branchId);

        $role = $user->roleInBranch($branchId);
        DB::transaction(function () use ($user, $branchId, $role) {
            BranchAdmins::assertNotLast($branchId, [$user->id]);
            $user->branches()->detach($branchId);
            // Keep a default branch: promote another membership when the default one was removed.
            if (! $user->branches()->wherePivot('is_default', true)->exists() && ($next = $user->branches()->orderBy('branches.id')->first())) {
                $user->branches()->updateExistingPivot($next->id, ['is_default' => true]);
            }
            foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                DB::table($table)->where('model_type', User::class)->where('model_id', $user->id)->where('branch_id', $branchId)->delete();
            }
            AuditLog::record('user.removed_from_branch', $user, $role ? $role->snapshot() : ['role_id' => null, 'role_name' => null, 'permissions' => []], null, $branchId);
        });
        AccessAlerts::notify('user.removed_from_branch', $branchId, "{$user->name} <{$user->email}>", $role?->snapshot(), null);

        return ['ok' => true];
    }

    /** N4 */
    public function resendInvite(Request $request, User $user)
    {
        $me = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        $this->assertSuperAdminTarget($me, $user);
        $this->assertVisible($me, $user, $branchId);
        $this->assertManagesEverywhere($me, $user);
        if ($user->last_login_at !== null) {
            throw ValidationException::withMessages(['user' => 'This user has already logged in; they can use their password.']);
        }
        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['user' => 'This user is inactive. Activate them first.']);
        }
        AuditLog::record('user.invite_resent', $user, null, ['user_id' => $user->id, 'email' => $user->email, 'branch_id' => $branchId], $branchId);
        $this->sendInvitation($user);
        AccessAlerts::notify('user.invite_resent', $branchId, "{$user->name} <{$user->email}>", null, ['email' => $user->email]);

        return ['ok' => true];
    }

    /** E4: global profile fields and status (B5, B6, B7, B8, B9). */
    public function update(Request $request, User $user)
    {
        $me = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        $this->assertSuperAdminTarget($me, $user);
        $this->assertVisible($me, $user, $branchId);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'status' => 'sometimes|in:active,inactive',
        ]);
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
            if (User::whereRaw('lower(email) = ?', [$data['email']])->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This email already has an account.']);
            }
        }
        $this->assertManagesEverywhere($me, $user);
        $deactivating = ($data['status'] ?? $user->status) === 'inactive' && $user->status === 'active';
        if (isset($data['status']) && $data['status'] !== $user->status) {
            $this->assertNotSelf($me, $user);
        }

        $old = $user->only(array_keys($data));
        DB::transaction(function () use ($user, $data, $deactivating, $old, $branchId) {
            if ($deactivating) {
                foreach (BranchAdmins::branchesOf($user) as $b) {
                    BranchAdmins::assertNotLast($b, [$user->id]);
                }
            }
            $user->update($data);
            if ($deactivating) {
                $user->tokens()->delete(); // AG-024: immediate lock-out
            }
            AuditLog::record('user.updated', $user, $old, $data, $branchId);
        });
        // Accountant follow-up: email/status changes by a Branch Admin are sensitive (e.g. redirecting an invitation).
        $sensitive = array_intersect_key($data, array_flip(['email', 'status']));
        $changed = array_filter($sensitive, fn ($v, $k) => ($old[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            AccessAlerts::notify('user.updated', $branchId, "{$user->name} <{$user->email}>", array_intersect_key($old, $changed), $changed);
        }

        return $user->fresh();
    }

    private function present(User $u, $memberships, ?int $branchId): array
    {
        $row = $u->toArray() + ['memberships' => $memberships->values()];
        if ($branchId) {
            $row['roles_in_branch'] = $memberships->where('branch_id', $branchId)->where('role_status', 'active')->pluck('role_name')->values();
        }

        return $row;
    }

    /** Queued after commit; a mail failure is reported, never turned into a failed request. */
    private function sendInvitation(User $user): void
    {
        try {
            $user->notify(new InvitationNotification(Password::broker()->createToken($user)));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * B5 + B2 for global changes (profile, status, invitation): a non-Super Admin must hold user.edit in
     * every branch of the target, and the target must not hold permissions the actor lacks in any of them.
     */
    private function assertManagesEverywhere(User $me, User $user): void
    {
        if ($reason = $this->profileLockReason($me, $user)) {
            abort(403, $reason);
        }
    }

    private function profileLockReason(User $me, User $user): ?string
    {
        if ($me->is_super_admin) {
            return null;
        }
        if ($user->is_super_admin) {
            return 'Only a Super Admin can change a Super Admin.';
        }
        foreach ($user->branches()->pluck('branches.id') as $b) {
            $mine = $me->isMemberOf((int) $b) ? $me->permissionKeysIn((int) $b) : [];
            if (! in_array('user.edit', $mine, true)) {
                return 'This user also works in other branches; ask a Super Admin.';
            }
            $theirs = $user->roleInBranch((int) $b)?->permissionKeys() ?? [];
            if (array_diff($theirs, $mine)) {
                return 'You cannot change a user with more permissions than you.';
            }
        }

        return null;
    }

    /** B1: non-Super Admins only reach members of the active branch. */
    private function assertVisible(User $me, User $user, ?int $branchId): void
    {
        if (! $me->is_super_admin) {
            abort_unless($branchId && $user->isMemberOf($branchId), 404, 'User not found.');
        }
    }

    /** B6 */
    private function assertSuperAdminTarget(User $me, User $user): void
    {
        abort_if($user->is_super_admin && ! $me->is_super_admin, 403, 'Only a Super Admin can change a Super Admin.');
    }

    /** B8 */
    private function assertNotSelf(User $me, User $user): void
    {
        if ($me->id === $user->id) {
            throw ValidationException::withMessages(['access' => 'You cannot change your own access.']);
        }
    }

    /** B2 / E3: a non-Super Admin cannot change the access of someone holding permissions they lack. */
    private function assertTargetNotAbove(User $me, User $user, int $branchId): void
    {
        if ($me->is_super_admin || ! ($current = $user->roleInBranch($branchId))) {
            return;
        }
        $missing = array_diff($current->permissionKeys(), $me->permissionKeysIn($branchId));
        abort_if($missing, 403, 'You cannot change the role of a user with more permissions than you.');
    }

    /** E3: an active role of the active branch, holding no permission the actor lacks. */
    private function assignableRole(Request $request, int $roleId, int $branchId): Role
    {
        $role = Role::find($roleId);
        if (! $role || $role->isTemplate()) {
            throw ValidationException::withMessages(['role_id' => 'Pick a role of this branch.']);
        }
        if ($role->branch_id !== $branchId) {
            throw ValidationException::withMessages(['role_id' => 'Role belongs to another branch.']);
        }
        if (! $role->isActive()) {
            throw ValidationException::withMessages(['role_id' => 'This role is inactive.']);
        }
        $me = $request->user();
        if (! $me->is_super_admin) {
            $missing = array_diff($role->permissionKeys(), $me->permissionKeysIn($branchId));
            abort_if($missing, 403, 'You cannot assign a role with more permissions than you have: '.implode(', ', $missing));
        }

        return $role;
    }
}
