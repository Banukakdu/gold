<?php

namespace Tests\Feature\Notifications;

use App\Events\TransferApprovalRequested;
use App\Events\TransferReceiptRequested;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Notifications\InAppNotification as N;
use App\Notifications\TransferApprovalDecidedNotification;
use App\Notifications\TransferApprovalRequestedMail;
use App\Services\InAppNotifications;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/** docs/specs/notifications.md AC 1-10, 13, 30a, 30c: who gets which row, R6 auto-read and the approval email. */
class TransferNotificationsTest extends NotificationsTestCase
{
    public function test_ac1_approval_request_goes_to_main_approvers_and_super_admins_only(): void
    {
        $t1 = $this->t1();
        $this->assertSame(self::ids([$this->mainBa, $this->admin]), $this->recipients(N::APPROVAL_REQUESTED));
        foreach ([$this->mainBa, $this->admin] as $u) {
            $rows = $this->rows($u, N::APPROVAL_REQUESTED);
            $this->assertCount(1, $rows);
            $n = $rows[0];
            $this->assertNull($n->read_at);
            $this->assertSame($t1->id, $n->data['transaction_id']);
            $this->assertSame('MAIN', $n->data['branch_code']);
            $this->assertSame($this->main->id, $n->data['branch_id']);
            $this->assertSame("/transactions/{$t1->id}", $n->data['url']);
            $this->assertSame("{$t1->ref_no} needs approval", $n->data['title']);
            $this->assertSame('KDY -> GAL, 150,000.00, requested by A. Perera', $n->data['body']);
        }
        foreach ([$this->acc, $this->mainAccUser, $this->mainBa2, $this->kdyBa] as $u) {
            $this->assertCount(0, $this->rows($u), $u->name);
        }
    }

    public function test_ac2_approval_email_only_to_approvers_who_keep_it_on_and_links_to_their_row(): void
    {
        $this->admin->forceFill(['approval_request_email' => false])->save();
        $this->t1();
        $row = $this->rows($this->mainBa, N::APPROVAL_REQUESTED)->sole();
        Notification::assertSentTimes(TransferApprovalRequestedMail::class, 1);
        Notification::assertNotSentTo($this->admin, TransferApprovalRequestedMail::class);
        Notification::assertSentTo($this->mainBa, TransferApprovalRequestedMail::class, function (TransferApprovalRequestedMail $m, array $channels) use ($row) {
            $mail = $m->toMail($this->mainBa);

            return $channels === ['mail'] && $m->notificationId === $row->id && str_ends_with($mail->actionUrl, "/notifications/{$row->id}")
                && $mail->subject === 'Transfer '.Transaction::withoutGlobalScopes()->find($m->transactionId)->ref_no.' needs your approval';
        });
        // In-app rows are created regardless of the email preference.
        $this->assertCount(1, $this->rows($this->admin, N::APPROVAL_REQUESTED));
    }

    public function test_ac3_approval_tells_the_requester_and_closes_the_other_approvers_rows(): void
    {
        $t1 = $this->t1();
        $this->approve($t1->id)->assertOk();
        $decided = $this->rows($this->acc, N::APPROVAL_DECIDED)->sole();
        $this->assertNull($decided->read_at);
        $this->assertSame("Your transfer request {$t1->ref_no} was approved and posted.", $decided->data['title']);
        $this->assertSame('KDY', $decided->data['branch_code']);
        $this->assertSame("/transactions/{$t1->id}", $decided->data['url']);
        Notification::assertSentTo($this->acc, TransferApprovalDecidedNotification::class);
        $this->assertNotNull($this->rows($this->admin, N::APPROVAL_REQUESTED)->sole()->read_at);
        $this->assertSame([(int) $this->acc->id], $this->recipients(N::APPROVAL_DECIDED));
        $this->assertCount(0, $this->rows($this->mainBa, N::APPROVAL_DECIDED));
    }

