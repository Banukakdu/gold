<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** AC 7-14, 20, 35b, 35c, 35d: approval posts on the approval date with every check repeated; who may decide. */
class ApproveTest extends ExpenseApprovalTestCase
{
    public function test_ac7_approval_posts_on_the_approval_date(): void
    {
        $e1 = $this->e1();
        $this->approve($e1->id, body: ['posting_date' => '2026-11-18', 'comment' => 'Bill checked, cash available'])->assertOk()
            ->assertJsonPath('status', 'posted')->assertJsonPath('approval_status', 'approved')->assertJsonPath('txn_date', '2026-11-20')
            ->assertJsonPath('requested_date', '2026-11-18')->assertJsonPath('ref_no', $e1->ref_no)->assertJsonCount(2, 'entries')
            ->assertJsonPath('decided_by.id', $this->kdyBa->id);
        $t = $this->txn($e1->id);
        $this->assertSame(['KDY Cr Cash 75000.00', 'KDY Dr Utilities > Electricity 75000.00'], $this->sides($t->id));
        $this->assertSame(['2026-11-20'], LedgerEntry::where('transaction_id', $t->id)->pluck('entry_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all());
        $this->assertSame('125000.00', $this->bal($this->kdyCash));
        $this->assertSame('75000.00', $this->bal($this->catAcc($this->electricity, $this->kdy)));
        $this->assertSame([(int) $this->kdyBa->id, (int) $this->kdyBa->id], [(int) $t->approved_by, (int) $t->decided_by]);
        $this->assertNotNull($t->approved_at);
        $this->assertNotNull($t->decided_at);
        $events = AuditLog::where('auditable_type', Transaction::class)->where('auditable_id', $t->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['expense.approval_requested', 'expense.approved', 'transaction.expense'], $events);
        $a = $this->audits($t, 'expense.approved')[0];
        $this->assertSame(['approval_status' => 'pending'], $a->old_values);
        $this->assertSame(self::sorted([
            'approval_status' => 'approved', 'posting_date' => '2026-11-20', 'requested_date' => '2026-11-18', 'comment' => 'Bill checked, cash available',
            'all_branches' => false, 'approver_branch' => 'KDY', 'available_before' => '200000.00', 'files_count' => 0,
            'paid_before_approval' => false, 'approver_onboarded_requester' => false, 'ref_no' => $t->ref_no,
        ]), self::sorted($a->new_values));
        $this->assertBalancedPerBranch($t->id);
        $this->assertVerifies();
    }

    public function test_ac8_funds_are_checked_again_at_approval(): void
    {
        $e1 = $this->e1();
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => null])->assertOk();
        $this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id,
            'to_account_id' => $this->galCash->id, 'amount' => '160000.00', 'txn_date' => '2026-11-19', 'note' => 'Cash for GAL'])
            ->assertCreated()->assertJsonPath('status', 'posted');
        $this->assertSame('40000.00', $this->bal($this->kdyCash));
        $before = $this->state();
        $this->approve($e1->id)->assertStatus(422)->assertJsonPath('message', 'Insufficient balance in KDY Cash (available 40,000.00).');
        $this->assertSame($before, $this->state());
        $this->assertSame('pending', $this->txn($e1->id)->approval_status);
        $this->assertSame(0, LedgerEntry::where('transaction_id', $e1->id)->count());

        // Section 7 step 4: income of 50,000.00, approval again.
        $this->income('2026-11-20', '50000.00');
        $this->approve($e1->id)->assertOk();
        $this->assertSame('15000.00', $this->bal($this->kdyCash));
        $this->assertVerifies();
    }

    public function test_ac9_warn_mode_lets_the_approval_take_the_account_below_zero(): void
    {
        $e1 = $this->e1();
        // Not editable through the API (settings spec); set as an installation would.
        Setting::findOrFail('expense_negative_balance')->fill(['value' => 'warn'])->save();
        $this->record('160000.00', '2026-11-19', $this->admin, branch: $this->kdy)->assertCreated()->assertJsonPath('status', 'posted');
        $this->assertSame('40000.00', $this->bal($this->kdyCash));
        $this->approve($e1->id)->assertOk();
        $this->assertSame('-35000.00', $this->bal($this->kdyCash));
    }

    public function test_ac10_a_category_deactivated_meanwhile_stops_the_approval(): void
    {
        $e1 = $this->e1();
        $this->electricity->update(['is_active' => false]);
        $before = $this->state();
        $this->approve($e1->id)->assertStatus(422)->assertJsonPath('message', 'Category must be an active expense category.');
        $this->assertSame($before, $this->state());
        $this->assertSame('pending', $this->txn($e1->id)->approval_status);
    }

    public function test_r15_inactive_account_or_parent_category_stops_the_approval(): void
    {
        $e1 = $this->e1();
        $this->electricity->parent->update(['is_active' => false]);
        $this->approve($e1->id)->assertStatus(422)->assertJsonPath('message', 'Category must be an active expense category.');
        $this->electricity->parent->update(['is_active' => true]);
        DB::table('accounts')->where('id', $this->kdyCash->id)->update(['status' => 'inactive']);
        $this->approve($e1->id)->assertStatus(422)->assertJsonPath('message', 'Account is inactive.');
        $this->assertSame('pending', $this->txn($e1->id)->approval_status);
    }

    public function test_ac11_locks_are_checked_on_the_approval_date_only(): void
    {
        $e1 = $this->e1();
        $this->lock('2026-11', $this->kdy)->assertCreated();
        $this->approve($e1->id)->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked.');
        $this->assertSame('pending', $this->txn($e1->id)->approval_status);
        $this->unlock($this->activeLock('2026-11', $this->kdy))->assertOk();

        $old = $this->e1('75000.00', '2026-10-30');
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $row = collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $old->id);
        $this->assertTrue($row['requested_period_locked']);
        $this->approve($old->id)->assertOk()->assertJsonPath('txn_date', '2026-11-20')->assertJsonPath('requested_date', '2026-10-30');
        $this->assertVerifies();
    }

    public function test_ac12_only_approvers_of_the_expense_branch_with_it_active(): void
    {
        $e1 = $this->e1();
        $both = $this->userIn($this->gal, 'Branch Admin');
        $both->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $both->assignRole($this->branchRole($this->kdy, 'Cashier'));
        $before = $this->state();

        $this->approve($e1->id, $this->acc)->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.approve');
        $this->approve($e1->id, $this->ba, $this->gal)->assertNotFound();
        $this->reject($e1->id, by: $this->ba, branch: $this->gal)->assertNotFound();
        $this->approve($e1->id, $this->mainBa, $this->main)->assertNotFound();
        $this->approve($e1->id, $both, $this->kdy)->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.approve');
        $this->approve($e1->id, $both, $this->gal)->assertNotFound();
        $this->assertSame($before, $this->state());
    }

    public function test_ac13_requester_never_and_branch_user_managers_only_by_a_super_admin(): void
    {
        $e2 = $this->e1('80000.00', '2026-11-20', $this->kdyBa);
        $this->assertTrue($this->audits($e2, 'expense.approval_requested')[0]->new_values['super_admin_only']);
        $this->grant($this->kdy, 'Accountant');
        $before = $this->state();

        $this->approve($e2->id, $this->kdyBa)->assertForbidden()->assertJsonPath('message', 'You recorded this expense. Someone else must approve it.');
        $msg = "This expense was recorded by a user who manages this branch's users. Only a Super Admin can approve it.";
        foreach ([$this->kdyBa2, $this->acc] as $u) {
            $this->approve($e2->id, $u)->assertForbidden()->assertJsonPath('message', $msg);
            $this->reject($e2->id, by: $u)->assertForbidden()->assertJsonPath('message', $msg);
        }
        $this->assertSame($before, $this->state());
        $this->assertSame('pending', $this->txn($e2->id)->approval_status);

        // The widget shows the row to branch approvers tagged, without buttons; the requester sees "Requested by you".
        $row = collect($this->widget($this->kdyBa2, $this->kdy)['to_approve']['data'])->firstWhere('id', $e2->id);
        $this->assertSame([true, false], [$row['super_admin_only'], $row['can_approve']]);
        $mine = collect($this->widget($this->kdyBa, $this->kdy)['my_requests']['data'])->firstWhere('id', $e2->id);
        $this->assertSame([true, false, true], [$mine['requested_by_you'], $mine['can_approve'], $mine['can_cancel']]);

        $this->approve($e2->id, $this->admin, 'all')->assertOk();
        $this->assertSame('120000.00', $this->bal($this->kdyCash));
    }

    public function test_r4a_is_derived_at_decision_time_too(): void
    {
        $e1 = $this->e1();
        $this->assertFalse($this->audits($e1, 'expense.approval_requested')[0]->new_values['super_admin_only']);
        // The Cashier is made a user manager after the request (e.g. promoted): the rule now applies.
        $this->grant($this->kdy, 'Cashier', 'user.create');
        $this->approve($e1->id)->assertForbidden();
        $this->approve($e1->id, $this->admin, $this->kdy)->assertOk();
    }

    public function test_ac14_super_admin_approves_in_all_branches_mode(): void
    {
        $e1 = $this->e1();
        $this->approve($e1->id, $this->admin, 'all')->assertOk()->assertJsonPath('status', 'posted');
        $this->assertSame('all', $this->audits($e1, 'expense.approved')[0]->new_values['approver_branch']);
        $this->assertTrue($this->audits($e1, 'expense.approved')[0]->new_values['all_branches']);
    }

    public function test_ac20_expense_and_transfer_routes_do_not_mix(): void
    {
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => '100000.00'])->assertOk();
        $t1 = $this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id,
            'to_account_id' => $this->galCash->id, 'amount' => '150000.00', 'txn_date' => '2026-11-18', 'note' => 'Cash for GAL'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $e1 = $this->e1();
        $this->approve($t1)->assertStatus(422)->assertJsonPath('message', 'This entry is not waiting for approval.');
        // Review 7: Main's transfer approver gets 404 for another branch's expense (no existence leak) ...
        $this->as($this->mainBa, $this->main)->postJson("/api/v1/transfer-approvals/{$e1->id}/approve", [])->assertNotFound();
        $this->as($this->mainBa, $this->main)->postJson("/api/v1/transfer-approvals/{$e1->id}/reject", ['comment' => 'Not a transfer at all'])->assertNotFound();
        // ... and 422 in the expense's own branch.
        $this->as($this->kdyBa, $this->kdy)->postJson("/api/v1/transfer-approvals/{$e1->id}/approve", [])
            ->assertStatus(422)->assertJsonPath('message', 'This entry is not waiting for approval.');
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transfer-approvals/{$e1->id}/cancel", [])->assertStatus(422);
        // A posted expense without approval: not waiting either.
        $posted = $this->record('100.00', '2026-11-20')->json('id');
        $this->approve($posted)->assertStatus(422)->assertJsonPath('message', 'This entry is not waiting for approval.');
        // The transfer widget does not list expense requests, the expense widget no transfer requests.
        $this->assertSame([$t1], array_column($this->as($this->mainBa, $this->main)->getJson('/api/v1/transfer-approvals')->json('to_approve.data'), 'id'));
        $this->assertSame([$e1->id], array_column($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'], 'id'));
    }

    public function test_ac35c_approving_without_a_file_needs_a_reason(): void
    {
        $e1 = $this->e1();
        $before = $this->state();
        foreach ([[], ['comment' => '123456789'], ['comment' => '          ']] as $body) {
            $this->approve($e1->id, body: $body)->assertStatus(422)->assertJsonValidationErrors('comment')
                ->assertJsonPath('errors.comment.0', 'Say why there is no receipt (10-500 characters).');
        }
        $this->assertSame($before, $this->state());

        // With a file, a missing comment is accepted.
        $this->as($this->csh, $this->kdy)->post("/api/v1/transactions/{$e1->id}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\n%test\n")], ['Accept' => 'application/json'])->assertCreated();
        $this->approve($e1->id, body: [])->assertOk();
        $this->assertSame(1, $this->audits($e1, 'expense.approved')[0]->new_values['files_count']);
    }

    public function test_ac35d_paid_before_approval_is_recorded(): void
    {
        $e1 = $this->e1();
        $this->approve($e1->id, body: ['comment' => 'Cash bill, no receipt given', 'paid_before_approval' => true])->assertOk();
        $this->assertTrue($this->audits($e1, 'expense.approved')[0]->new_values['paid_before_approval']);
        $e2 = $this->e1('60000.00', '2026-11-19');
        $this->approve($e2->id)->assertOk();
        $this->assertFalse($this->audits($e2, 'expense.approved')[0]->new_values['paid_before_approval']);
        $e3 = $this->e1('60000.00', '2026-11-19');
        $this->approve($e3->id, body: ['comment' => 'Cash bill, no receipt given', 'paid_before_approval' => 'maybe'])->assertStatus(422)
            ->assertJsonValidationErrors('paid_before_approval');
    }

    public function test_ac35b_approval_by_the_user_who_invited_the_requester_is_flagged(): void
    {
        $u = $this->invited($this->kdyBa, '2026-11-01 09:00:00');
        $e = $this->e1('75000.00', '2026-11-20', $u);
        $row = collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $e->id);
        $this->assertTrue($row['approver_onboarded_requester']);
        $this->assertFalse(collect($this->widget($this->kdyBa2, $this->kdy)['to_approve']['data'])->firstWhere('id', $e->id)['approver_onboarded_requester']);
        $this->approve($e->id)->assertOk();
        $this->assertTrue($this->audits($e, 'expense.approved')[0]->new_values['approver_onboarded_requester']);
        $rows = $this->sa()->getJson('/api/v1/audit-logs?from=2026-11-20&to=2026-11-20&approval=approver_onboarded_requester')->assertOk()->json('data');
        $this->assertSame([$e->id], array_column($rows, 'auditable_id'));

        // KDY Branch Admin 2 did not invite U: no flag.
        $e2 = $this->e1('60000.00', '2026-11-20', $u);
        $this->approve($e2->id, $this->kdyBa2)->assertOk();
        $this->assertFalse($this->audits($e2, 'expense.approved')[0]->new_values['approver_onboarded_requester']);

        // An invitation more than 90 days before requested_on does not flag.
        $old = $this->invited($this->kdyBa, '2026-08-21 09:00:00');
        $e3 = $this->e1('51000.00', '2026-11-20', $old);
        $this->approve($e3->id)->assertOk();
        $this->assertFalse($this->audits($e3, 'expense.approved')[0]->new_values['approver_onboarded_requester']);
    }

    /**
     * AC 35b, accountant sign-off MUST 6: R4b never applies to a Super Admin approver, even one who invited the requester:
     * no widget or detail flag, false in expense.approved, no flagged notification, no hit on the audit filter.
     */
    public function test_ac35b_a_super_admin_approver_is_never_flagged(): void
    {
        $sa2 = User::factory()->create(['is_super_admin' => true, 'status' => 'active']);
        $u = $this->invited($this->admin, '2026-11-01 09:00:00');
        $e = $this->e1('75000.00', '2026-11-20', $u);
        foreach (['all', $this->kdy] as $mode) {
            $row = collect($this->widget($this->admin, $mode)['to_approve']['data'])->firstWhere('id', $e->id);
            $this->assertTrue($row['can_approve']);
            $this->assertFalse($row['approver_onboarded_requester']);
        }
        $this->sa()->getJson("/api/v1/transactions/{$e->id}")->assertOk()->assertJsonPath('approver_onboarded_requester', false);

        $this->approve($e->id, $this->admin, 'all')->assertOk();
        $this->assertFalse($this->audits($e, 'expense.approved')[0]->new_values['approver_onboarded_requester']);
        $this->assertSame(0, DatabaseNotification::where('type', InAppNotification::EXPENSE_APPROVAL_FLAGGED)->count());
        $this->assertSame(0, $sa2->notifications()->where('type', InAppNotification::EXPENSE_APPROVAL_FLAGGED)->count());
        $this->sa()->getJson('/api/v1/audit-logs?from=2026-11-20&to=2026-11-20&approval=approver_onboarded_requester')->assertOk()
            ->assertJsonCount(0, 'data');

        // The same invitation by a Branch Admin still flags (non-Super-Admin approvers unchanged).
        $v = $this->invited($this->kdyBa, '2026-11-02 09:00:00');
        $e2 = $this->e1('60000.00', '2026-11-20', $v);
        $this->approve($e2->id)->assertOk();
        $this->assertTrue($this->audits($e2, 'expense.approved')[0]->new_values['approver_onboarded_requester']);
        $this->assertSame(collect([$sa2->id, $this->admin->id])->sort()->values()->all(), DatabaseNotification::where('type', InAppNotification::EXPENSE_APPROVAL_FLAGGED)
            ->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($v) => (int) $v)->sort()->values()->all());
    }

    /** A KDY Cashier invited by $by at $at (UTC) through the Users screen (audit user.invited, user_id = $by). */
    private function invited($by, string $at)
    {
        Carbon::setTestNow($at);
        $id = $this->as($by, $this->kdy)->postJson('/api/v1/users', ['name' => 'U '.substr($at, 0, 10), 'email' => 'u'.substr(md5($at), 0, 6).'@example.com',
            'role_id' => $this->branchRole($this->kdy, 'Cashier')->id])->assertCreated()->json('id');
        Carbon::setTestNow('2026-11-20 10:00:00');
        $u = User::findOrFail($id);
        $u->forceFill(['status' => 'active', 'password' => bcrypt('secret123')])->save();
        $this->assertTrue(AuditLog::whereIn('event', ['user.invited', 'user.created'])->where('auditable_id', $id)->where('user_id', $by->id)->exists());

        return $u;
    }
}
