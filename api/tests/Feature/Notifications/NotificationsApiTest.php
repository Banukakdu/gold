<?php

namespace Tests\Feature\Notifications;

use App\Events\TransferApprovalDecided;
use App\Events\TransferApprovalRequested;
use App\Events\TransferReceiptChanged;
use App\Events\TransferReceiptRequested;
use App\Models\AuditLog;
use App\Notifications\InAppNotification as N;
use App\Services\InAppNotifications;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** docs/specs/notifications.md AC 14-22, 30b, 30d: the endpoints, the prune and what they never touch. */
class NotificationsApiTest extends NotificationsTestCase
{
    public function test_ac14_own_unread_rows_newest_first_whatever_the_branch_header(): void
    {
        $u1 = $this->note($this->acc, '2026-11-20 08:00:00', 'Unread 1');
        $r1 = $this->note($this->acc, '2026-11-20 08:10:00', 'Read 1');
        $u2 = $this->note($this->acc, '2026-11-20 08:20:00', 'Unread 2');
        $r2 = $this->note($this->acc, '2026-11-20 08:30:00', 'Read 2');
        $u3 = $this->note($this->acc, '2026-11-20 08:40:00', 'Unread 3');
        DB::table('notifications')->whereIn('id', [$r1, $r2])->update(['read_at' => now()]);
        $this->note($this->galCsh, '2026-11-20 09:00:00', 'Someone else');

        $expected = null;
        foreach ([fn () => $this->bare($this->acc), fn () => $this->as($this->acc, $this->kdy), fn () => $this->as($this->acc, (string) $this->gal->id),
            fn () => $this->as($this->acc, 'all')] as $client) {
            $r = $client()->getJson('/api/v1/notifications?filter=unread')->assertOk();
            $this->assertSame([$u3, $u2, $u1], array_column($r->json('data'), 'id'));
            $this->assertSame(['Unread 3', 'Unread 2', 'Unread 1'], array_column($r->json('data'), 'title'));
            $this->assertSame(3, $r->json('unread_count'));
            $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 3], $r->json('meta'));
            $this->assertNotNull($r->json('fetched_at'));
            $this->assertSame(['id', 'kind', 'title', 'body', 'branch', 'url', 'created_at', 'read_at'], array_keys($r->json('data.0')));
            $data = $r->json('data');
            $expected ??= $data;
            $this->assertSame($expected, $data);
            $client()->getJson('/api/v1/notifications/unread-count')->assertOk()->assertExactJson(['unread_count' => 3]);
        }
        $all = $this->bare($this->acc)->getJson('/api/v1/notifications?per_page=10')->assertOk();
        $this->assertCount(5, $all->json('data'));
        $this->assertSame(10, $all->json('meta.per_page'));
        $this->bare($this->acc)->getJson('/api/v1/notifications?per_page=50')->assertStatus(422);
        $this->bare($this->acc)->getJson('/api/v1/notifications?filter=archived')->assertStatus(422);
    }

    public function test_ac15_another_users_row_is_a_404_for_everyone_else(): void
    {
        $id = $this->note($this->galCsh, '2026-11-20 08:00:00');
        $before = DatabaseNotification::find($id)->toArray();
        $this->bare($this->acc)->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->bare($this->admin)->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->bare($this->admin)->getJson("/api/v1/notifications/{$id}")->assertNotFound();
        $this->bare($this->acc)->postJson('/api/v1/notifications/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/read')->assertNotFound();
        $this->assertSame($before, DatabaseNotification::find($id)->toArray());
        $this->bare($this->galCsh)->getJson("/api/v1/notifications/{$id}")->assertOk()->assertJsonPath('id', $id);
    }

    public function test_ac16_read_is_idempotent_and_keeps_the_first_time(): void
    {
        $id = $this->note($this->acc, '2026-11-20 08:00:00');
        $first = $this->bare($this->acc)->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('id', $id)->json('read_at');
        $this->assertNotNull($first);
        $this->travel(5)->minutes();
        $this->bare($this->acc)->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('read_at', $first);
        $this->assertSame($first, DatabaseNotification::find($id)->read_at->toIso8601String());
    }

    public function test_ac17_read_all_stops_at_the_time_the_list_was_fetched(): void
    {
        foreach (['08:00', '08:10', '08:20'] as $t) {
            $this->note($this->acc, "2026-11-20 {$t}:00");
        }
        $other = $this->note($this->galCsh, '2026-11-20 08:30:00');
        $fetchedAt = $this->bare($this->acc)->getJson('/api/v1/notifications')->assertOk()->json('fetched_at');
        $late = $this->note($this->acc, '2026-11-20 10:01:00');
        $this->bare($this->acc)->postJson('/api/v1/notifications/read-all', ['before' => $fetchedAt])->assertOk()->assertExactJson(['marked' => 3]);
        $this->assertNull(DatabaseNotification::find($late)->read_at);
        $this->assertNull(DatabaseNotification::find($other)->read_at);
        $this->bare($this->acc)->getJson('/api/v1/notifications/unread-count')->assertExactJson(['unread_count' => 1]);
        // Without `before`: every unread row of the caller.
        $this->bare($this->acc)->postJson('/api/v1/notifications/read-all')->assertOk()->assertExactJson(['marked' => 1]);
        $this->bare($this->acc)->postJson('/api/v1/notifications/read-all', ['before' => 'yesterday-ish'])->assertStatus(422);
    }

    public function test_ac18_inactive_user_gets_403_on_every_endpoint(): void
    {
        $id = $this->note($this->acc, '2026-11-20 08:00:00');
        $this->acc->update(['status' => 'inactive']);
        foreach ([['getJson', '/api/v1/notifications'], ['getJson', '/api/v1/notifications/unread-count'], ['getJson', "/api/v1/notifications/{$id}"],
            ['postJson', "/api/v1/notifications/{$id}/read"], ['postJson', '/api/v1/notifications/read-all'],
            ['getJson', '/api/v1/notifications/preferences'], ['putJson', '/api/v1/notifications/preferences']] as [$method, $url]) {
            $this->bare($this->acc)->{$method}($url, ...($method === 'getJson' ? [] : [['approval_request_email' => false]]))
                ->assertForbidden()->assertExactJson(['message' => 'Account inactive.']);
        }
        $this->assertNull(DatabaseNotification::find($id)->read_at);
    }

    public function test_ac19_there_is_no_delete_route(): void
    {
        $id = $this->note($this->acc, '2026-11-20 08:00:00');
        $this->bare($this->acc)->deleteJson("/api/v1/notifications/{$id}")->assertStatus(405);
        $this->assertNotNull(DatabaseNotification::find($id));
    }

    public function test_ac20_email_preference_read_update_and_audit(): void
    {
        $this->bare($this->mainBa)->getJson('/api/v1/notifications/preferences')->assertOk()
            ->assertExactJson(['approval_request_email' => true, 'can_approve' => true]);
        $this->bare($this->galCsh)->getJson('/api/v1/notifications/preferences')->assertOk()
            ->assertExactJson(['approval_request_email' => true, 'can_approve' => false]);
        $this->bare($this->admin)->getJson('/api/v1/notifications/preferences')->assertJsonPath('can_approve', true);
        $this->bare($this->mainAccUser)->getJson('/api/v1/notifications/preferences')->assertJsonPath('can_approve', false);

        $this->as($this->mainBa, $this->kdy)->putJson('/api/v1/notifications/preferences', ['approval_request_email' => false])->assertOk()
            ->assertExactJson(['approval_request_email' => false, 'can_approve' => true]);
        $a = AuditLog::where('event', 'user.notification_preferences_updated')->sole();
        $this->assertSame(['approval_request_email' => true], $a->old_values);
        $this->assertSame(['approval_request_email' => false], $a->new_values);
        $this->assertSame($this->mainBa->id, (int) $a->auditable_id);
        $this->assertSame((int) $this->mainBa->id, (int) $a->user_id);
        $this->assertNull($a->branch_id);
        $this->assertFalse($this->mainBa->fresh()->approval_request_email);

        $this->bare($this->mainBa)->putJson('/api/v1/notifications/preferences', ['approval_request_email' => false])->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'user.notification_preferences_updated')->count());
        foreach (['yes', null, 'maybe'] as $bad) {
            $this->bare($this->mainBa)->putJson('/api/v1/notifications/preferences', ['approval_request_email' => $bad])->assertStatus(422)
                ->assertJsonPath('errors.approval_request_email.0', 'Approval request email must be true or false.');
        }
        $this->bare($this->mainBa)->putJson('/api/v1/notifications/preferences', ['approval_request_email' => true])->assertOk();
        $this->assertSame(2, AuditLog::where('event', 'user.notification_preferences_updated')->count());
    }

    public function test_ac21_reading_marking_auto_read_and_prune_write_no_audit_row(): void
    {
        $id = $this->note($this->acc, '2026-11-20 08:00:00');
        $this->note($this->acc, '2026-11-20 08:01:00');
        $this->note($this->acc, '2026-08-01 08:01:00');
        $t1 = $this->t1();
        $before = AuditLog::count();
        $this->bare($this->acc)->getJson('/api/v1/notifications')->assertOk();
        $this->bare($this->acc)->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->bare($this->acc)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('marked', 2);
        event(new TransferApprovalDecided($t1->id, 'cancelled', $this->acc->id)); // R6 auto-read alone
        $this->assertSame(0, DB::table('notifications')->where('type', N::APPROVAL_REQUESTED)->whereNull('read_at')->count());
        $this->artisan('notifications:prune')->assertExitCode(0);
        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $this->acc->id)->count());
        $this->assertSame($before, AuditLog::count());
    }

    public function test_ac22_prune_deletes_only_rows_older_than_90_days_and_nothing_else(): void
    {
        $this->t1();
        $this->t2();
        $old = [$this->note($this->acc, '2026-08-21 09:00:00'), $this->note($this->galCsh, '2026-08-21 09:00:00')];
        $young = [$this->note($this->acc, '2026-08-23 09:00:00'), $this->note($this->galCsh, '2026-08-23 09:00:00')];
        DB::table('notifications')->whereIn('id', [$old[0], $young[0]])->update(['read_at' => '2026-09-01 00:00:00']);
        $keep = DB::table('notifications')->whereNotIn('id', $old)->orderBy('id')->pluck('id')->all();
        $tables = fn () => collect(['transactions', 'ledger_entries', 'audit_logs', 'exports', 'accounts'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
        $before = $tables();

        $this->artisan('notifications:prune')->expectsOutputToContain('Deleted 2 notifications older than 90 days.')->assertExitCode(0);
        $this->assertSame($keep, DB::table('notifications')->orderBy('id')->pluck('id')->all());
        $this->assertSame($before, $tables());
        // Scheduled daily 02:30 Asia/Colombo, without overlapping, on one server (section 5 "Schedule").
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'notifications:prune'));
        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertSame('Asia/Colombo', $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_ac30b_rows_of_a_branch_the_user_left_are_redacted_until_access_returns(): void
    {
        $t2 = $this->t2();
        $row = $this->rows($this->galCsh, N::RECEIPT_REQUESTED)->sole();
        $stored = DB::table('notifications')->where('id', $row->id)->first();
        $full = $this->bare($this->galCsh)->getJson('/api/v1/notifications')->assertOk()->json('data.0');
        $this->assertSame("Confirm receipt of {$t2->ref_no} from KDY: 10,000.00", $full['title']);
        $this->assertNotNull($full['body']);

        $this->sa($this->gal)->postJson("/api/v1/users/{$this->galCsh->id}/remove-from-branch")->assertOk();
        $this->galCsh->refresh();
        foreach (['/api/v1/notifications', '/api/v1/notifications?filter=unread'] as $url) {
            $r = $this->bare($this->galCsh)->getJson($url)->assertOk();
            $this->assertSame('Notification for GAL (no longer available)', $r->json('data.0.title'));
            $this->assertNull($r->json('data.0.body'));
            $this->assertSame(['id' => $this->gal->id, 'code' => 'GAL'], $r->json('data.0.branch'));
            $this->assertStringNotContainsString('10,000', json_encode($r->json()));
            $this->assertStringNotContainsString('A. Perera', json_encode($r->json()));
        }
        $this->bare($this->galCsh)->getJson("/api/v1/notifications/{$row->id}")->assertOk()->assertJsonPath('body', null);
        $this->bare($this->galCsh)->getJson('/api/v1/notifications/unread-count')->assertExactJson(['unread_count' => 1]);
        $this->assertEquals($stored, DB::table('notifications')->where('id', $row->id)->first());

        $this->galCsh->branches()->attach($this->gal->id);
        setPermissionsTeamId($this->gal->id);
        $this->galCsh->assignRole($this->branchRole($this->gal, 'Cashier'));
        $this->assertSame($full, $this->bare($this->galCsh)->getJson('/api/v1/notifications')->json('data.0'));

        // S1 always sees full rows, whatever branches it belongs to.
        $this->t1();
        $sa = $this->bare($this->admin)->getJson('/api/v1/notifications')->assertOk()->json('data.0');
        $this->assertStringEndsWith('needs approval', $sa['title']);
        $this->assertSame('KDY -> GAL, 150,000.00, requested by A. Perera', $sa['body']);
    }

    public function test_ac30d_no_notification_flow_touches_the_ledger(): void
    {
        $t1 = $this->t1();
        $t2 = $this->t2();
        $this->dispute($t2)->assertOk();
        $before = $this->ledgerState();
        $balances = DB::table('accounts')->orderBy('id')->pluck('current_balance', 'id')->all();

        // Create (every listener), read, read-all, auto-read and prune, on their own.
        event(new TransferApprovalRequested($t1->id, $this->acc->id));
        event(new TransferReceiptRequested($t2->id, $this->acc->id));
        event(new TransferReceiptChanged($t2->id, 'awaiting', 'disputed', $this->galCsh->id));
        event(new TransferReceiptChanged($t2->id, 'disputed', 'received', $this->galAcc->id));
        event(new TransferApprovalDecided($t1->id, 'expired', null));
        $this->assertGreaterThan(10, DB::table('notifications')->count());
        $id = $this->rows($this->acc)->first()->id;
        $this->bare($this->acc)->getJson('/api/v1/notifications')->assertOk();
        $this->bare($this->acc)->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->bare($this->acc)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->bare($this->mainBa)->putJson('/api/v1/notifications/preferences', ['approval_request_email' => false])->assertOk();
        DB::table('notifications')->update(['created_at' => '2026-01-01 00:00:00']);
        $this->artisan('notifications:prune')->assertExitCode(0);
        $this->assertSame(0, DB::table('notifications')->count());

        $this->assertSame($before, $this->ledgerState());
        $this->assertSame($balances, DB::table('accounts')->orderBy('id')->pluck('current_balance', 'id')->all());
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_r5_redaction_helper_never_changes_super_admin_rows(): void
    {
        $id = $this->note($this->admin, '2026-11-20 08:00:00', 'GAL row', N::RECEIPT_REQUESTED, $this->gal->id, 'GAL');
        $row = app(InAppNotifications::class)->present(DatabaseNotification::find($id), app(InAppNotifications::class)->viewer($this->admin));
        $this->assertSame(['GAL row', 'Body'], [$row['title'], $row['body']]);
    }

    public function test_review1_approval_requests_are_redacted_once_the_user_is_no_longer_an_approver(): void
    {
        $t1 = $this->t1();
        $row = $this->rows($this->mainBa, N::APPROVAL_REQUESTED)->sole();
        $stored = DB::table('notifications')->where('id', $row->id)->first();
        $this->bare($this->mainBa)->getJson('/api/v1/notifications')->assertJsonPath('data.0.body', 'KDY -> GAL, 150,000.00, requested by A. Perera');

        // Moved to MAIN Accountant (no transfer.approve), still a MAIN member.
        setPermissionsTeamId($this->main->id);
        $this->mainBa->syncRoles([$this->branchRole($this->main, 'Accountant')]);
        $list = $this->bare($this->mainBa)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame('Notification for MAIN (no longer available)', $list->json('data.0.title'));
        $this->assertNull($list->json('data.0.body'));
        foreach (['150,000', 'A. Perera', 'KDY', $t1->ref_no] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($list->json('data')));
        }
        $this->bare($this->mainBa)->getJson("/api/v1/notifications/{$row->id}")->assertOk()->assertJsonPath('body', null);
        $this->assertEquals($stored, DB::table('notifications')->where('id', $row->id)->first());

        // Other kinds of the same user are unaffected; an approver again sees the full row.
        $other = $this->note($this->mainBa, '2026-11-20 08:00:00', 'Export row');
        $this->bare($this->mainBa)->getJson("/api/v1/notifications/{$other}")->assertJsonPath('title', 'Export row')->assertJsonPath('body', 'Body');
        setPermissionsTeamId($this->main->id);
        $this->mainBa->syncRoles([$this->branchRole($this->main, 'Branch Admin')]);
        $this->bare($this->mainBa)->getJson("/api/v1/notifications/{$row->id}")->assertJsonPath('title', "{$t1->ref_no} needs approval");
    }

    public function test_review3_read_all_keeps_a_row_written_after_the_list_in_the_same_second(): void
    {
        foreach (['08:00', '08:10', '08:20'] as $t) {
            $this->note($this->acc, "2026-11-20 {$t}:00");
        }
        $list = $this->bare($this->acc)->getJson('/api/v1/notifications')->assertOk();
        $fetchedAt = $list->json('fetched_at');
        $this->assertTrue(Str::isUuid($list->json('fetched_id')));
        // Same (frozen) second as fetched_at, written after the list.
        $same = $this->note($this->acc, '2026-11-20 10:00:00');
        $this->assertSame(now()->toIso8601String(), $fetchedAt);
        $this->bare($this->acc)->postJson('/api/v1/notifications/read-all', ['before' => $fetchedAt, 'before_id' => $list->json('fetched_id')])
            ->assertOk()->assertExactJson(['marked' => 3]);
        $this->assertNull(DatabaseNotification::find($same)->read_at);
        $this->bare($this->acc)->postJson('/api/v1/notifications/read-all', ['before_id' => 'not-a-uuid'])->assertStatus(422);
    }

    public function test_review6_show_is_owner_only_and_refuses_inactive_users(): void
    {
        $id = $this->note($this->galCsh, '2026-11-20 08:00:00');
        $this->bare($this->acc)->getJson("/api/v1/notifications/{$id}")->assertNotFound()->assertJsonPath('message', 'Notification not found.');
        $this->bare($this->admin)->getJson("/api/v1/notifications/{$id}")->assertNotFound();
        $this->bare($this->galCsh)->getJson('/api/v1/notifications/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')->assertNotFound();
        $this->bare($this->galCsh)->getJson('/api/v1/notifications/not-a-uuid')->assertNotFound();
        $this->bare($this->galCsh)->getJson("/api/v1/notifications/{$id}")->assertOk()->assertJsonPath('id', $id);
        $this->galCsh->update(['status' => 'inactive']);
        $this->bare($this->galCsh)->getJson("/api/v1/notifications/{$id}")->assertForbidden()->assertExactJson(['message' => 'Account inactive.']);
    }
}