    public function test_ac4_reject_names_the_approver_and_never_copies_the_comment(): void
    {
        $t1 = $this->t1();
        $this->reject($t1->id, 'Split this across two weeks please')->assertOk();
        $n = $this->rows($this->acc, N::APPROVAL_DECIDED)->sole();
        $this->assertStringContainsString('rejected by', $n->data['body']);
        $this->assertStringContainsString('M. Silva', $n->data['body']);
        $this->assertSame("Your transfer request {$t1->ref_no} was rejected by M. Silva.", $n->data['title']);
        $this->assertStringNotContainsString('Split this', json_encode($n->data));
        $this->assertStringNotContainsString('Cash for GAL', json_encode($n->data));
    }

    public function test_ac5_cancel_creates_no_decided_row_and_closes_the_requests(): void
    {
        $t1 = $this->t1();
        $this->cancel($t1->id)->assertOk();
        $this->assertSame([], $this->recipients(N::APPROVAL_DECIDED));
        foreach ([$this->mainBa, $this->admin] as $u) {
            $this->assertNotNull($this->rows($u, N::APPROVAL_REQUESTED)->sole()->read_at);
        }
    }

    public function test_ac6_expiry_tells_the_requester_and_closes_the_requests(): void
    {
        $t1 = $this->t1();
        Carbon::setTestNow('2026-12-20 10:00:00');
        $this->artisan('transfers:expire-approvals')->assertExitCode(0);
        $n = $this->rows($this->acc, N::APPROVAL_DECIDED)->sole();
        $this->assertSame("Your transfer request {$t1->ref_no} expired after 30 days.", $n->data['title']);
        foreach ([$this->mainBa, $this->admin] as $u) {
            $this->assertNotNull($this->rows($u, N::APPROVAL_REQUESTED)->sole()->read_at);
        }
    }

    public function test_ac7_receipt_request_goes_to_receiving_branch_confirmers(): void
    {
        $t2 = $this->t2();
        foreach ([$this->galCsh, $this->galAcc] as $u) {
            $n = $this->rows($u, N::RECEIPT_REQUESTED)->sole();
            $this->assertSame('GAL', $n->data['branch_code']);
            $this->assertSame($t2->id, $n->data['transaction_id']);
            $this->assertSame("Confirm receipt of {$t2->ref_no} from KDY: 10,000.00", $n->data['title']);
        }
        foreach ([$this->galVw, $this->acc, $this->admin] as $u) {
            $this->assertCount(0, $this->rows($u), $u->name);
        }
        // Holders of transfer.acknowledge in GAL only (GAL Branch Admin has every key); nobody in KDY or MAIN.
        $this->assertSame(self::ids([$this->galCsh, $this->galAcc, $this->ba]), $this->recipients(N::RECEIPT_REQUESTED, $t2));
    }

    public function test_ac8_dispute_goes_to_sender_reversers_and_super_admins_and_closes_requests(): void
    {
        $t2 = $this->t2();
        $this->dispute($t2)->assertOk();
        $this->assertSame(self::ids([$this->acc, $this->kdyBa, $this->admin]), $this->recipients(N::RECEIPT_DISPUTED, $t2));
        foreach ([$this->acc, $this->kdyBa, $this->admin] as $u) {
            $n = $this->rows($u, N::RECEIPT_DISPUTED)->sole();
            $this->assertSame('KDY', $n->data['branch_code']);
            $this->assertSame("GAL reported a discrepancy on {$t2->ref_no} (10,000.00)", $n->data['title']);
            $this->assertStringNotContainsString('Short by 500', json_encode($n->data));
        }
        $this->assertCount(0, $this->rows($this->galCsh, N::RECEIPT_DISPUTED));
        foreach ([$this->galCsh, $this->galAcc] as $u) {
            $this->assertNotNull($this->rows($u, N::RECEIPT_REQUESTED)->sole()->read_at);
        }
    }

