<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;

/** AC 4-11 and 10a: confirming receipt, who may, and the field rules. Confirming posts nothing (R1). */
class ConfirmTest extends TransferReceiptTestCase
{
    public function test_ac4_widget_of_the_receiving_branch_only(): void
    {
        $t = $this->t1();
        $w = $this->widget($this->galCsh, $this->gal);
        $this->assertTrue($w['required']);
        $this->assertCount(1, $w['incoming']['data']);
        $row = $w['incoming']['data'][0];
        $this->assertSame([$t->id, $t->ref_no, 'branch_transfer', '2026-11-18', 2, '10000.00', 'awaiting', true, false],
            [$row['id'], $row['ref_no'], $row['type'], $row['txn_date'], $row['age_days'], $row['amount'], $row['receipt_status'], $row['can_acknowledge'], $row['sent_by_you']]);
        $this->assertSame(['branch_code' => 'KDY', 'account' => 'Cash'], $row['from']);
        $this->assertSame(['branch_code' => 'GAL', 'account' => 'Cash'], $row['to']);
        $this->assertSame(['id' => $this->acc->id, 'name' => $this->acc->name], $row['created_by']);
        $buckets = collect($w['summary']['buckets'])->keyBy('key');
        $this->assertSame(['0_1', '2_3', '4_7', '8_plus'], $buckets->keys()->all());
        $this->assertSame([1, '10000.00'], [$buckets['2_3']['count'], $buckets['2_3']['amount']]);
        $this->assertSame([1, '10000.00'], [$w['summary']['count'], $w['summary']['amount']]);
        $this->assertSame([], $w['outgoing_disputed']['data']);

        $kdy = $this->widget($this->acc, $this->kdy);
        $this->assertSame([], $kdy['incoming']['data']);
        $this->assertSame(0, $kdy['summary']['count']);

        // The Viewer sees the list (transaction.view) but may not act.
        $this->assertFalse($this->widget($this->galVw, $this->gal)['incoming']['data'][0]['can_acknowledge']);
    }

    public function test_ac5_confirm_posts_nothing_and_is_audited(): void
    {
        $t = $this->t1();
        $before = $this->ledgerState();
        $audits = AuditLog::count();

        $r = $this->confirm($t->id)->assertOk();
        $r->assertJsonPath('receipt_status', 'received')->assertJsonPath('received_on', '2026-11-19')
            ->assertJsonPath('receipt_by.id', $this->galCsh->id)->assertJsonPath('receipt_by.name', $this->galCsh->name);
        $this->assertNotNull($r->json('receipt_at'));

        $fresh = $this->txn($t->id);
        $this->assertSame(['received', '2026-11-19', $this->galCsh->id, 'posted', '10000.00'],
            [$fresh->receipt_status, $fresh->received_on->format('Y-m-d'), (int) $fresh->receipt_by, $fresh->status, $fresh->amount]);
        $this->assertNotNull($fresh->receipt_at);

        // R1: no ledger line, no balance, no status or amount changes.
        $this->assertSame($before, $this->ledgerState());
        $this->assertLedgerVerifies();

        $this->assertSame($audits + 1, AuditLog::count());
        $a = AuditLog::where('event', 'transfer.receipt_received')->sole();
        $this->assertSame([$this->gal->id, $this->galCsh->id, $t->id], [(int) $a->branch_id, (int) $a->user_id, (int) $a->auditable_id]);
        $this->assertSame(['receipt_status' => 'awaiting'], $a->old_values);
        $new = self::sorted($a->new_values);
        $this->assertSame(['all_branches', 'note', 'receipt_at', 'receipt_status', 'received_on'], array_keys($new));
        $this->assertSame([false, null, 'received', '2026-11-19'], [$new['all_branches'], $new['note'], $new['receipt_status'], $new['received_on']]);

        $this->assertSame([], $this->widget($this->galCsh, $this->gal)['incoming']['data']);

        // The detail (S3) of the receiving branch shows the receipt; no buttons any more.
        $this->as($this->galCsh, $this->gal)->getJson("/api/v1/transactions/{$t->id}")->assertOk()
            ->assertJsonPath('receipt_status', 'received')->assertJsonPath('received_on', '2026-11-19')
            ->assertJsonPath('receipt_by.name', $this->galCsh->name)->assertJsonPath('can_acknowledge', false);

        // Audit log screen: Transactions group, labelled.
        $row = collect($this->sa()->getJson('/api/v1/audit-logs?event_group=transactions&from=2026-11-20&to=2026-11-20')->assertOk()->json('data'))->firstWhere('id', $a->id);
        $this->assertNotNull($row);
        $this->assertSame('Receipt confirmed', $row['event_label'] ?? $row['label'] ?? null);
    }

