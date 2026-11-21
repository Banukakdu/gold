<?php

namespace Tests\Feature\TransferApprovals;

use App\Events\TransferApprovalDecided;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Notifications\TransferApprovalDecidedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/** AC 15-20: reject, cancel, expiry, decided requests, races and the requester's email. */
class DecisionsTest extends TransferApprovalTestCase
{
    public function test_ac15_reject_with_a_reason_posts_nothing(): void
    {
        $t = $this->t1();
        $before = $this->state();
        $this->reject($t->id, null)->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->reject($t->id, '123456789')->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->reject($t->id, '         ')->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->assertSame($before, $this->state());

        $entries = LedgerEntry::count();
        $this->reject($t->id)->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('approval_status', 'rejected')
            ->assertJsonPath('approval_note', 'Split into two smaller transfers')->assertJsonPath('decided_by.id', $this->mainBa->id);
        $this->assertSame($entries, LedgerEntry::count());
        $this->assertSame(0, LedgerEntry::where('transaction_id', $t->id)->count());
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
        $this->assertSame('50000.00', $this->bal($this->galCash));
        $a = $this->audits($t, 'transfer.rejected');
        $this->assertCount(1, $a);
        $this->assertSame(['approval_status' => 'pending'], $a[0]->old_values);
        $this->assertSame(self::sorted(['approval_status' => 'rejected', 'comment' => 'Split into two smaller transfers', 'ref_no' => $t->ref_no, 'all_branches' => false]),
            self::sorted($a[0]->new_values));
    }

    public function test_ac16_only_the_requester_cancels_and_no_email_is_sent(): void
    {
        $t = $this->t1();
        $before = $this->state();
        $this->cancel($t->id, $this->kdyBa)->assertForbidden()->assertJsonPath('message', 'Only the requester can cancel this request.');
        $this->assertSame($before, $this->state());

        $this->cancel($t->id)->assertOk()->assertJsonPath('approval_status', 'cancelled')->assertJsonPath('status', 'rejected');
        $this->assertCount(1, $this->audits($t, 'transfer.approval_cancelled'));
        Notification::assertNothingSent();
    }

    public function test_ac17_expiry_on_the_business_date_of_the_request(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        $old = $this->t1('150000.00', '2026-10-21');
        Carbon::setTestNow('2026-10-22 10:00:00');
        $young = $this->t1('100000.01', '2026-10-22');
        $this->assertSame('2026-10-21', $old->requested_on->format('Y-m-d'));

        Carbon::setTestNow('2026-11-20 10:00:00');
        $row = collect($this->widget($this->acc, $this->kdy)['my_requests']['data'])->keyBy('id');
        $this->assertSame('2026-11-20', $row[$old->id]['expires_on']);
        $this->assertSame('2026-11-21', $row[$young->id]['expires_on']);

        $this->artisan('transfers:expire-approvals')->assertExitCode(0);
        $old->refresh();
        $this->assertSame(['rejected', 'expired'], [$old->status, $old->approval_status]);
        $this->assertNull($old->decided_by);
        $this->assertSame('pending', $young->fresh()->approval_status);
        $a = $this->audits($old, 'transfer.approval_expired');
        $this->assertCount(1, $a);
        $this->assertNull($a[0]->user_id);
        $this->assertSame('2026-11-20', $a[0]->new_values['expired_on']);
        Notification::assertSentToTimes($this->acc, TransferApprovalDecidedNotification::class, 1);

        // Running again changes nothing.
        $count = AuditLog::count();
        $this->artisan('transfers:expire-approvals')->assertExitCode(0);
        $this->assertSame($count, AuditLog::count());
    }

    public function test_the_expiry_uses_the_colombo_date_not_the_utc_date(): void
    {
        // 2026-10-21 00:30 in Colombo is still 2026-10-20 in UTC (finding 11).
        Carbon::setTestNow('2026-10-20 19:00:00');
        $t = $this->t1('150000.00', '2026-10-20');
        $this->assertSame('2026-10-21', $t->requested_on->format('Y-m-d'));
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->artisan('transfers:expire-approvals')->assertExitCode(0);
        $this->assertSame('pending', $t->fresh()->approval_status);
    }

