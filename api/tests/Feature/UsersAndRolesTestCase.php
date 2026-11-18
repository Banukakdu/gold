<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/** Shared helpers for the users-and-roles spec (docs/specs/users-and-roles.md). */
abstract class UsersAndRolesTestCase extends AccountingTestCase
{
    protected Branch $kdy;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->kdy = $this->makeBranch('KDY');
    }

    /** Act as a user in a branch ('all' for the Super Admin consolidated view). */
    protected function as(User $user, Branch|string $branch)
    {
        return $this->flushHeaders()->actingAs($user)
            ->withHeader('X-Branch-Id', $branch instanceof Branch ? (string) $branch->id : $branch);
    }

    protected function admin(Branch|string $branch = 'all')
    {
        return $this->as($this->admin, $branch);
    }

    /** Add an existing user to another branch with a role (no API, test setup only). */
    protected function addTo(User $u, Branch $b, string $role): User
    {
        $u->branches()->syncWithoutDetaching([$b->id]);
        setPermissionsTeamId($b->id);
        $u->unsetRelation('roles');
        $u->assignRole($this->branchRole($b, $role));

        return $u;
    }

    /** A custom role in a branch with exactly these permissions. */
    protected function customRole(Branch $b, string $name, array $perms): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web', 'branch_id' => $b->id]);
        $role->syncPermissions($perms);

        return $role;
    }

    protected function userWith(Branch $b, Role $role): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($b->id);
        setPermissionsTeamId($b->id);
        $u->assignRole($role);

        return $u;
    }

    protected function roleNameIn(User $u, Branch $b): ?string
    {
        return $u->fresh()->roleInBranch($b->id)?->name;
    }

    protected function meBranches(User $u): array
    {
        return collect($this->as($u->fresh(), 'all')->getJson('/api/v1/me')->assertOk()->json('branches'))->keyBy('code')->all();
    }
}