    public function test_ac6_second_confirmation_is_refused_without_audit(): void
    {
        $t = $this->t1();
        $this->confirm($t->id)->assertOk();
        $state = $this->state();
        $this->confirm($t->id, $this->galAcc)->assertStatus(422)
            ->assertJsonPath('message', "Receipt was already confirmed by {$this->galCsh->name} on 2026-11-19.");
        $this->assertSame($state, $this->state());
    }

    public function test_ac7_viewer_without_the_key_gets_403(): void
    {
        $t = $this->t1();
        $state = $this->state();
        $this->confirm($t->id, $this->galVw)->assertForbidden()->assertJsonPath('message', 'Missing permission: transfer.acknowledge');
        $this->assertSame($state, $this->state());
    }

    public function test_ac8_sending_branch_gets_404(): void
    {
        $t = $this->t1();
        $state = $this->state();
        $this->assertTrue($this->acc->fresh()->permissionKeysIn($this->kdy->id) !== [] && in_array('transfer.acknowledge', $this->acc->permissionKeysIn($this->kdy->id), true));
        $this->confirm($t->id, $this->acc, $this->kdy)->assertNotFound();
        // An entry that does not exist: the same answer.
        $this->confirm(999999, $this->galCsh)->assertNotFound();
        $this->assertSame($state, $this->state());
    }

    public function test_ac9_creator_who_is_also_a_member_of_the_receiving_branch_gets_403(): void
    {
        $t = $this->t1();
        $this->acc->branches()->attach($this->gal->id);
        setPermissionsTeamId($this->gal->id);
        $this->acc->assignRole($this->branchRole($this->gal, 'Accountant'));
        $state = $this->state();
        $this->confirm($t->id, $this->acc, $this->gal)->assertForbidden()
            ->assertJsonPath('message', 'You sent this transfer. Someone in GAL must confirm receipt.');
        $this->assertSame($state, $this->state());

        // R13: a member of both branches who did not create it may confirm in the receiving branch.
        $both = $this->userIn($this->kdy, 'Accountant');
        $both->branches()->attach($this->gal->id);
        setPermissionsTeamId($this->gal->id);
        $both->assignRole($this->branchRole($this->gal, 'Cashier'));
        $this->confirm($t->id, $both, $this->gal)->assertOk();
    }

    public function test_ac10_super_admin_cannot_confirm_own_top_up_the_branch_can(): void
    {
        $top = $this->topUp();
        $state = $this->state();
        $this->confirm($top->id, $this->admin, 'all', note: 'Checked GAL cash count sheet 19 Nov')->assertForbidden()
            ->assertJsonPath('message', 'You sent this transfer. Someone in GAL must confirm receipt.');
        $this->confirm($top->id, $this->admin, $this->gal)->assertForbidden();
        $this->assertSame($state, $this->state());
        $this->assertFalse($this->widget($this->admin, 'all')['incoming']['data'][0]['can_acknowledge']);
        $this->assertTrue($this->widget($this->admin, 'all')['incoming']['data'][0]['sent_by_you']);

        $this->confirm($top->id)->assertOk()->assertJsonPath('receipt_status', 'received');
    }

