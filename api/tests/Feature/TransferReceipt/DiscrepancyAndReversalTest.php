<?php

namespace Tests\Feature\TransferReceipt;

use App\Events\TransferReceiptChanged;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Event;

/** AC 12-16: discrepancy, its settlement, and reversal of a transfer with an open or confirmed receipt. */
class DiscrepancyAndReversalTest extends TransferReceiptTestCase
{
    public function test_ac12_discrepancy_is_stored_audited_and_shown_to_both_branches(): void
    {
        $t = $this->t1();
        $before = $this->ledgerState();
        $this->dispute($t->id)->assertOk()->assertJsonPath('receipt_status', 'disputed')->assertJsonPath('receipt_note', 'Short by 500')
            ->assertJsonPath('received_on', null)->assertJsonPath('receipt_by.id', $this->galCsh->id);
        $this->assertSame($before, $this->ledgerState());
        $this->assertLedgerVerifies();

        $a = AuditLog::where('event', 'transfer.receipt_disputed')->sole();
        $this->assertSame($this->gal->id, (int) $a->branch_id);
        $this->assertSame(['receipt_status' => 'awaiting'], $a->old_values);
        $new = self::sorted($a->new_values);
        $this->assertSame(['all_branches', 'note', 'receipt_at', 'receipt_status'], array_keys($new));
        $this->assertSame(['disputed', 'Short by 500'], [$new['receipt_status'], $new['note']]);

        $gal = $this->widget($this->galCsh, $this->gal);
        $this->assertSame([$t->id], array_column($gal['incoming']['data'], 'id'));
        $this->assertSame('disputed', $gal['incoming']['data'][0]['receipt_status']);
        $this->assertTrue($gal['incoming']['data'][0]['note_required']);
        $kdy = $this->widget($this->acc, $this->kdy);
        $this->assertSame([], $kdy['incoming']['data']);
        $this->assertSame([$t->id], array_column($kdy['outgoing_disputed']['data'], 'id'));
        $this->assertSame(['Short by 500', $this->galCsh->name], [$kdy['outgoing_disputed']['data'][0]['receipt_note'], $kdy['outgoing_disputed']['data'][0]['receipt_by']['name']]);
        $this->assertFalse($kdy['outgoing_disputed']['data'][0]['can_acknowledge']);
        // State filter of the widget.
        $this->assertSame([], $this->widget($this->galCsh, $this->gal, '?state=awaiting')['incoming']['data']);
        $this->assertCount(1, $this->widget($this->galCsh, $this->gal, '?state=disputed')['incoming']['data']);
        $this->as($this->galCsh, $this->gal)->getJson('/api/v1/transfer-receipts?state=received')->assertStatus(422);
    }

    public function test_ac13_discrepancy_note_is_required_10_to_500(): void
    {
        $t = $this->t1();
        $state = $this->state();
        $this->dispute($t->id, null)->assertStatus(422)->assertJsonPath('errors.note.0', 'Describe the discrepancy (10 to 500 characters).');
        $this->dispute($t->id, '123456789')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->dispute($t->id, '   123456789   ')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->dispute($t->id, str_repeat('x', 501))->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame($state, $this->state());
        $this->dispute($t->id, '1234567890')->assertOk();
    }

    public function test_ac14_disputed_then_received_with_a_settlement_note(): void
    {
        $t = $this->t1();
        $this->dispute($t->id)->assertOk();
        $state = $this->state();
        $this->dispute($t->id, 'Still short by 500', $this->galAcc)->assertStatus(422)
            ->assertJsonPath('message', 'A discrepancy is already reported. Confirm receipt when the money arrives, or ask KDY to reverse the transfer.');
        $this->confirm($t->id)->assertStatus(422)->assertJsonPath('errors.note.0', 'Say how the discrepancy was settled (10 to 500 characters).');
        $this->confirm($t->id, note: '123456789')->assertStatus(422);
        $this->assertSame($state, $this->state());

        $this->confirm($t->id, note: 'Missing 500 found in the bag')->assertOk()->assertJsonPath('receipt_status', 'received')
            ->assertJsonPath('receipt_note', 'Missing 500 found in the bag');
        $a = AuditLog::where('event', 'transfer.receipt_received')->sole();
        $this->assertSame(['receipt_status' => 'disputed'], $a->old_values);
        $this->assertSame('Missing 500 found in the bag', $a->new_values['note']);
        $this->assertSame([], $this->widget($this->acc, $this->kdy)['outgoing_disputed']['data']);
        $this->assertLedgerVerifies();
    }

