<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BusinessDate;

class AccessControlTest extends AccountingTestCase
{
    public function test_login_and_lockout_after_five_failures(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@accountgold.local', 'password' => 'ChangeMe123'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['branches']]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'admin@accountgold.local', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@accountgold.local', 'password' => 'ChangeMe123'])->assertStatus(429);
    }

    public function test_branch_user_cannot_see_or_use_another_branch(): void
    {
        $kdy = $this->makeBranch('KDY');
        $gle = $this->makeBranch('GLE');
        $cashier = $this->userIn($kdy, 'Cashier');

        // Not a member of Galle
        $this->actingAs($cashier)->withHeader('X-Branch-Id', (string) $gle->id)->getJson('/api/v1/accounts')->assertForbidden();
        // Must pick a branch
        $this->flushHeaders()->actingAs($cashier)->getJson('/api/v1/accounts')->assertStatus(400);
        // Sees only own accounts
        $ids = collect($this->actingAs($cashier)->withHeader('X-Branch-Id', (string) $kdy->id)->getJson('/api/v1/accounts')->assertOk()->json())->pluck('branch_id')->unique()->all();
        $this->assertSame([$kdy->id], $ids);
        // Cannot open another branch's account via the URL (scoped route binding)
        $this->actingAs($cashier)->withHeader('X-Branch-Id', (string) $kdy->id)
            ->getJson("/api/v1/accounts/{$this->cashOf($gle)->id}/statement?from=2026-01-01&to=2026-12-31")->assertNotFound();
        // Cashier has no transfer permission
        $this->actingAs($cashier)->withHeader('X-Branch-Id', (string) $kdy->id)->postJson('/api/v1/transfers', [])->assertForbidden();
        // Cannot post an expense from another branch's account
        $this->actingAs($cashier)->withHeader('X-Branch-Id', (string) $kdy->id)->postJson('/api/v1/expenses', [
            'account_id' => $this->cashOf($gle)->id, 'category_id' => $this->category('expense')->id, 'txn_date' => BusinessDate::today(), 'amount' => 1,
        ])->assertNotFound();
    }

    public function test_branch_admin_creates_custom_role_without_escalation(): void
    {
        $kdy = $this->makeBranch('KDY');
        $acct = $this->userIn($kdy, 'Accountant');
        $acct->givePermissionTo([]); // no-op, accountants can't manage roles
        $h = ['X-Branch-Id' => (string) $kdy->id];
        $this->actingAs($acct)->withHeaders($h)->postJson('/api/v1/roles', ['name' => 'X'])->assertForbidden();

        $badmin = $this->userIn($kdy, 'Branch Admin');
        $role = $this->actingAs($badmin)->withHeaders($h)->postJson('/api/v1/roles', [
            'name' => 'Petty Cash Clerk', 'permissions' => ['expense.create', 'transaction.view'],
        ])->assertCreated()->json();
        $this->assertSame($kdy->id, $role['branch_id']);

        // the new role is not visible in another branch
        $gle = $this->makeBranch('GLE');
        $names = collect($this->asAdmin($gle->id)->getJson('/api/v1/roles')->json())->pluck('name');
        $this->assertNotContains('Petty Cash Clerk', $names);

        // Branch admin creates a user with that role; user can record expenses but not income
        $this->actingAs($badmin)->withHeaders($h)->postJson('/api/v1/users', [
            'name' => 'Clerk', 'email' => 'clerk@example.com', 'password' => 'secret123', 'role_id' => $role['id'],
        ])->assertCreated();
        $clerk = User::where('email', 'clerk@example.com')->first();
        $this->actingAs($clerk)->withHeaders($h)->postJson('/api/v1/incomes', [])->assertForbidden();
        $this->actingAs($clerk)->withHeaders($h)->postJson('/api/v1/expenses', [])->assertStatus(422); // allowed, fails validation
    }

    public function test_deactivated_user_is_locked_out(): void
    {
        $kdy = $this->makeBranch('KDY');
        $u = $this->userIn($kdy, 'Cashier');
        $this->asAdmin($kdy->id)->putJson("/api/v1/users/{$u->id}", ['status' => 'inactive'])->assertOk();
        $this->actingAs($u->fresh())->withHeader('X-Branch-Id', (string) $kdy->id)->getJson('/api/v1/accounts')->assertForbidden();
    }
}
