<?php

namespace Tests\Feature\TransferApprovals;

use App\Events\TransferReceiptRequested;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Event;

/** AC 7-14, 32 and the approval half of 33: who approves, what approval posts and when, and what it refuses. */
class ApproveTest extends TransferApprovalTestCase
{
    public function test_ac7_approval_posts_the_six_lines_on_the_approval_date(): void
    {
        $t = $this->t1();
        $this->approve($t->id, body: ['comment' => 'OK, cash for the GAL event'])->assertOk()
            ->assertJsonPath('status', 'posted')->assertJsonPath('approval_status', 'approved')->assertJsonPath('txn_date', '2026-11-20')
            ->assertJsonPath('requested_date', '2026-11-18')->assertJsonPath('ref_no', $t->ref_no)->assertJsonCount(6, 'entries');

        $this->assertSame([
            'KDY Cr Cash 150000.00', 'KDY Dr Head office current account 150000.00',
            'MAIN Dr Branch current account - GAL 150000.00', 'MAIN Cr Branch current account - KDY 150000.00',
            'GAL Dr Cash 150000.00', 'GAL Cr Head office current account 150000.00',
        ], $this->normalised($t->id));
        $this->assertSame(['2026-11-20'], LedgerEntry::where('transaction_id', $t->id)->get()->map(fn ($l) => $l->entry_date->format('Y-m-d'))->unique()->values()->all());
        $this->assertSame('50000.00', $this->bal($this->kdyCash));
        $this->assertSame('200000.00', $this->bal($this->galCash));

        $t->refresh();
        $this->assertSame('2026-11-20', $t->txn_date->format('Y-m-d'));
        $this->assertSame('2026-11-18', $t->requested_date->format('Y-m-d'));
        $this->assertSame($this->mainBa->id, (int) $t->approved_by);
        $this->assertSame($this->mainBa->id, (int) $t->decided_by);
        $this->assertNotNull($t->approved_at);
        $this->assertNotNull($t->decided_at);
        $this->assertSame('OK, cash for the GAL event', $t->approval_note);

        $events = AuditLog::where('auditable_type', $t::class)->where('auditable_id', $t->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['transfer.approval_requested', 'transfer.approved', 'transaction.branch_transfer'], $events);
        // AC 33 (finding 12): available_before and approver_branch.
        $this->assertSame(self::sorted(['approval_status' => 'approved', 'posting_date' => '2026-11-20', 'requested_date' => '2026-11-18',
            'comment' => 'OK, cash for the GAL event', 'all_branches' => false, 'available_before' => '200000.00', 'approver_branch' => 'MAIN', 'ref_no' => $t->ref_no]),
            self::sorted($this->audits($t, 'transfer.approved')[0]->new_values));
        $this->assertSame(['approval_status' => 'pending'], $this->audits($t, 'transfer.approved')[0]->old_values);
        $this->assertSame('2026-11-20', $this->audits($t, 'transaction.branch_transfer')[0]->new_values['txn_date']);
        $this->assertBalancedPerBranch($t->id);
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_ac8_the_receipt_starts_only_after_approval(): void
    {
        Event::fake([TransferReceiptRequested::class]);
        $this->sa()->putJson('/api/v1/settings', ['transfer_receipt_required' => true])->assertOk();
        $t = $this->t1();
        $this->assertNull($t->receipt_status);
        $galCsh = $this->userIn($this->gal, 'Cashier');
        $this->assertSame([], $this->as($galCsh, $this->gal)->getJson('/api/v1/transfer-receipts')->assertOk()->json('incoming.data'));
        Event::assertNotDispatched(TransferReceiptRequested::class);

        $this->approve($t->id)->assertOk()->assertJsonPath('receipt_status', 'awaiting');
        Event::assertDispatchedTimes(TransferReceiptRequested::class, 1);
        $this->assertSame([$t->id], array_column($this->as($galCsh, $this->gal)->getJson('/api/v1/transfer-receipts')->json('incoming.data'), 'id'));
        $this->assertSame('awaiting', $this->audits($t, 'transaction.branch_transfer')[0]->new_values['receipt_status']);
    }

    public function test_ac9_funds_are_checked_again_at_approval(): void
    {
        $t = $this->t1();
        $this->expense('2026-11-19', '60000.00');
        $this->assertSame('140000.00', $this->bal($this->kdyCash));
        $before = $this->state();
        $this->approve($t->id)->assertStatus(422)->assertJsonPath('message', 'Insufficient balance in Cash (available 140000.00).');
        $this->assertSame($before, $this->state());
        $this->assertSame('pending', $t->fresh()->approval_status);
        $this->assertSame(0, LedgerEntry::where('transaction_id', $t->id)->count());

        // Section 7 step 4.
        $this->income('2026-11-20', '20000.00');
        $this->approve($t->id)->assertOk();
        $this->assertSame('10000.00', $this->bal($this->kdyCash));
        $this->assertSame('200000.00', $this->bal($this->galCash));
    }

    public function test_ac10_a_locked_requested_month_posts_on_the_approval_date(): void
    {
        $t = $this->t1('150000.00', '2026-10-30');
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $this->approve($t->id)->assertOk()->assertJsonPath('txn_date', '2026-11-20');
        $this->assertSame(['2026-11-20'], LedgerEntry::where('transaction_id', $t->id)->pluck('entry_date')->map(fn ($d) => $d->format('Y-m-d'))->unique()->values()->all());
        $this->assertSame('2026-10-30', $t->fresh()->requested_date->format('Y-m-d'));

        // Today's month locked for a branch with a line (abnormal, test only): refused, still pending.
        $this->threshold('0');
        $t2 = $this->t1('20000.00', '2026-11-19');
        $this->lock('2026-11', $this->kdy)->assertCreated();
        $before = $this->state();
        $this->approve($t2->id)->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked.');
        $this->assertSame($before, $this->state());
        $this->assertSame('pending', $t2->fresh()->approval_status);
    }

    public function test_ac11_a_posting_date_in_the_body_is_ignored(): void
    {
        $t = $this->t1();
        $this->approve($t->id, body: ['posting_date' => '2026-11-18'])->assertOk()->assertJsonPath('txn_date', '2026-11-20');
        $this->assertSame(['2026-11-20'], LedgerEntry::where('transaction_id', $t->id)->pluck('entry_date')->map(fn ($d) => $d->format('Y-m-d'))->unique()->values()->all());
    }

    public function test_ac12_only_main_approves(): void
    {
        $t = $this->t1();
        $before = $this->state();
        $this->approve($t->id, $this->kdyBa, $this->kdy)->assertForbidden()->assertJsonPath('message', 'Transfers are approved by Main.');
        $this->reject($t->id, by: $this->kdyBa, branch: $this->kdy)->assertForbidden()->assertJsonPath('message', 'Transfers are approved by Main.');
        $this->approve($t->id, $this->mainAccUser, $this->main)->assertForbidden()->assertJsonPath('message', 'Missing permission: transfer.approve');
        $this->assertSame($before, $this->state());
    }

    public function test_ac13_the_requester_never_approves_even_from_main(): void
    {
        $this->acc->branches()->attach($this->main->id);
        setPermissionsTeamId($this->main->id);
        $this->acc->assignRole($this->branchRole($this->main, 'Branch Admin'));
        $t = $this->t1();
        $before = $this->state();
        $msg = 'You requested this transfer. Someone else in Main must approve it.';
        $this->approve($t->id, $this->acc, $this->main)->assertForbidden()->assertJsonPath('message', $msg);
        $this->reject($t->id, by: $this->acc, branch: $this->main)->assertForbidden()->assertJsonPath('message', $msg);
        $this->assertSame($before, $this->state());
    }

    public function test_ac14_the_super_admin_approves_in_all_mode(): void
    {
        $t = $this->t1();
        $this->approve($t->id, $this->admin, 'all')->assertOk()->assertJsonPath('status', 'posted');
        $this->assertSame('all', $this->audits($t, 'transfer.approved')[0]->new_values['approver_branch']);
        $this->assertTrue($this->audits($t, 'transfer.approved')[0]->new_values['all_branches']);
    }

    public function test_ac32_split_detection_line_and_inactive_requester(): void
    {
        $this->request('90000.00', '2026-11-19')->assertCreated()->assertJsonPath('status', 'posted');
        $this->request('5000.00', '2026-11-13')->assertCreated(); // outside the 7 days
        $t = $this->t1('100000.01');
        $row = collect($this->widget($this->mainBa, $this->main)['to_approve']['data'])->firstWhere('id', $t->id);
        $this->assertSame(['count' => 1, 'amount' => '90000.00'], $row['below_threshold_7d']);
        $this->assertFalse($row['requester_inactive']);

        $this->acc->update(['status' => 'inactive']);
        $row = collect($this->widget($this->mainBa, $this->main)['to_approve']['data'])->firstWhere('id', $t->id);
        $this->assertTrue($row['requester_inactive']);
        $this->approve($t->id)->assertOk();
    }

    /** Lines as "BRANCH Dr|Cr <account> <amount>", with the generated account names reduced to their meaning. */
    private function normalised(int $id): array
    {
        $names = [$this->ho($this->kdy)->name => 'Head office current account', $this->ho($this->gal)->name => 'Head office current account',
            $this->bca($this->kdy)->name => 'Branch current account - KDY', $this->bca($this->gal)->name => 'Branch current account - GAL'];

        return array_map(fn ($s) => strtr($s, $names), $this->sides($id));
    }
}
