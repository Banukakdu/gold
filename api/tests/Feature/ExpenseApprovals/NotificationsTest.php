<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ExpenseApprovalRequestedMail;
use App\Notifications\InAppNotification as N;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/** AC 30-33 and the R4b flag of 35b: in-app rows of expense approvals, R6 auto-read, R5 redaction and the email. */
class NotificationsTest extends ExpenseApprovalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->csh->update(['name' => 'K. Fernando']);
        $this->kdyBa->update(['name' => 'R. Bandara']);
        DB::table('notifications')->delete();
    }

    /** @return int[] ids of the users holding a row of $kind about $t */
    private function recipients(string $kind, Transaction $t): array
    {
        return DatabaseNotification::where('type', $kind)->get()->filter(fn ($n) => (int) ($n->data['transaction_id'] ?? 0) === $t->id)
            ->pluck('notifiable_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    private static function ids(array $users): array
    {
        return collect($users)->map(fn (User $u) => (int) $u->id)->sort()->values()->all();
    }

    private function rows(User $u, string $kind)
    {
        return DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $u->id)->where('type', $kind)->get();
    }

    private function bare(User $u)
    {
        return $this->flushHeaders()->actingAs($u);
    }

    public function test_ac30_request_goes_to_the_branch_approvers_and_super_admins(): void
    {
        $e1 = $this->e1();
        $this->assertSame(self::ids([$this->kdyBa, $this->kdyBa2, $this->admin]), $this->recipients(N::EXPENSE_APPROVAL_REQUESTED, $e1));
        foreach ([$this->kdyBa, $this->kdyBa2, $this->admin] as $u) {
            $n = $this->rows($u, N::EXPENSE_APPROVAL_REQUESTED)->sole();
            $this->assertNull($n->read_at);
            $this->assertSame(['KDY', $this->kdy->id, "/transactions/{$e1->id}", "{$e1->ref_no} needs approval"],
                [$n->data['branch_code'], $n->data['branch_id'], $n->data['url'], $n->data['title']]);
            $this->assertSame('KDY Cash, Utilities > Electricity, 75,000.00, requested by K. Fernando', $n->data['body']);
            $this->assertStringNotContainsString('Ceylon Electricity Board', json_encode($n->data));
            $this->assertStringNotContainsString('BILL-7781', json_encode($n->data));
        }
        foreach ([$this->csh, $this->acc, $this->ba, $this->mainBa] as $u) {
            $this->assertCount(0, DatabaseNotification::where('notifiable_id', $u->id)->get(), $u->name);
        }
    }

    public function test_ac31_decisions_tell_the_requester_and_close_the_approvers_rows(): void
    {
        $e1 = $this->e1();
        $this->approve($e1->id)->assertOk();
        $d = $this->rows($this->csh, N::EXPENSE_APPROVAL_DECIDED)->sole();
        $this->assertSame("Your expense request {$e1->ref_no} was approved and posted. You may now pay it.", $d->data['title']);
        $this->assertSame(['KDY', "/transactions/{$e1->id}"], [$d->data['branch_code'], $d->data['url']]);
        $this->assertNotNull($this->rows($this->kdyBa2, N::EXPENSE_APPROVAL_REQUESTED)->sole()->read_at);
        $this->assertNotNull($this->rows($this->admin, N::EXPENSE_APPROVAL_REQUESTED)->sole()->read_at);
        $this->assertCount(0, $this->rows($this->kdyBa, N::EXPENSE_APPROVAL_DECIDED));
        $this->assertCount(0, DatabaseNotification::where('type', N::EXPENSE_APPROVAL_FLAGGED)->get());

        $r = $this->e1('60000.00', '2026-11-19');
        $this->reject($r->id, 'Wrong supplier, please re-enter')->assertOk();
        $rd = $this->rows($this->csh, N::EXPENSE_APPROVAL_DECIDED)->first(fn ($n) => $n->data['transaction_id'] === $r->id);
        $this->assertSame("Your expense request {$r->ref_no} was rejected by R. Bandara. Do not pay it.", $rd->data['title']);
        $this->assertStringNotContainsString('Wrong supplier', json_encode($rd->data));

        $c = $this->e1('55000.00', '2026-11-19');
        $this->cancel($c->id)->assertOk();
        $this->assertCount(0, $this->rows($this->csh, N::EXPENSE_APPROVAL_DECIDED)->filter(fn ($n) => $n->data['transaction_id'] === $c->id));
        $this->assertSame(0, DatabaseNotification::where('type', N::EXPENSE_APPROVAL_REQUESTED)->whereNull('read_at')->where('data->transaction_id', $c->id)->count());

        Carbon::setTestNow('2026-10-01 10:00:00');
        $x = $this->e1('51000.00', '2026-10-01');
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->artisan('expenses:expire-approvals')->assertExitCode(0);
        $xd = $this->rows($this->csh, N::EXPENSE_APPROVAL_DECIDED)->first(fn ($n) => $n->data['transaction_id'] === $x->id);
        $this->assertSame("Your expense request {$x->ref_no} expired after 30 days. Do not pay it.", $xd->data['title']);
        $this->assertSame(0, DatabaseNotification::where('type', N::EXPENSE_APPROVAL_REQUESTED)->whereNull('read_at')->where('data->transaction_id', $x->id)->count());
    }

    public function test_ac32_email_to_approvers_who_keep_it_on_with_no_balance_or_free_text(): void
    {
        $this->kdyBa2->forceFill(['approval_request_email' => false])->save();
        $e1 = $this->e1();
        Notification::assertSentTimes(ExpenseApprovalRequestedMail::class, 2);
        Notification::assertNotSentTo($this->kdyBa2, ExpenseApprovalRequestedMail::class);
        foreach ([$this->kdyBa, $this->admin] as $u) {
            $row = $this->rows($u, N::EXPENSE_APPROVAL_REQUESTED)->sole();
            Notification::assertSentTo($u, ExpenseApprovalRequestedMail::class, function (ExpenseApprovalRequestedMail $m, array $channels) use ($row, $u, $e1) {
                $mail = $m->toMail($u);

                return $channels === ['mail'] && $m->notificationId === $row->id && str_ends_with($mail->actionUrl, "/notifications/{$row->id}")
                    && $mail->subject === "Expense {$e1->ref_no} needs your approval";
            });
        }

        $mail = Notification::sent($this->kdyBa, ExpenseApprovalRequestedMail::class)->sole();
        $send = fn () => (new ChannelManager($this->app))->sendNow($this->kdyBa->fresh(), $mail);
        $messages = fn () => Mail::mailer()->getSymfonyTransport()->messages();
        $send();
        $this->assertCount(1, $messages());
        foreach ([$messages()[0]->getOriginalMessage()->getTextBody() ?? '', $messages()[0]->getOriginalMessage()->getHtmlBody() ?? ''] as $text) {
            foreach (['75,000.00', 'KDY', 'Cash', 'Utilities &gt; Electricity|Utilities > Electricity'] as $needle) {
                $this->assertMatchesRegularExpression('/'.str_replace(['>', '.'], ['>', '\.'], $needle).'/', $text);
            }
            foreach (['available', 'balance', '200,000.00', 'Ceylon Electricity Board', 'BILL-7781', 'November bill'] as $secret) {
                $this->assertStringNotContainsStringIgnoringCase($secret, $text);
            }
        }

        // Lost expense.approve in KDY (moved to Accountant) before the queue ran: dropped.
        setPermissionsTeamId($this->kdy->id);
        $this->kdyBa->syncRoles([$this->branchRole($this->kdy, 'Accountant')]);
        $send();
        $this->assertCount(1, $messages());
        setPermissionsTeamId($this->kdy->id);
        $this->kdyBa->syncRoles([$this->branchRole($this->kdy, 'Branch Admin')]);
        $send();
        $this->assertCount(2, $messages());

        // Decided before the queue ran: dropped.
        $this->approve($e1->id, $this->kdyBa2)->assertOk();
        $send();
        $this->assertCount(2, $messages());
    }

    public function test_ac13_a_branch_user_managers_request_goes_to_super_admins_only(): void
    {
        $e2 = $this->e1('80000.00', '2026-11-20', $this->kdyBa);
        $this->assertSame(self::ids([$this->admin]), $this->recipients(N::EXPENSE_APPROVAL_REQUESTED, $e2));
        Notification::assertSentTimes(ExpenseApprovalRequestedMail::class, 1);
        Notification::assertSentTo($this->admin, ExpenseApprovalRequestedMail::class);
        // A second Super Admin is told too; an inactive one is not.
        $s2 = User::factory()->create(['status' => 'active', 'is_super_admin' => true]);
        $s3 = User::factory()->create(['status' => 'inactive', 'is_super_admin' => true]);
        $e3 = $this->e1('81000.00', '2026-11-20', $this->kdyBa);
        $this->assertSame(self::ids([$this->admin, $s2]), $this->recipients(N::EXPENSE_APPROVAL_REQUESTED, $e3));
        $this->assertCount(0, $this->rows($s3, N::EXPENSE_APPROVAL_REQUESTED));
    }

    public function test_ac33_redacted_once_the_reader_no_longer_approves_that_branch(): void
    {
        $e1 = $this->e1();
        $row = $this->rows($this->kdyBa, N::EXPENSE_APPROVAL_REQUESTED)->sole();
        $stored = DB::table('notifications')->where('id', $row->id)->first();
        $this->bare($this->kdyBa)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.body', 'KDY Cash, Utilities > Electricity, 75,000.00, requested by K. Fernando');

        setPermissionsTeamId($this->kdy->id);
        $this->kdyBa->syncRoles([$this->branchRole($this->kdy, 'Accountant')]);
        $list = $this->bare($this->kdyBa)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame('Notification for KDY (no longer available)', $list->json('data.0.title'));
        $this->assertNull($list->json('data.0.body'));
        foreach (['75,000', 'K. Fernando', $e1->ref_no] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($list->json('data')));
        }
        $this->assertEquals($stored, DB::table('notifications')->where('id', $row->id)->first());
        // S1 always sees the full row.
        $this->bare($this->admin)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.title', "{$e1->ref_no} needs approval");

        // Preferences: a user holding only KDY expense.approve can approve (email preference shown).
        $only = $this->userIn($this->kdy, 'Cashier');
        $this->bare($only)->getJson('/api/v1/notifications/preferences')->assertOk()->assertJsonPath('can_approve', false);
        $this->grant($this->kdy, 'Cashier');
        $this->bare($only)->getJson('/api/v1/notifications/preferences')->assertOk()->assertJsonPath('can_approve', true);
    }

    public function test_ac35b_the_flag_reaches_the_super_admins_and_stays_unread(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $id = $this->as($this->kdyBa, $this->kdy)->postJson('/api/v1/users', ['name' => 'U Dummy', 'email' => 'u.dummy@example.com',
            'role_id' => $this->branchRole($this->kdy, 'Cashier')->id])->assertCreated()->json('id');
        Carbon::setTestNow('2026-11-20 10:00:00');
        $u = User::findOrFail($id);
        $e = $this->e1('75000.00', '2026-11-20', $u);
        $this->approve($e->id)->assertOk();
        $f = $this->rows($this->admin, N::EXPENSE_APPROVAL_FLAGGED)->sole();
        $this->assertNull($f->fresh()->read_at, 'R6 does not mark the flag read');
        $this->assertSame("{$e->ref_no} approved by the user who added the requester", $f->data['title']);
        $this->assertSame('KDY 75,000.00, requested by U Dummy, approved by R. Bandara', $f->data['body']);
        $this->assertSame(self::ids([$this->admin]), $this->recipients(N::EXPENSE_APPROVAL_FLAGGED, $e));

        // Approved by someone who did not bring the requester in: no flag row.
        $e2 = $this->e1('60000.00', '2026-11-20', $u);
        $this->approve($e2->id, $this->kdyBa2)->assertOk();
        $this->assertSame([], $this->recipients(N::EXPENSE_APPROVAL_FLAGGED, $e2));
    }

    public function test_notifications_never_touch_the_ledger(): void
    {
        $e1 = $this->e1();
        $lines = DB::table('ledger_entries')->count();
        $this->bare($this->kdyBa)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->cancel($e1->id)->assertOk();
        $this->assertSame($lines, DB::table('ledger_entries')->count());
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
    }
}
