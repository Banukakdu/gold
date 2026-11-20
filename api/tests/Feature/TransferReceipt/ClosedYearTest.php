<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Tests\Feature\YearEnd\YearEndTestCase;

/**
 * AC 17 (closed year) and R8: confirming posts nothing, so it is allowed in a closed fiscal year, and the Year-end
 * preview shows the transfers in transit at the year end (information only). Y-dataset, "today" 2027-04-10.
 */
class ClosedYearTest extends YearEndTestCase
{
    public function test_ac17_confirm_in_a_closed_year_and_preview_counts_in_transit_at_year_end(): void
    {
        $this->sa()->putJson('/api/v1/settings', ['transfer_receipt_required' => true])->assertOk();
        $kdyAcc = $this->userIn($this->kdy, 'Accountant');
        $galCsh = $this->userIn($this->gal, 'Cashier');
        $send = fn (string $amount) => $this->as($kdyAcc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->acc($this->kdy, 'Cash')->id,
            'to_account_id' => $this->acc($this->gal, 'Cash')->id, 'amount' => $amount, 'txn_date' => '2027-03-30', 'note' => 'Cash for GAL'])->assertCreated()->json('id');
        $a = $send('1000.00');
        $b = $send('250.00');

        $preview = fn () => $this->sa()->getJson('/api/v1/fiscal-years/2026/preview')->assertOk()->json();
        $p = $preview();
        $this->assertSame([2, '1250.00', 0, '0.00'], [$p['transfers_awaiting_receipt'], $p['transfers_awaiting_receipt_amount'], $p['transfers_disputed'], $p['transfers_disputed_amount']]);
        $this->assertNotContains('transfers_awaiting_receipt', array_column($p['checks'] ?? [], 'key'), 'not a check');

        // The open receipts do not block the close.
        $this->closeY();
        $lines = LedgerEntry::count();
        $balances = $this->balances();

        $this->as($galCsh, $this->gal)->postJson("/api/v1/transactions/{$a}/receipt", ['action' => 'received', 'received_on' => '2027-03-31'])
            ->assertOk()->assertJsonPath('receipt_status', 'received');
        $this->as($galCsh, $this->gal)->postJson("/api/v1/transactions/{$b}/receipt", ['action' => 'received', 'received_on' => '2027-04-02'])->assertOk();
        $this->assertSame($lines, LedgerEntry::count());
        $this->assertSame($balances, $this->balances());
        $this->assertSame(['posted', 'posted'], [Transaction::withoutGlobalScopes()->find($a)->status, Transaction::withoutGlobalScopes()->find($b)->status]);
        $this->artisan('ledger:verify')->assertExitCode(0);

        // As at 2027-03-31: only the one received after the year end.
        $p = $preview();
        $this->assertSame([1, '250.00'], [$p['transfers_awaiting_receipt'], $p['transfers_awaiting_receipt_amount']]);

        // Reopening the year changes no receipt.
        Carbon::setTestNow('2027-04-10 11:00:00');
        $this->reopen()->assertOk();
        $this->assertSame(['received', 'received'], [Transaction::withoutGlobalScopes()->find($a)->receipt_status, Transaction::withoutGlobalScopes()->find($b)->receipt_status]);
    }
}
