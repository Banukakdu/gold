<?php

namespace Tests\Feature\TransferApprovals;

use App\Events\TransferApprovalRequested;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\Event;

/** AC 1-6, 29, 30 and the request half of 33: who is held, what a request writes, and what it refuses. */
class RequestTest extends TransferApprovalTestCase
{
    public function test_ac1_a_request_posts_nothing_and_is_audited_once(): void
    {
        Event::fake([TransferApprovalRequested::class]);
        $entries = LedgerEntry::count();
        $r = $this->request()->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('approval_status', 'pending')
            ->assertJsonPath('requested_date', '2026-11-18')->assertJsonPath('requested_on', '2026-11-20')
            ->assertJsonMissingPath('entries')->assertJsonPath('warning', null);
        $t = $this->txn($r->json('id'));
        $this->assertMatchesRegularExpression('/^KDY-TRF-2026-\d{6}$/', $t->ref_no);
        $this->assertSame(0, LedgerEntry::where('transaction_id', $t->id)->count());
        $this->assertSame($entries, LedgerEntry::count());
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
        $this->assertSame('50000.00', $this->bal($this->galCash));
        $this->assertNull($t->receipt_status);
        $this->assertSame('100000.00', (string) $t->approval_threshold);

        $rows = $this->audits($t, 'transfer.approval_requested');
        $this->assertCount(1, $rows);
        // AC 33 (finding 12): requested_on and other_pending_from_account.
        $this->assertSame(self::sorted(['ref_no' => $t->ref_no, 'type' => 'branch_transfer', 'amount' => '150000.00', 'requested_date' => '2026-11-18',
            'requested_on' => '2026-11-20', 'from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id, 'threshold' => '100000.00',
            'other_pending_from_account' => '0.00', 'status' => 'pending']), self::sorted($rows[0]->new_values));
        $this->assertSame($this->kdy->id, (int) $rows[0]->branch_id);
        $this->assertSame(0, $this->audits($t, 'transaction.branch_transfer')->count());
        Event::assertDispatchedTimes(TransferApprovalRequested::class, 1);
        Event::assertDispatched(TransferApprovalRequested::class, fn ($e) => $e->transactionId === $t->id);
    }

    public function test_ac2_strictly_above_the_threshold(): void
    {
        $this->request('100000.01')->assertCreated()->assertJsonPath('status', 'pending');
        $this->request('100000.00')->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('approval_status', null);
    }

    public function test_ac3_empty_threshold_never_holds_and_zero_holds_everything(): void
    {
        $this->threshold(null);
        $this->request('150000.00')->assertCreated()->assertJsonPath('status', 'posted');
        $this->threshold('0');
        $this->request('0.01')->assertCreated()->assertJsonPath('status', 'pending');
    }

    public function test_ac4_internal_transfers_and_super_admin_movements_are_never_held(): void
    {
        $this->threshold('0');
        $kdyBank = Account::create(['branch_id' => $this->kdy->id, 'name' => 'Bank', 'type' => 'bank']);
        $this->request('500.00', '2026-11-19', $kdyBank)->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('type', 'internal_transfer');
        $this->request('500.00', '2026-11-19', null, $this->admin, null)->assertCreated()->assertJsonPath('status', 'posted');
        $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '500.00', 'txn_date' => '2026-11-19', 'note' => 'SA'])->assertCreated()->assertJsonPath('status', 'posted');
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->galCash->id,
            'amount' => '500.00', 'txn_date' => '2026-11-19', 'kind' => 'top_up'])->assertCreated()->assertJsonPath('status', 'posted');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->whereNotNull('approval_status')->count());
    }

    public function test_ac5_a_fund_return_is_held_and_main_approves_it(): void
    {
        $galUser = $this->userIn($this->gal, 'Accountant');
        $this->threshold('10000.00');
        $id = $this->as($galUser, $this->gal)->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id,
            'to_account_id' => $this->mainAcc->id, 'amount' => '15000.00', 'txn_date' => '2026-11-19', 'note' => 'Return'])
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('type', 'fund_return')->json('id');
        $this->approve($id)->assertOk()->assertJsonPath('status', 'posted');
        $this->assertSame('35000.00', $this->bal($this->galCash));
        $this->assertBalancedPerBranch($id);
    }

    public function test_ac6_request_checks_refuse_and_create_nothing(): void
    {
        $before = Transaction::withoutGlobalScopes()->count();
        $this->request('200000.01')->assertStatus(422)->assertJsonPath('message', 'Insufficient balance in KDY Cash (available 200,000.00).');
        $this->assertSame($before, Transaction::withoutGlobalScopes()->count());

        foreach ([$this->kdy, $this->gal, $this->main] as $b) {
            $this->lock('2026-10', $b)->assertCreated();
            $this->request('150000.00', '2026-10-30')->assertStatus(422);
            $this->assertSame($before, Transaction::withoutGlobalScopes()->count(), $b->code);
            $this->unlock($this->activeLock('2026-10', $b))->assertOk();
        }
    }

    public function test_ac29_over_commitment_is_a_warning_not_a_block(): void
    {
        $this->t1();
        $this->request('100000.01', '2026-11-19')->assertCreated()->assertJsonPath('status', 'pending')
            ->assertJsonPath('warning', 'Other requests from this account already wait for 150,000.00; together they exceed the balance.');
        $last = Transaction::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('150000.00', $this->audits($last, 'transfer.approval_requested')[0]->new_values['other_pending_from_account']);
    }

    public function test_ac30_super_admin_movements_above_the_threshold_are_flagged_in_the_audit(): void
    {
        $big = $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '150000.00', 'txn_date' => '2026-11-19', 'note' => 'SA'])->assertCreated()->assertJsonPath('status', 'posted')->json('id');
        $small = $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '100000.00', 'txn_date' => '2026-11-19', 'note' => 'SA'])->assertCreated()->json('id');

        $this->assertSame('not_required_super_admin', $this->audits($this->txn($big), 'transaction.branch_transfer')[0]->new_values['approval']);
        $this->assertArrayNotHasKey('approval', $this->audits($this->txn($small), 'transaction.branch_transfer')[0]->new_values);
        $rows = collect($this->sa()->getJson('/api/v1/audit-logs?from=2026-11-01&to=2026-11-30&approval=not_required_super_admin')->assertOk()->json('data'));
        $this->assertSame([$big], $rows->pluck('auditable_id')->all());
        $this->sa()->getJson('/api/v1/audit-logs?from=2026-11-01&to=2026-11-30&approval=other')->assertStatus(422);
        $this->assertSame(1, AuditLog::where('event', 'transaction.branch_transfer')->where('new_values->approval', 'not_required_super_admin')->count());
    }
}
