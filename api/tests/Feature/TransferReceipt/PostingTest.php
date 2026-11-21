<?php

namespace Tests\Feature\TransferReceipt;

use App\Events\TransferReceiptChanged;
use App\Events\TransferReceiptRequested;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\Event;

/** AC 1-3, 19, 24, 25: which transfers start as "Awaiting receipt", and the setting. */
class PostingTest extends TransferReceiptTestCase
{
    /** Lines of a transaction as "account debit credit", order kept. */
    private function lineShape(Transaction $t): array
    {
        return LedgerEntry::where('transaction_id', $t->id)->orderBy('id')->get()
            ->map(fn ($l) => [$l->account_id, $l->branch_id, $l->debit, $l->credit])->all();
    }

    public function test_ac1_setting_on_posts_awaiting_with_the_same_ledger_lines_as_setting_off(): void
    {
        $before = $this->ledgerState()['balances'];
        $on = $this->t1();
        $this->assertSame('awaiting', $on->receipt_status);
        $this->assertSame('posted', $on->status);
        $afterOn = $this->ledgerState()['balances'];

        $this->setting(false);
        $off = $this->t1();
        $this->assertNull($off->receipt_status);
        $afterOff = $this->ledgerState()['balances'];

        $this->assertSame($this->lineShape($on), $this->lineShape($off), 'same accounts, branches and amounts');
        foreach ($before as $id => $balance) {
            // Each transfer moved every account by the same amount.
            $moveOn = Money::toCents($afterOn[$id]) - Money::toCents($balance);
            $moveOff = Money::toCents($afterOff[$id]) - Money::toCents($afterOn[$id]);
            $this->assertSame($moveOn, $moveOff, "account {$id}");
        }
        $this->assertBalancedPerBranch($on->id);

        // R12: the creation row carries the receipt status; the response too.
        $audit = AuditLog::where('event', 'transaction.branch_transfer')->where('auditable_id', $on->id)->sole();
        $this->assertSame('awaiting', $audit->new_values['receipt_status']);
        $this->assertSame('awaiting', $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$on->id}")->json('receipt_status'));
        $this->assertLedgerVerifies();
    }