    public function test_ac15_reversal_cancels_an_open_receipt(): void
    {
        foreach (['awaiting', 'disputed'] as $state) {
            Event::fake([TransferReceiptChanged::class]);
            $t = $this->t1();
            if ($state === 'disputed') {
                $this->dispute($t->id)->assertOk();
            }
            $rev = $this->accKdy()->postJson("/api/v1/transactions/{$t->id}/reverse", ['reason' => 'Wrong amount sent'])->assertCreated()->json();
            $fresh = $this->txn($t->id);
            $this->assertSame(['reversed', 'cancelled'], [$fresh->status, $fresh->receipt_status], $state);
            $this->assertNull($this->txn($rev['id'])->receipt_status);
            if ($state === 'disputed') {
                // receipt_by / note kept as they were.
                $this->assertSame(['Short by 500', $this->galCsh->id], [$fresh->receipt_note, (int) $fresh->receipt_by]);
            }

            $a = AuditLog::where('event', 'transfer.receipt_cancelled')->where('auditable_id', $t->id)->sole();
            $this->assertSame([$this->gal->id, $this->acc->id], [(int) $a->branch_id, (int) $a->user_id]);
            $this->assertSame(['receipt_status' => $state], $a->old_values);
            $this->assertSame(self::sorted(['receipt_status' => 'cancelled', 'reversal_id' => $rev['id'], 'reversal_ref' => $rev['ref_no']]), self::sorted($a->new_values));
            Event::assertDispatched(TransferReceiptChanged::class, fn ($e) => $e->transactionId === $t->id && $e->oldState === $state && $e->newState === 'cancelled');

            $this->assertNotContains($t->id, array_column($this->widget($this->galCsh, $this->gal)['incoming']['data'], 'id'));
            $this->assertSame([], $this->widget($this->acc, $this->kdy)['outgoing_disputed']['data']);
            $this->confirm($t->id, note: 'Money arrived after all')->assertStatus(422)->assertJsonPath('message', 'This transfer was reversed. No receipt is needed.');
        }
        $this->assertLedgerVerifies();
    }

    public function test_ac16_reversal_of_a_received_transfer_keeps_received(): void
    {
        $t = $this->t1();
        $this->confirm($t->id)->assertOk();
        $this->accKdy()->postJson("/api/v1/transactions/{$t->id}/reverse", ['reason' => 'Cash taken back'])->assertCreated();
        $fresh = $this->txn($t->id);
        $this->assertSame(['reversed', 'received'], [$fresh->status, $fresh->receipt_status]);
        $this->assertSame(0, AuditLog::where('event', 'transfer.receipt_cancelled')->count());
        // The detail shows the receipt, which the reverse dialog warns about (S6).
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$t->id}")->assertJsonPath('receipt_status', 'received')
            ->assertJsonPath('received_on', '2026-11-19');
    }

    public function test_reversal_without_receipt_writes_no_receipt_row(): void
    {
        $this->setting(false);
        $t = $this->t1();
        $this->accKdy()->postJson("/api/v1/transactions/{$t->id}/reverse", ['reason' => 'Wrong'])->assertCreated();
        $this->assertNull($this->txn($t->id)->receipt_status);
        $this->assertSame(0, AuditLog::where('event', 'like', 'transfer.receipt_%')->count());
    }
}