    public function test_ac18_a_decided_request_refuses_every_decision(): void
    {
        $cases = [
            'approved' => fn (Transaction $t) => $this->approve($t->id)->assertOk(),
            'rejected' => fn (Transaction $t) => $this->reject($t->id)->assertOk(),
            'cancelled' => fn (Transaction $t) => $this->cancel($t->id)->assertOk(),
            'expired' => function (Transaction $t) {
                Transaction::withoutGlobalScopes()->whereKey($t->id)->update(['requested_on' => '2026-10-01']);
                $this->artisan('transfers:expire-approvals')->assertExitCode(0);
            },
        ];
        foreach ($cases as $state => $decide) {
            $this->threshold('0');
            $t = $this->t1('20000.00', '2026-11-19');
            $decide($t);
            $this->assertSame($state, $t->fresh()->approval_status);
            $before = $this->state();
            $msg = match ($state) {
                'approved' => "This request was already approved by {$this->mainBa->name} on 2026-11-20.",
                'rejected' => "This request was already rejected by {$this->mainBa->name} on 2026-11-20.",
                'cancelled' => 'This request was already cancelled by the requester on 2026-11-20.',
                'expired' => 'This request was already expired on 2026-11-20.',
            };
            $this->approve($t->id)->assertStatus(422)->assertJsonPath('message', $msg);
            $this->reject($t->id)->assertStatus(422)->assertJsonPath('message', $msg);
            $this->cancel($t->id)->assertStatus(422)->assertJsonPath('message', $msg);
            $this->assertSame($before, $this->state(), $state);
            $this->threshold('100000.00');
        }

        // A transfer that never needed approval.
        $posted = $this->request('500.00')->assertCreated()->json('id');
        $this->approve($posted)->assertStatus(422)->assertJsonPath('message', 'This entry is not waiting for approval.');
        $this->approve(999999)->assertNotFound();
    }

    public function test_ac19_one_decision_wins(): void
    {
        $t = $this->t1();
        $this->approve($t->id)->assertOk();
        $other = $this->userIn($this->main, 'Branch Admin');
        $this->approve($t->id, $other)->assertStatus(422);
        $this->cancel($t->id)->assertStatus(422);
        $this->assertSame(6, LedgerEntry::where('transaction_id', $t->id)->count());
        $this->assertCount(1, $this->audits($t, 'transaction.branch_transfer'));
        $this->assertCount(1, $this->audits($t, 'transfer.approved'));

        // Approve and cancel: the cancel wins, the approval posts nothing.
        $this->threshold('0');
        $t2 = $this->t1('20000.00');
        $this->cancel($t2->id)->assertOk();
        $this->approve($t2->id)->assertStatus(422);
        $this->assertSame(0, LedgerEntry::where('transaction_id', $t2->id)->count());
        $this->assertCount(0, $this->audits($t2, 'transaction.branch_transfer'));
    }

    public function test_ac20_the_requester_is_told_once_per_decision(): void
    {
        $approved = $this->t1();
        $this->approve($approved->id)->assertOk();
        Notification::assertSentToTimes($this->acc, TransferApprovalDecidedNotification::class, 1);
        $this->threshold('0');
        $rejected = $this->t1('1000.00');
        $this->reject($rejected->id)->assertOk();
        Notification::assertSentToTimes($this->acc, TransferApprovalDecidedNotification::class, 2);
        Notification::assertSentTo($this->acc, TransferApprovalDecidedNotification::class,
            fn ($n) => $n->transactionId === $rejected->id && $n->state === 'rejected'
                && $n->toMail($this->acc)->subject === "Transfer {$rejected->ref_no} rejected");
        $mail = (new TransferApprovalDecidedNotification($approved->id, 'approved'))->toMail($this->acc);
        $this->assertSame("Transfer {$approved->ref_no} approved", $mail->subject);
        $this->assertStringEndsWith("/transactions/{$approved->id}", $mail->actionUrl);
        $this->assertContains('Posting date: 2026-11-20.', $mail->introLines);

        $cancelled = $this->t1('1000.00');
        $this->cancel($cancelled->id)->assertOk();
        Notification::assertSentToTimes($this->acc, TransferApprovalDecidedNotification::class, 2);

        $inactive = $this->t1('1000.00');
        $this->acc->update(['status' => 'inactive']);
        $this->approve($inactive->id)->assertOk();
        Notification::assertSentToTimes($this->acc, TransferApprovalDecidedNotification::class, 2);
    }

    public function test_ac20_one_event_per_decision_and_none_on_refusal(): void
    {
        Event::fake([TransferApprovalDecided::class]);
        $this->threshold('0');
        $a = $this->t1('1000.00');
        $r = $this->t1('1000.00');
        $c = $this->t1('1000.00');
        $this->approve($a->id)->assertOk();
        $this->reject($r->id)->assertOk();
        $this->cancel($c->id)->assertOk();
        $this->approve($a->id)->assertStatus(422);
        $this->reject($r->id, 'short')->assertStatus(422);
        $this->approve($c->id, $this->kdyBa, $this->kdy)->assertForbidden();
        Event::assertDispatchedTimes(TransferApprovalDecided::class, 3);
        foreach ([[$a, 'approved', $this->mainBa->id], [$r, 'rejected', $this->mainBa->id], [$c, 'cancelled', $this->acc->id]] as [$t, $state, $by]) {
            Event::assertDispatched(TransferApprovalDecided::class, fn ($e) => $e->transactionId === $t->id && $e->state === $state && $e->userId === $by);
        }
    }
}