    public function test_ac9_settlement_after_a_dispute_only(): void
    {
        $t2 = $this->t2();
        $this->dispute($t2)->assertOk();
        $this->confirmReceipt($t2, note: 'Bag recounted, the 500 was found')->assertOk();
        $this->assertSame(self::ids([$this->acc, $this->kdyBa, $this->admin]), $this->recipients(N::RECEIPT_SETTLED, $t2));
        $this->assertSame("GAL confirmed receipt of {$t2->ref_no} after a discrepancy", $this->rows($this->acc, N::RECEIPT_SETTLED)->sole()->data['title']);

        $t3 = $this->t2('2000.00');
        $this->confirmReceipt($t3)->assertOk();
        $this->assertSame([], $this->recipients(N::RECEIPT_SETTLED, $t3));
        $this->assertSame([], $this->recipients(N::RECEIPT_DISPUTED, $t3));
        $this->assertCount(1, $this->rows($this->acc, N::RECEIPT_SETTLED), 'no row for the sender of T3');
        $this->assertNotNull($this->rows($this->galCsh, N::RECEIPT_REQUESTED)->firstWhere('data.transaction_id', $t3->id)->read_at);
    }

    public function test_ac10_fund_return_dispute_goes_to_the_creator_and_super_admins_only(): void
    {
        $id = $this->as($this->galAcc, $this->gal)->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id,
            'to_account_id' => $this->mainAcc->id, 'amount' => '1000.00', 'txn_date' => '2026-11-20', 'note' => 'Surplus'])->assertCreated()->json('id');
        $fr = $this->txn($id);
        $this->assertSame(Transaction::FUND_RETURN, $fr->type);
        $this->as($this->mainAccUser, $this->main)->postJson("/api/v1/transactions/{$fr->id}/receipt",
            ['action' => 'discrepancy', 'note' => 'Short by 100 rupees'])->assertOk();
        $this->assertSame(self::ids([$this->galAcc, $this->admin]), $this->recipients(N::RECEIPT_DISPUTED, $fr));
        $this->assertSame('GAL', $this->rows($this->galAcc, N::RECEIPT_DISPUTED)->sole()->data['branch_code']);
        // GAL Branch Admin holds transfer.reverse but is not the creator.
        $this->assertCount(0, $this->rows($this->ba, N::RECEIPT_DISPUTED));
    }

    public function test_ac13_a_failing_in_app_write_never_fails_the_approval(): void
    {
        $t1 = $this->t1();
        Exceptions::fake();
        $this->app->instance(InAppNotifications::class, new class extends InAppNotifications
        {
            public function write($user, string $kind, array $data): string
            {
                throw new RuntimeException('no such table: notifications');
            }

            public function markReadFor(string $kind, int $transactionId): int
            {
                throw new RuntimeException('no such table: notifications');
            }
        });
        $this->approve($t1->id)->assertOk()->assertJsonPath('status', 'posted');
        $this->assertSame('posted', $t1->fresh()->status);
        $this->assertSame('50000.00', $this->bal($this->kdyCash));
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'no such table: notifications');
        Notification::assertSentTo($this->acc, TransferApprovalDecidedNotification::class);
    }

    public function test_ac30a_a_dispute_reaches_a_super_admin_when_the_sender_is_the_only_reverser(): void
    {
        $this->acc->update(['status' => 'inactive']);
        $t2 = $this->t2('10000.00', $this->kdyBa);
        $this->dispute($t2)->assertOk();
        $this->assertCount(1, $this->rows($this->admin, N::RECEIPT_DISPUTED));
        $this->assertSame(self::ids([$this->kdyBa, $this->admin]), $this->recipients(N::RECEIPT_DISPUTED, $t2));
    }

    public function test_ac30c_queued_approval_email_rechecks_the_recipient_and_carries_no_balance_or_note(): void
    {
        $t1 = $this->t1();
        $mail = Notification::sent($this->mainBa, TransferApprovalRequestedMail::class)->sole();
        $send = fn () => (new ChannelManager($this->app))->sendNow($this->mainBa->fresh(), $mail);
        $messages = fn () => Mail::mailer()->getSymfonyTransport()->messages();

        // Still active and still an approver: sent. Amount and branch codes, no balance, no "available", no note.
        $send();
        $this->assertCount(1, $messages());
        $body = $messages()[0]->getOriginalMessage()->getTextBody() ?? '';
        $html = $messages()[0]->getOriginalMessage()->getHtmlBody() ?? '';
        foreach ([$body, $html] as $text) {
            $this->assertStringContainsString('150,000.00', $text);
            $this->assertStringContainsString('KDY', $text);
            $this->assertStringContainsString('GAL', $text);
            $this->assertStringContainsString("Transfer {$t1->ref_no} needs your approval", $text);
            $this->assertStringNotContainsStringIgnoringCase('available', $text);
            $this->assertStringNotContainsStringIgnoringCase('balance', $text);
            $this->assertStringNotContainsString('200,000.00', $text);
            $this->assertStringNotContainsString('Cash for GAL', $text);
        }

        // Lost transfer.approve in MAIN (moved to the Accountant role) before the queue ran: dropped.
        setPermissionsTeamId($this->main->id);
        $this->mainBa->syncRoles([$this->branchRole($this->main, 'Accountant')]);
        $send();
        $this->assertCount(1, $messages());

        // Deactivated before the queue ran: dropped.
        setPermissionsTeamId($this->main->id);
        $this->mainBa->syncRoles([$this->branchRole($this->main, 'Branch Admin')]);
        $this->mainBa->update(['status' => 'inactive']);
        $send();
        $this->assertCount(1, $messages());
        $this->mainBa->update(['status' => 'active']);
        $send();
        $this->assertCount(2, $messages());
    }

    public function test_r10_creating_and_auto_reading_rows_writes_no_audit_row(): void
    {
        $t1 = $this->t1();
        $before = AuditLog::count();
        $this->reject($t1->id)->assertOk();
        $this->assertSame(['transfer.rejected'], AuditLog::where('id', '>', AuditLog::max('id') - (AuditLog::count() - $before))->pluck('event')->all());
        $this->assertSame(0, AuditLog::where('event', 'like', '%notification%')->count());
        $this->assertSame(0, DB::table('notifications')->where('type', N::APPROVAL_REQUESTED)->whereNull('read_at')->count());
    }

    public function test_review2_queued_approval_email_is_dropped_when_decided_or_switched_off(): void
    {
        $t1 = $this->t1();
        $mail = Notification::sent($this->mainBa, TransferApprovalRequestedMail::class)->sole();
        $this->assertTrue($mail->shouldSend($this->mainBa, 'mail'));

        $this->mainBa->forceFill(['approval_request_email' => false])->save();
        $this->assertFalse($mail->shouldSend($this->mainBa, 'mail'));
        $this->mainBa->forceFill(['approval_request_email' => true])->save();
        $this->assertTrue($mail->shouldSend($this->mainBa, 'mail'));

        $this->cancel($t1->id)->assertOk();
        $this->assertFalse($mail->shouldSend($this->mainBa, 'mail'));
        (new ChannelManager($this->app))->sendNow($this->mainBa->fresh(), $mail);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());

        $t2 = $this->t1('120000.00');
        $mail2 = Notification::sent($this->mainBa, TransferApprovalRequestedMail::class)->firstWhere('transactionId', $t2->id);
        $this->approve($t2->id, $this->admin, 'all')->assertOk();
        $this->assertFalse($mail2->shouldSend($this->mainBa, 'mail'));
    }

    public function test_review7_the_events_carry_the_actor_who_is_never_notified(): void
    {
        $t1 = $this->t1();
        DB::table('notifications')->delete();
        // A console dispatch (no signed-in user) still excludes the user named by the event.
        $this->app['auth']->forgetGuards();
        event(new TransferApprovalRequested($t1->id, $this->mainBa->id));
        $this->assertSame([(int) $this->admin->id], $this->recipients(N::APPROVAL_REQUESTED, $t1));

        $t2 = $this->t2();
        DB::table('notifications')->delete();
        $this->app['auth']->forgetGuards();
        event(new TransferReceiptRequested($t2->id, $this->galAcc->id));
        $this->assertSame(self::ids([$this->galCsh, $this->ba]), $this->recipients(N::RECEIPT_REQUESTED, $t2));
    }
}
