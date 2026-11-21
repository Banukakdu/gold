<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\AuditLog;

/** R6 (review finding 1): a confirm or report sent from an out-of-date screen is refused; S3 keeps the settled discrepancy visible. */
class StaleStateTest extends TransferReceiptTestCase
{
    public function test_r6_stale_confirm_cannot_settle_a_dispute_it_did_not_see(): void
    {
        $t = $this->t1();
        $this->dispute($t->id)->assertOk();
        $state = $this->state();

        // The accountant's screen still shows "Awaiting receipt"; a note long enough to settle is not enough.
        $this->receipt($t->id, $this->galAcc, $this->gal, ['action' => 'received', 'received_on' => '2026-11-19',
            'note' => 'Counted and banked', 'expected_status' => 'awaiting'])
            ->assertStatus(422)->assertJsonPath('message', 'This receipt was changed by someone else. Reload and try again.');
        $this->assertSame($state, $this->state());

        // Reloaded: the same request with the state now shown settles it.
        $this->receipt($t->id, $this->galAcc, $this->gal, ['action' => 'received', 'received_on' => '2026-11-19',
            'note' => 'Counted and banked', 'expected_status' => 'disputed'])
            ->assertOk()->assertJsonPath('receipt_status', 'received');
        $this->assertLedgerVerifies();
    }

    public function test_r6_stale_report_and_bad_expected_status_are_refused(): void
    {
        // Confirmed meanwhile: the more specific "already confirmed" message wins.
        $t = $this->t1();
        $this->confirm($t->id)->assertOk();
        $u = $this->t1();
        $state = $this->state();
        $this->receipt($t->id, $this->galAcc, $this->gal, ['action' => 'discrepancy', 'note' => 'Short by 500 rupees', 'expected_status' => 'awaiting'])
            ->assertStatus(422)->assertJsonMissingValidationErrors()->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Receipt was already confirmed by'));
        $this->receipt($u->id, $this->galAcc, $this->gal, ['action' => 'discrepancy', 'note' => 'Short by 500 rupees', 'expected_status' => 'received'])
            ->assertStatus(422)->assertJsonValidationErrors('expected_status');
        $this->assertSame($state, $this->state());

        $this->receipt($u->id, $this->galCsh, $this->gal, ['action' => 'received', 'received_on' => '2026-11-19', 'expected_status' => 'awaiting'])
            ->assertOk()->assertJsonPath('receipt_status', 'received');
    }

    public function test_s3_settled_discrepancy_stays_visible_and_audit_summary_has_the_details(): void
    {
        $t = $this->t1();
        $this->dispute($t->id)->assertOk();
        $this->confirm($t->id, note: 'Missing 500 found in the bag')->assertOk();

        $d = $this->as($this->galCsh, $this->gal)->getJson("/api/v1/transactions/{$t->id}")->assertOk();
        $d->assertJsonPath('receipt_note', 'Missing 500 found in the bag')
            ->assertJsonPath('receipt_discrepancy.note', 'Short by 500')
            ->assertJsonPath('receipt_discrepancy.by.id', $this->galCsh->id);
        $this->assertNull($this->as($this->galCsh, $this->gal)->getJson('/api/v1/transactions/'.$this->t1()->id)->json('receipt_discrepancy'));

        $a = AuditLog::where('event', 'transfer.receipt_received')->sole();
        $row = collect($this->sa()->getJson('/api/v1/audit-logs?event_group=transactions&from=2026-11-20&to=2026-11-20')->assertOk()->json('data'))
            ->firstWhere('id', $a->id);
        $this->assertSame('receipt_status: disputed -> received; received_on: 2026-11-19; note: Missing 500 found in the bag', $row['summary']);
    }
}
