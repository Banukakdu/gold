<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Events\ExpenseApprovalDecided;
use App\Exceptions\LedgerException;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/** AC 15-19: reject, cancel, expiry, already decided requests and races. */
class DecisionsTest extends ExpenseApprovalTestCase
{
    public function test_ac15_reject_with_a_reason_posts_nothing(): void
    {
        $e1 = $this->e1();
        $before = $this->state();
        $this->reject($e1->id, null)->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->reject($e1->id, '123456789')->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->assertSame($before, $this->state());

        $entries = LedgerEntry::count();
        $this->reject($e1->id)->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('approval_status', 'rejected')
            ->assertJsonPath('approval_note', 'Bill not attached, please add it')->assertJsonPath('decided_by.id', $this->kdyBa->id);
        $this->assertSame($entries, LedgerEntry::count());
        $this->assertSame(0, LedgerEntry::where('transaction_id', $e1->id)->count());
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
        $a = $this->audits($e1, 'expense.rejected');
        $this->assertCount(1, $a);
        $this->assertSame(['approval_status' => 'pending'], $a[0]->old_values);
        $this->assertSame(self::sorted(['approval_status' => 'rejected', 'comment' => 'Bill not attached, please add it', 'ref_no' => $e1->ref_no, 'all_branches' => false]),
            self::sorted($a[0]->new_values));
        $this->assertVerifies();
    }

    public function test_ac16_only_the_requester_cancels(): void
    {
        $e1 = $this->e1();
        $before = $this->state();
        $this->cancel($e1->id, $this->kdyBa)->assertForbidden()->assertJsonPath('message', 'Only the requester can cancel this request.');
        $this->assertSame($before, $this->state());
        $this->cancel($e1->id)->assertOk()->assertJsonPath('approval_status', 'cancelled')->assertJsonPath('status', 'rejected');
        $this->assertCount(1, $this->audits($e1, 'expense.approval_cancelled'));
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
    }

    public function test_ac17_expiry_after_30_days_on_the_business_date(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        $old = $this->e1('75000.00', '2026-10-21');
        Carbon::setTestNow('2026-10-22 10:00:00');
        $young = $this->e1('60000.00', '2026-10-22');
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->assertSame('2026-11-20', collect($this->widget($this->csh, $this->kdy)['my_requests']['data'])->firstWhere('id', $old->id)['expires_on']);
        Event::fake([ExpenseApprovalDecided::class]);

        $this->artisan('expenses:expire-approvals')->expectsOutput('Expired 1 expense request(s).')->assertExitCode(0);
        $this->assertSame(['rejected', 'expired'], [$this->txn($old->id)->status, $this->txn($old->id)->approval_status]);
        $this->assertSame('pending', $this->txn($young->id)->approval_status);
        $a = $this->audits($old, 'expense.approval_expired');
        $this->assertCount(1, $a);
        $this->assertNull($a[0]->user_id);
        $this->assertSame('2026-11-20', $a[0]->new_values['expired_on']);
        Event::assertDispatched(ExpenseApprovalDecided::class, fn ($e) => $e->transactionId === $old->id && $e->state === 'expired' && $e->userId === null);
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
        $this->assertVerifies();
    }

    public function test_r8_the_two_expiry_jobs_only_touch_their_own_kind(): void
    {
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => '100000.00'])->assertOk();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $t = $this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id,
            'to_account_id' => $this->galCash->id, 'amount' => '150000.00', 'txn_date' => '2026-10-21', 'note' => 'Cash for GAL'])->json('id');
        $e = $this->e1('75000.00', '2026-10-21');
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->artisan('expenses:expire-approvals')->assertExitCode(0);
        $this->assertSame(['pending', 'expired'], [$this->txn($t)->approval_status, $this->txn($e->id)->approval_status]);
        $this->artisan('transfers:expire-approvals')->assertExitCode(0);
        $this->assertSame('expired', $this->txn($t)->approval_status);
        $this->assertCount(0, $this->audits($this->txn($t), 'expense.approval_expired'));
    }

    public function test_ac18_a_decided_request_cannot_be_decided_again(): void
    {
        $approved = $this->e1();
        $this->approve($approved->id)->assertOk();
        $rejected = $this->e1('60000.00', '2026-11-19');
        $this->reject($rejected->id)->assertOk();
        $cancelled = $this->e1('60000.00', '2026-11-19');
        $this->cancel($cancelled->id)->assertOk();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $expired = $this->e1('60000.00', '2026-10-01');
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->artisan('expenses:expire-approvals')->assertExitCode(0);

        $name = $this->kdyBa->name;
        $cases = [
            [$approved, "This request was already approved by {$name} on 2026-11-20."],
            [$rejected, "This request was already rejected by {$name} on 2026-11-20."],
            [$cancelled, 'This request was already cancelled by the requester on 2026-11-20.'],
            [$expired, 'This request was already expired on 2026-11-20.'],
        ];
        $audit = AuditLog::count();
        foreach ($cases as [$t, $message]) {
            $this->approve($t->id, $this->kdyBa2)->assertStatus(422)->assertJsonPath('message', $message);
            $this->reject($t->id, by: $this->kdyBa2)->assertStatus(422)->assertJsonPath('message', $message);
            $this->cancel($t->id)->assertStatus(422)->assertJsonPath('message', $message);
        }
        $this->assertSame($audit, AuditLog::count());
    }

    public function test_ac19_one_decision_wins(): void
    {
        $e1 = $this->e1();
        $this->approve($e1->id)->assertOk();
        $this->approve($e1->id, $this->kdyBa2)->assertStatus(422);
        $this->cancel($e1->id)->assertStatus(422);
        $this->assertSame(2, LedgerEntry::where('transaction_id', $e1->id)->count());
        $this->assertCount(1, $this->audits($e1, 'transaction.expense'));
        $this->assertCount(1, $this->audits($e1, 'expense.approved'));

        // Approve and cancel: the cancel wins, the approval posts nothing.
        $e2 = $this->e1('60000.00', '2026-11-19');
        $this->cancel($e2->id)->assertOk();
        $this->approve($e2->id)->assertStatus(422);
        $this->assertSame(0, LedgerEntry::where('transaction_id', $e2->id)->count());
        $this->assertCount(0, $this->audits($e2, 'transaction.expense'));
        $this->assertVerifies();
    }

    public function test_r10_the_conditional_update_refuses_a_row_changed_underneath(): void
    {
        // A decision that read the row as pending but finds it decided when it writes (the losing side of a race).
        $e1 = $this->e1();
        $this->cancel($e1->id)->assertOk();
        $t = $this->txn($e1->id);
        $t->approval_status = 'pending';
        $t->status = 'pending';
        $this->expectException(LedgerException::class);
        $this->actingAs($this->kdyBa);
        app(LedgerService::class)->postApproved($t, 'x');
    }
}