    public function test_ac1_response_of_post_transfers_has_receipt_status(): void
    {
        $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '100.00', 'txn_date' => '2026-11-18', 'note' => 'x'])->assertCreated()->assertJsonPath('receipt_status', 'awaiting');
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->galCash->id,
            'amount' => '100.00', 'txn_date' => '2026-11-18', 'kind' => 'top_up'])->assertCreated()->assertJsonPath('receipt_status', 'awaiting');
    }

    public function test_ac2_setting_off_gives_no_receipt_and_never_listed(): void
    {
        $this->setting(false);
        $t = $this->t1();
        $this->assertNull($t->receipt_status);
        $w = $this->widget($this->galCsh, $this->gal);
        $this->assertSame([], $w['incoming']['data']);
        $this->assertSame(0, $w['summary']['count']);
        $this->assertFalse($w['required']);
        $this->confirm($t->id)->assertStatus(422)->assertJsonPath('message', 'This entry does not need a receipt.');
    }

    public function test_ac3_internal_transfer_and_capital_have_none_top_up_and_fund_return_await(): void
    {
        $kdyBank = $this->sa()->postJson('/api/v1/accounts', ['branch_id' => $this->kdy->id, 'name' => 'Bank', 'type' => 'bank'])->assertCreated()->json('id');
        $internal = $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $kdyBank,
            'amount' => '100.00', 'txn_date' => '2026-11-18', 'note' => 'Deposit'])->assertCreated()->json();
        $this->assertSame('internal_transfer', $internal['type']);
        $this->assertNull($this->txn($internal['id'])->receipt_status);

        $capital = $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '100.00', 'txn_date' => '2026-11-18'])->assertCreated()->json('id');
        $this->assertNull($this->txn($capital)->receipt_status);

        $topUp = $this->topUp();
        $this->assertSame(['fund_allocation', 'awaiting'], [$topUp->type, $topUp->receipt_status]);

        $galAccountant = $this->as($this->galAcc, $this->gal);
        $ret = $galAccountant->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id, 'to_account_id' => $this->mainAcc->id,
            'amount' => '1000.00', 'txn_date' => '2026-11-19', 'note' => 'Surplus'])->assertCreated()->json('id');
        $this->assertSame(['fund_return', 'awaiting'], [$this->txn($ret)->type, $this->txn($ret)->receipt_status]);

        // Income and expense never get one.
        $this->assertNull($this->txn($this->expense('2026-11-19', '50.00')['id'])->receipt_status);
    }

    public function test_ac19_turning_the_setting_off_keeps_open_receipts_confirmable(): void
    {
        $t = $this->t1();
        $this->setting(false);
        $this->assertSame('awaiting', $this->txn($t->id)->receipt_status);
        $this->assertSame([$t->id], array_column($this->widget($this->galCsh, $this->gal)['incoming']['data'], 'id'));
        $this->confirm($t->id)->assertOk()->assertJsonPath('receipt_status', 'received');
    }

    public function test_ac24_events_after_commit_and_none_on_refusal(): void
    {
        Event::fake([TransferReceiptRequested::class, TransferReceiptChanged::class]);
        $t = $this->t1();
        Event::assertDispatchedTimes(TransferReceiptRequested::class, 1);
        Event::assertDispatched(TransferReceiptRequested::class, fn ($e) => $e->transactionId === $t->id);
        Event::assertNotDispatched(TransferReceiptChanged::class);

        // A refused request (the creator) dispatches nothing.
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$t->id}/receipt", ['action' => 'received', 'received_on' => '2026-11-19'])->assertNotFound();
        $this->receipt($t->id, $this->galCsh, $this->gal, ['action' => 'received', 'received_on' => '2026-11-21'])->assertStatus(422);
        Event::assertNotDispatched(TransferReceiptChanged::class);

        $this->confirm($t->id)->assertOk();
        Event::assertDispatchedTimes(TransferReceiptChanged::class, 1);
        Event::assertDispatched(TransferReceiptChanged::class, fn ($e) => $e->transactionId === $t->id && $e->oldState === 'awaiting'
            && $e->newState === 'received' && $e->userId === $this->galCsh->id);

        // Setting off: no request event.
        $this->setting(false);
        $this->t1();
        Event::assertDispatchedTimes(TransferReceiptRequested::class, 1);
    }

    public function test_ac25_setting_is_super_admin_only_and_audited_once(): void
    {
        $this->setting(false);
        $before = AuditLog::where('event', 'settings.updated')->count();
        $this->as($this->ba, $this->gal)->putJson('/api/v1/settings', ['transfer_receipt_required' => true])->assertForbidden();
        $this->as($this->galCsh, $this->gal)->getJson('/api/v1/settings')->assertForbidden();
        $this->assertSame($before, AuditLog::where('event', 'settings.updated')->count());

        $this->sa()->putJson('/api/v1/settings', ['transfer_receipt_required' => true])->assertOk()
            ->assertJsonPath('transfer_receipt_required', true)->assertJsonPath('last_changed.transfer_receipt_required.by.name', $this->admin->name);
        $rows = AuditLog::where('event', 'settings.updated')->orderBy('id')->get()->slice($before)->values();
        $this->assertCount(1, $rows);
        $this->assertSame(['transfer_receipt_required' => 0], $rows[0]->old_values);
        $this->assertSame(['transfer_receipt_required' => 1], $rows[0]->new_values);
        $this->assertSame('1', Setting::get('transfer_receipt_required'));

        // Saving the same value writes nothing; a non-boolean is a 422.
        $this->sa()->putJson('/api/v1/settings', ['transfer_receipt_required' => true])->assertOk();
        $this->assertSame($before + 1, AuditLog::where('event', 'settings.updated')->count());
        $this->sa()->putJson('/api/v1/settings', ['transfer_receipt_required' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('transfer_receipt_required');
        $this->sa()->getJson('/api/v1/settings')->assertOk()->assertJsonPath('transfer_receipt_required', true);
    }
}