    public function test_ac10a_super_admin_in_all_mode_must_say_what_was_checked(): void
    {
        $t = $this->t1();
        $state = $this->state();
        $this->confirm($t->id, $this->admin, 'all')->assertStatus(422)->assertJsonValidationErrors('note')
            ->assertJsonPath('errors.note.0', 'From All branches, say what you checked, e.g. the bank statement date (10 to 500 characters).');
        $this->confirm($t->id, $this->admin, 'all', note: 'too short')->assertStatus(422);
        $this->assertSame($state, $this->state());
        $row = $this->widget($this->admin, 'all')['incoming']['data'][0];
        $this->assertTrue($row['can_acknowledge']);
        $this->assertTrue($row['note_required']);

        $this->confirm($t->id, $this->admin, 'all', note: 'Checked GAL cash count sheet 19 Nov')->assertOk()
            ->assertJsonPath('receipt_by.is_super_admin', true)->assertJsonPath('receipt_note', 'Checked GAL cash count sheet 19 Nov');
        $a = AuditLog::where('event', 'transfer.receipt_received')->sole();
        $this->assertSame($this->gal->id, (int) $a->branch_id);
        $this->assertTrue($a->new_values['all_branches']);
        $this->as($this->galCsh, $this->gal)->getJson("/api/v1/transactions/{$t->id}")->assertJsonPath('receipt_by.is_super_admin', true);
    }

    public function test_ac10a_super_admin_in_the_receiving_branch_needs_no_note(): void
    {
        $t = $this->t1();
        $this->confirm($t->id, $this->admin, $this->gal)->assertOk();
    }

    public function test_ac11_received_on_between_transfer_date_and_today(): void
    {
        $t = $this->t1();
        $state = $this->state();
        $this->confirm($t->id, date: '2026-11-17')->assertStatus(422)
            ->assertJsonPath('errors.received_on.0', 'Received on cannot be before the transfer date (2026-11-18).');
        $this->confirm($t->id, date: '2026-11-21')->assertStatus(422)
            ->assertJsonPath('errors.received_on.0', 'Received on cannot be in the future.');
        $this->receipt($t->id, $this->galCsh, $this->gal, ['action' => 'received'])->assertStatus(422)->assertJsonValidationErrors('received_on');
        $this->receipt($t->id, $this->galCsh, $this->gal, ['action' => 'received', 'received_on' => '19/11/2026'])->assertStatus(422)->assertJsonValidationErrors('received_on');
        $this->receipt($t->id, $this->galCsh, $this->gal, ['action' => 'accept', 'received_on' => '2026-11-19'])->assertStatus(422)->assertJsonValidationErrors('action');
        $this->confirm($t->id, note: str_repeat('x', 501))->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame($state, $this->state());

        // The transfer date and today are both allowed.
        $this->confirm($t->id, date: '2026-11-18')->assertOk();
        $t2 = $this->t1();
        $this->confirm($t2->id, date: '2026-11-20', note: 'Counted with K. Silva')->assertOk()->assertJsonPath('receipt_note', 'Counted with K. Silva');
    }

    public function test_entries_that_need_no_receipt(): void
    {
        // An expense is nobody's receipt: not visible to a branch (R4, 404); from All mode, R6 item 2.
        $exp = $this->expense('2026-11-19', '50.00', $this->ba, $this->galCash);
        $this->confirm($exp['id'], $this->ba)->assertNotFound();
        $this->confirm($exp['id'], $this->admin, 'all', note: 'Checked the bank statement')->assertStatus(422)
            ->assertJsonPath('message', 'This entry does not need a receipt.');
        // A transfer posted with the setting off (the opening allocation of GAL, before this release).
        $opening = Transaction::withoutGlobalScopes()->where('type', 'opening_allocation')->where('related_branch_id', $this->gal->id)->sole();
        $this->confirm($opening->id)->assertStatus(422)->assertJsonPath('message', 'This entry does not need a receipt.');
    }

    public function test_role_without_the_key_in_the_receiving_branch(): void
    {
        $t = $this->t1();
        // Branch copies of a template do not change when the template does: remove the key from GAL's Cashier role.
        $role = Role::where('branch_id', $this->gal->id)->where('name', 'Cashier')->sole();
        $role->revokePermissionTo('transfer.acknowledge');
        $this->confirm($t->id)->assertForbidden();
        $this->assertFalse($this->widget($this->galCsh, $this->gal)['incoming']['data'][0]['can_acknowledge']);
    }
}
