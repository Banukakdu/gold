<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AccessChangedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/** Spec users-and-roles, Accounting review: audit trail and attribution (AC 36-48). */
class AccessAuditTest extends UsersAndRolesTestCase
{
    public function test_ac36_role_assignment_records_old_and_new_role_with_permissions(): void
    {
        $u = $this->userIn($this->kdy, 'Cashier');
        $cashier = $this->branchRole($this->kdy, 'Cashier');
        $acct = $this->branchRole($this->kdy, 'Accountant');
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $acct->id])->assertOk();

        $row = AuditLog::where('event', 'user.role_assigned')->where('auditable_id', $u->id)->latest('id')->first();
        $this->assertSame($this->kdy->id, $row->branch_id);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame(['role_id' => $cashier->id, 'role_name' => 'Cashier', 'permissions' => $cashier->permissionKeys()], $row->old_values);
        $this->assertSame(['role_id' => $acct->id, 'role_name' => 'Accountant', 'permissions' => $acct->permissionKeys()], $row->new_values);
        $sorted = $row->new_values['permissions'];
        sort($sorted);
        $this->assertSame($sorted, $row->new_values['permissions']);

        // no previous role in that branch -> old = null
        $viewer = $this->branchRole($this->main, 'Viewer');
        $this->admin($this->main)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $viewer->id])->assertOk();
        $row = AuditLog::where('event', 'user.role_assigned')->where('auditable_id', $u->id)->latest('id')->first();
        $this->assertNull($row->old_values);
        $this->assertSame($this->main->id, $row->branch_id);
    }

    public function test_ac37_removal_records_old_role(): void
    {
        $u = $this->userIn($this->kdy, 'Cashier');
        $role = $this->branchRole($this->kdy, 'Cashier');
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/remove-from-branch")->assertOk();
        $row = AuditLog::where('event', 'user.removed_from_branch')->where('auditable_id', $u->id)->first();
        $this->assertSame($this->kdy->id, $row->branch_id);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame(['role_id' => $role->id, 'role_name' => 'Cashier', 'permissions' => $role->permissionKeys()], $row->old_values);
        $this->assertNull($row->new_values);
    }

    public function test_ac38_role_events_record_full_state_and_holders(): void
    {
        $this->userIn($this->kdy, 'Branch Admin');
        $created = $this->admin($this->kdy)->postJson('/api/v1/roles', ['name' => 'Clerk', 'permissions' => ['transaction.view', 'expense.create']])->assertCreated()->json();
        $row = AuditLog::where('event', 'role.created')->where('auditable_id', $created['id'])->first();
        $this->assertNull($row->old_values);
        $this->assertSame(['name' => 'Clerk', 'status' => 'active', 'permissions' => ['expense.create', 'transaction.view']], $row->new_values);

        $this->admin($this->kdy)->putJson("/api/v1/roles/{$created['id']}", ['name' => 'Petty Clerk', 'permissions' => ['transaction.view']])->assertOk();
        $row = AuditLog::where('event', 'role.updated')->where('auditable_id', $created['id'])->first();
        $this->assertSame(['name' => 'Clerk', 'status' => 'active', 'permissions' => ['expense.create', 'transaction.view']], $row->old_values);
        $this->assertSame(['name' => 'Petty Clerk', 'status' => 'active', 'permissions' => ['transaction.view']], $row->new_values);

        $holder = $this->userWith($this->kdy, Role::find($created['id']));
        $inactiveHolder = $this->userWith($this->kdy, Role::find($created['id']));
        $inactiveHolder->update(['status' => 'inactive']);
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$created['id']}", ['status' => 'inactive'])->assertOk();
        $row = AuditLog::where('event', 'role.deactivated')->where('auditable_id', $created['id'])->first();
        $this->assertSame('active', $row->old_values['status']);
        $this->assertSame('inactive', $row->new_values['status']);
        $this->assertSame([$holder->id], $row->new_values['holders']);
        $this->assertSame(['transaction.view'], $row->new_values['permissions']);

        $this->admin($this->kdy)->putJson("/api/v1/roles/{$created['id']}", ['status' => 'active'])->assertOk();
        $row = AuditLog::where('event', 'role.activated')->where('auditable_id', $created['id'])->first();
        $this->assertSame('inactive', $row->old_values['status']);
        $this->assertSame('active', $row->new_values['status']);
        $this->assertSame([$holder->id], $row->new_values['holders']);
    }

    public function test_ac40_invite_and_status_audit_rows_and_no_audit_write_endpoints(): void
    {
        $role = $this->branchRole($this->kdy, 'Cashier');
        $id = $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'Inv', 'email' => 'inv@example.com', 'role_id' => $role->id])->assertCreated()->json('id');
        $invited = AuditLog::where('event', 'user.invited')->where('auditable_id', $id)->first();
        $this->assertSame(User::class, $invited->auditable_type);
        $this->assertSame($this->kdy->id, $invited->branch_id);
        $this->assertSame($role->id, $invited->new_values['role_id']);
        $this->assertSame($this->admin->id, $invited->user_id);
        $this->assertNotNull($invited->ip);
        $this->assertNotNull($invited->created_at);

        $this->admin($this->kdy)->postJson("/api/v1/users/{$id}/resend-invite")->assertOk();
        $this->assertTrue(AuditLog::where('event', 'user.invite_resent')->where('auditable_id', $id)->where('user_id', $this->admin->id)->exists());

        $this->admin($this->kdy)->putJson("/api/v1/users/{$id}", ['status' => 'inactive'])->assertOk();
        $row = AuditLog::where('event', 'user.updated')->where('auditable_id', $id)->latest('id')->first();
        $this->assertSame(['status' => 'active'], $row->old_values);
        $this->assertSame(['status' => 'inactive'], $row->new_values);
        $this->assertSame($this->kdy->id, $row->branch_id);

        foreach (Route::getRoutes() as $r) {
            if (str_contains($r->uri(), 'audit')) {
                $this->assertSame(['GET', 'HEAD'], $r->methods());
            }
        }
    }

    public function test_ac42_authors_stay_visible_and_totals_unchanged_after_leaving(): void
    {
        $today = now()->toDateString();
        $x = $this->userIn($this->kdy, 'Accountant');
        $y = $this->userIn($this->kdy, 'Cashier');
        $viewer = $this->userIn($this->kdy, 'Viewer');
        $this->userIn($this->kdy, 'Branch Admin');
        $post = fn (User $u) => $this->as($u, $this->kdy)->postJson('/api/v1/incomes', [
            'account_id' => $this->cashOf($this->kdy)->id, 'category_id' => $this->category('income')->id, 'txn_date' => $today, 'amount' => '100',
        ])->assertCreated()->json();
        $tx = $post($x);
        $ty = $post($y);
        $balance = $this->balance($this->cashOf($this->kdy));
        $dashboard = $this->admin($this->kdy)->getJson('/api/v1/dashboard')->json();
        $auditUserIds = AuditLog::orderBy('id')->pluck('user_id')->all();

        $this->admin($this->kdy)->putJson("/api/v1/users/{$x->id}", ['status' => 'inactive'])->assertOk();
        $this->admin($this->kdy)->postJson("/api/v1/users/{$y->id}/remove-from-branch")->assertOk();

        $rows = collect($this->as($viewer, $this->kdy)->getJson('/api/v1/transactions')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($x->name, $rows[$tx['id']]['creator']['name']);
        $this->assertSame('inactive', $rows[$tx['id']]['creator_tag']);
        $this->assertSame($y->name, $rows[$ty['id']]['creator']['name']);
        $this->assertSame('no longer in this branch', $rows[$ty['id']]['creator_tag']);
        $this->as($viewer, $this->kdy)->getJson("/api/v1/transactions/{$ty['id']}")->assertOk()
            ->assertJsonPath('creator.name', $y->name)->assertJsonPath('creator_tag', 'no longer in this branch');

        $creators = collect($this->as($viewer, $this->kdy)->getJson('/api/v1/transactions/creators')->assertOk()->json())->keyBy('id');
        $this->assertSame('inactive', $creators[$x->id]['tag']);
        $this->assertSame('no longer in this branch', $creators[$y->id]['tag']);
        $this->assertCount(1, $this->as($viewer, $this->kdy)->getJson("/api/v1/transactions?created_by={$y->id}")->json('data'));

        $this->assertDatabaseHas('transactions', ['id' => $tx['id'], 'created_by' => $x->id]);
        $this->assertDatabaseHas('transactions', ['id' => $ty['id'], 'created_by' => $y->id]);
        $this->assertSame($auditUserIds, AuditLog::orderBy('id')->limit(count($auditUserIds))->pluck('user_id')->all());
        $this->assertSame($balance, $this->balance($this->cashOf($this->kdy)));
        $this->assertEquals($dashboard, $this->admin($this->kdy)->getJson('/api/v1/dashboard')->json());
    }

    /** Approval workflow does not exist yet; this covers what can be proven today. */
    public function test_ac43_pending_entries_of_a_leaver_stay_pending_and_old_tokens_fail(): void
    {
        $x = User::factory()->create(['status' => 'active', 'email' => 'x@example.com', 'password' => 'secret123']);
        $this->addTo($x, $this->kdy, 'Cashier');
        $this->userIn($this->kdy, 'Branch Admin');
        $token = $this->flushHeaders()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'secret123'])->json('token');
        $pending = Transaction::withoutGlobalScopes()->create([
            'branch_id' => $this->kdy->id, 'type' => 'expense', 'txn_date' => now()->toDateString(), 'amount' => '50.00',
            'account_id' => $this->cashOf($this->kdy)->id, 'category_id' => $this->category('expense')->id, 'status' => 'pending', 'created_by' => $x->id,
        ]);

        $this->admin($this->kdy)->putJson("/api/v1/users/{$x->id}", ['status' => 'inactive'])->assertOk();

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame($x->id, $pending->fresh()->created_by);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($token)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions')->assertUnauthorized();
    }

    public function test_ac47_self_cancellation_is_flagged(): void
    {
        $today = now()->toDateString();
        $x = $this->userIn($this->kdy, 'Accountant');
        $y = $this->userIn($this->kdy, 'Accountant');
        $gle = $this->makeBranch('GLE');
        $income = fn (User $u) => $this->as($u, $this->kdy)->postJson('/api/v1/incomes', [
            'account_id' => $this->cashOf($this->kdy)->id, 'category_id' => $this->category('income')->id, 'txn_date' => $today, 'amount' => '1000',
        ])->assertCreated()->json('id');
        $own = $income($x);
        $other = $income($x);

        $this->as($x, $this->kdy)->postJson("/api/v1/transactions/{$own}/void", ['reason' => 'dup'])->assertOk();
        $this->as($y, $this->kdy)->postJson("/api/v1/transactions/{$other}/void", ['reason' => 'dup'])->assertOk();
        $this->assertTrue(AuditLog::where('event', 'transaction.voided')->where('auditable_id', $own)->first()->new_values['self']);
        $this->assertFalse(AuditLog::where('event', 'transaction.voided')->where('auditable_id', $other)->first()->new_values['self']);

        $income($x);
        $trf = fn () => $this->as($x, $this->kdy)->postJson('/api/v1/transfers', [
            'from_account_id' => $this->cashOf($this->kdy)->id, 'to_account_id' => $this->cashOf($gle)->id, 'amount' => '100', 'txn_date' => $today, 'note' => 'x',
        ])->assertCreated()->json('id');
        $t1 = $trf();
        $t2 = $trf();
        $this->as($x, $this->kdy)->postJson("/api/v1/transactions/{$t1}/reverse", ['reason' => 'r'])->assertCreated();
        $this->as($y, $this->kdy)->postJson("/api/v1/transactions/{$t2}/reverse", ['reason' => 'r'])->assertCreated();
        $this->assertTrue(AuditLog::where('event', 'transaction.reversed')->where('auditable_id', $t1)->first()->new_values['self']);
        $this->assertFalse(AuditLog::where('event', 'transaction.reversed')->where('auditable_id', $t2)->first()->new_values['self']);
    }

    public function test_ac48_super_admins_are_emailed_about_access_changes_by_non_super_admins(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $u = $this->userIn($this->kdy, 'Cashier');
        $viewer = $this->branchRole($this->kdy, 'Viewer');

        $this->as($badmin, $this->kdy)->postJson('/api/v1/users', ['name' => 'New', 'email' => 'new@example.com', 'role_id' => $viewer->id])->assertCreated();
        $this->as($badmin, $this->kdy)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $viewer->id])->assertOk();
        $this->as($badmin, $this->kdy)->putJson("/api/v1/roles/{$viewer->id}", ['permissions' => ['transaction.view']])->assertOk();

        $events = [];
        Notification::assertSentTo($this->admin, AccessChangedNotification::class, function (AccessChangedNotification $n) use (&$events, $badmin) {
            $events[] = $n->event;

            return str_contains($n->actor, $badmin->email) && $n->branch === 'KDY';
        });
        $this->assertEqualsCanonicalizing(['user.created', 'user.role_assigned', 'role.updated'], $events);
        $assigned = collect(Notification::sent($this->admin, AccessChangedNotification::class))->firstWhere('event', 'user.role_assigned');
        $this->assertSame('Cashier', $assigned->old['role_name']);
        $this->assertSame('Viewer', $assigned->new['role_name']);

        // Super Admin's own changes do not alert
        $count = count(Notification::sent($this->admin, AccessChangedNotification::class));
        $this->admin($this->kdy)->putJson("/api/v1/roles/{$viewer->id}", ['permissions' => ['transaction.view', 'report.view']])->assertOk();
        $this->assertCount($count, Notification::sent($this->admin, AccessChangedNotification::class));
    }

    public function test_super_admins_are_alerted_when_a_branch_admin_changes_email_or_status_or_resends_invite(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $u = $this->userIn($this->kdy, 'Cashier');

        $this->as($badmin, $this->kdy)->putJson("/api/v1/users/{$u->id}", ['email' => 'changed@example.com'])->assertOk();
        $this->as($badmin, $this->kdy)->postJson("/api/v1/users/{$u->id}/resend-invite")->assertOk();
        $this->as($badmin, $this->kdy)->putJson("/api/v1/users/{$u->id}", ['phone' => '0771234567'])->assertOk(); // not sensitive

        $sent = collect(Notification::sent($this->admin, AccessChangedNotification::class));
        $updated = $sent->where('event', 'user.updated');
        $this->assertCount(1, $updated);
        $this->assertSame('changed@example.com', $updated->first()->new['email']);
        $this->assertNotSame('changed@example.com', $updated->first()->old['email']);
        $this->assertCount(1, $sent->where('event', 'user.invite_resent'));
    }
}
