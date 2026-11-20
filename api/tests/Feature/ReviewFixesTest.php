<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** Review round: escalation via profile/invitation, mail failures, default branch, seeder, search. */
class ReviewFixesTest extends UsersAndRolesTestCase
{
    public function test_weaker_admin_cannot_take_over_or_deactivate_a_stronger_user(): void
    {
        $actor = $this->userWith($this->kdy, $this->customRole($this->kdy, 'User Clerk', ['user.view', 'user.edit']));
        $this->userIn($this->kdy, 'Branch Admin');
        $target = $this->userIn($this->kdy, 'Branch Admin'); // never logged in
        $msg = 'You cannot change a user with more permissions than you.';

        $this->as($actor, $this->kdy)->putJson("/api/v1/users/{$target->id}", ['email' => $actor->email.'.x'])->assertForbidden()->assertJsonPath('message', $msg);
        $this->as($actor, $this->kdy)->putJson("/api/v1/users/{$target->id}", ['status' => 'inactive'])->assertForbidden()->assertJsonPath('message', $msg);
        $this->as($actor, $this->kdy)->putJson("/api/v1/users/{$target->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->as($actor, $this->kdy)->postJson("/api/v1/users/{$target->id}/resend-invite")->assertForbidden()->assertJsonPath('message', $msg);
        $this->as($actor, $this->kdy)->getJson("/api/v1/users/{$target->id}")->assertOk()->assertJsonPath('profile_locked_reason', $msg);

        $fresh = $target->fresh();
        $this->assertSame($target->email, $fresh->email);
        $this->assertSame('active', $fresh->status);
        Notification::assertNothingSentTo($fresh);

        // an equal-or-weaker target is still fine
        $weak = $this->userWith($this->kdy, $this->customRole($this->kdy, 'Reader', ['user.view']));
        $this->as($actor, $this->kdy)->putJson("/api/v1/users/{$weak->id}", ['phone' => '0700000000'])->assertOk();
        $this->as($actor, $this->kdy)->postJson("/api/v1/users/{$weak->id}/resend-invite")->assertOk();
        $this->as($actor, $this->kdy)->getJson("/api/v1/users/{$weak->id}")->assertJsonPath('profile_locked_reason', null);
    }

    public function test_shared_user_resend_needs_every_branch(): void
    {
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $shared = $this->addTo($this->userIn($this->kdy, 'Cashier'), $this->main, 'Viewer');
        $this->as($badmin, $this->kdy)->postJson("/api/v1/users/{$shared->id}/resend-invite")
            ->assertForbidden()->assertJsonPath('message', 'This user also works in other branches; ask a Super Admin.');
        $this->as($badmin, $this->kdy)->getJson("/api/v1/users/{$shared->id}")
            ->assertJsonPath('profile_locked_reason', 'This user also works in other branches; ask a Super Admin.');
    }

    public function test_mail_transport_failure_does_not_fail_the_request_or_lose_audit(): void
    {
        // Real notification channels (undo the fake) with a mailer whose transport always throws.
        $this->app->forgetInstance(ChannelManager::class);
        Notification::clearResolvedInstances();
        Mail::extend('broken', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP down');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });
        config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);
        $reported = 0;
        $this->app->make(ExceptionHandler::class)
            ->reportable(function (TransportException $e) use (&$reported) {
                $reported++;
            });

        $role = $this->branchRole($this->kdy, 'Cashier');
        $id = $this->admin($this->kdy)->postJson('/api/v1/users', ['name' => 'Inv', 'email' => 'inv@example.com', 'role_id' => $role->id])
            ->assertCreated()->json('id');
        $this->assertTrue(AuditLog::where('event', 'user.invited')->where('auditable_id', $id)->exists());
        $this->admin($this->kdy)->postJson("/api/v1/users/{$id}/resend-invite")->assertOk();
        $this->assertTrue(AuditLog::where('event', 'user.invite_resent')->where('auditable_id', $id)->exists());

        // Super Admin alert path (non-Super Admin actor)
        $badmin = $this->userIn($this->kdy, 'Branch Admin');
        $this->as($badmin, $this->kdy)->postJson('/api/v1/users', ['name' => 'B', 'email' => 'b@example.com', 'role_id' => $role->id])->assertCreated();
        $this->as($badmin, $this->kdy)->putJson("/api/v1/roles/{$role->id}", ['permissions' => ['transaction.view']])->assertOk();
        $this->assertTrue(AuditLog::where('event', 'role.updated')->where('auditable_id', $role->id)->exists());
        $this->assertGreaterThanOrEqual(4, $reported);
    }

    public function test_default_branch_is_kept_when_memberships_change(): void
    {
        $u = $this->userIn($this->kdy, 'Cashier');
        $u->branches()->updateExistingPivot($this->kdy->id, ['is_default' => true]);
        $this->addTo($u, $this->main, 'Viewer');
        $default = fn () => $u->branches()->wherePivot('is_default', true)->pluck('branches.id')->all();

        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/remove-from-branch")->assertOk();
        $this->assertSame([$this->main->id], $default());

        $this->admin($this->main)->postJson("/api/v1/users/{$u->id}/remove-from-branch")->assertOk();
        $this->assertSame([], $default());
        $this->admin($this->kdy)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $this->branchRole($this->kdy, 'Viewer')->id])->assertOk();
        $this->assertSame([$this->kdy->id], $default());
        $this->admin($this->main)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $this->branchRole($this->main, 'Viewer')->id])->assertOk();
        $this->assertSame([$this->kdy->id], $default());
    }

    public function test_seeder_adds_new_catalogue_keys_to_all_keys_templates_only(): void
    {
        $ba = Role::whereNull('branch_id')->where('name', 'Branch Admin')->first();
        $acct = Role::whereNull('branch_id')->where('name', 'Accountant')->first();
        $ba->revokePermissionTo('report.export'); // Super Admin edit
        Permission::where('name', 'audit.view')->delete(); // simulate a key that is new to the catalogue

        $this->seed(PermissionSeeder::class);

        $this->assertContains('audit.view', $ba->fresh()->permissionKeys());
        $this->assertNotContains('report.export', $ba->fresh()->permissionKeys());
        $this->assertNotContains('audit.view', $acct->fresh()->permissionKeys());
    }

    public function test_user_search_treats_like_wildcards_literally(): void
    {
        $a = User::factory()->create(['name' => 'Ann_Lee']);
        $b = User::factory()->create(['name' => 'AnnXLee']);
        $c = User::factory()->create(['name' => '100% Kumar']);
        foreach ([$a, $b, $c] as $u) {
            $this->addTo($u, $this->kdy, 'Viewer');
        }
        $ids = fn (string $q) => array_column($this->admin($this->kdy)->getJson('/api/v1/users?q='.urlencode($q))->assertOk()->json('data'), 'id');
        $this->assertSame([$a->id], $ids('n_L'));
        $this->assertSame([$c->id], $ids('0%'));
        $this->assertSame([], $ids('%%%'));
    }
}
