<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\Branch;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;

/**
 * AC 17, 18, 18a (accounting review MUST 1): confirming in a locked month, and transfers in transit as at a date (R14)
 * in the Period locks review and the Transfers report option "In transit at end date".
 */
class LocksAndInTransitTest extends TransferReceiptTestCase
{
    private function review(string $period, ?int $branchId = null): array
    {
        return $this->sa()->getJson('/api/v1/period-locks/review?'.http_build_query(array_filter(['period' => $period, 'branch_id' => $branchId])))->assertOk()->json();
    }

    private function inTransit(string $from, string $to, Branch|string $branch = 'all'): array
    {
        return $this->sa($branch)->getJson('/api/v1/reports/transfers?'.http_build_query(['from' => $from, 'to' => $to, 'receipt' => ['in_transit']]))->assertOk()->json();
    }

    public function test_ac17_confirming_in_a_locked_month_needs_no_unlock_and_posts_nothing(): void
    {
        $t = $this->t1();
        $t2 = $this->t1('500.00');
        $this->lock('2026-11', $this->gal)->assertCreated();
        $this->lock('2026-11', $this->kdy)->assertCreated();
        $lines = LedgerEntry::count();
        $before = $this->ledgerState();
        $this->confirm($t->id)->assertOk()->assertJsonPath('receipt_status', 'received');
        $this->dispute($t2->id)->assertOk();
        $this->assertSame($lines, LedgerEntry::count());
        $this->assertSame($before, $this->ledgerState());
        $this->assertLedgerVerifies();
    }

    public function test_ac18_lock_review_counts_open_receipts_with_amount_and_disputed(): void
    {
        $t = $this->t1();
        $r = $this->review('2026-11');
        $this->assertSame([1, '10000.00', 0, '0.00'],
            [$r['transfers_awaiting_receipt'], $r['transfers_awaiting_receipt_amount'], $r['transfers_disputed'], $r['transfers_disputed_amount']]);
        // MAIN neither sent nor received T1.
        $this->assertSame(0, $this->review('2026-11', $this->main->id)['transfers_awaiting_receipt']);
        $this->assertSame(1, $this->review('2026-11', $this->gal->id)['transfers_awaiting_receipt']);
        // October ends before T1.
        $this->assertSame(0, $this->review('2026-10')['transfers_awaiting_receipt']);

        $this->dispute($t->id)->assertOk();
        $r = $this->review('2026-11');
        $this->assertSame([1, '10000.00', 1, '10000.00'],
            [$r['transfers_awaiting_receipt'], $r['transfers_awaiting_receipt_amount'], $r['transfers_disputed'], $r['transfers_disputed_amount']]);

        $this->confirm($t->id, note: 'Missing 500 found in the bag')->assertOk();
        $this->assertSame(0, $this->review('2026-11')['transfers_awaiting_receipt']);

        // Information only: the lock is never blocked by an open receipt.
        $this->t1('700.00');
        $this->lock('2026-11', null)->assertCreated();
        // A branch the lock no longer newly closes is not counted.
        $this->assertSame(0, $this->review('2026-11')['transfers_awaiting_receipt']);
    }

    public function test_ac18a_confirmed_after_month_end_is_still_in_transit_at_month_end(): void
    {
        $t1 = $this->t1();
        $confirmedInMonth = $this->t1('300.00', '2026-11-19');
        $this->confirm($confirmedInMonth->id, date: '2026-11-20')->assertOk();
        $reversedInMonth = $this->t1('200.00', '2026-11-19');
        $this->accKdy()->postJson("/api/v1/transactions/{$reversedInMonth->id}/reverse", ['reason' => 'Not needed', 'txn_date' => '2026-11-20'])->assertCreated();
        $reversedLater = $this->t1('100.00', '2026-11-19');

        Carbon::setTestNow('2026-12-03 10:00:00');
        $this->confirm($t1->id, date: '2026-12-02')->assertOk();
        $this->accKdy()->postJson("/api/v1/transactions/{$reversedLater->id}/reverse", ['reason' => 'Not needed', 'txn_date' => '2026-12-01'])->assertCreated();
        $this->assertSame('cancelled', $this->txn($reversedLater->id)->receipt_status);

        // At 2026-11-30: T1 (received after) and the one reversed after; not the one received or reversed before.
        $r = $this->inTransit('2026-11-01', '2026-11-30');
        $this->assertEqualsCanonicalizing([$t1->id, $reversedLater->id], array_column($r['movements']['data'], 'id'));
        $this->assertSame(['as_at' => '2026-11-30', 'count' => 2, 'amount' => '10100.00'], $r['in_transit']);
        $this->assertSame('10100.00', $r['net_moved']);
        $row = collect($r['movements']['data'])->firstWhere('id', $t1->id);
        $this->assertSame(['received', '2026-12-02', '10000.00'], [$row['receipt_status'], $row['received_on'], $row['amount']]);

        $review = $this->review('2026-11');
        $this->assertSame([2, '10100.00'], [$review['transfers_awaiting_receipt'], $review['transfers_awaiting_receipt_amount']]);

        // From does not limit the list: a late-November start still shows the 18 Nov transfer.
        $this->assertSame(2, $this->inTransit('2026-11-25', '2026-11-30')['in_transit']['count']);
        // Branch mode: the branch's own transfers (sent or received).
        $this->assertSame(2, $this->inTransit('2026-11-01', '2026-11-30', $this->gal)['in_transit']['count']);
        $this->assertSame(0, $this->inTransit('2026-11-01', '2026-11-30', $this->main)['in_transit']['count']);

        // At 2026-12-02 T1 is received; the other was reversed on 2026-12-01.
        $r = $this->inTransit('2026-12-01', '2026-12-02');
        $this->assertSame([], $r['movements']['data']);
        $this->assertSame(['as_at' => '2026-12-02', 'count' => 0, 'amount' => '0.00'], $r['in_transit']);
        // At 2026-12-01 T1 is still in transit.
        $this->assertSame([$t1->id], array_column($this->inTransit('2026-12-01', '2026-12-01')['movements']['data'], 'id'));
    }

    public function test_in_transit_cannot_be_combined_and_unknown_values_are_refused(): void
    {
        $q = fn (array $receipt) => $this->sa()->getJson('/api/v1/reports/transfers?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-30', 'receipt' => $receipt]));
        $q(['in_transit', 'awaiting'])->assertStatus(422)->assertJsonPath('errors.receipt.0', 'In transit at end date cannot be combined with other receipt values.');
        $q(['pending'])->assertStatus(422)->assertJsonPath('errors.receipt.0', 'Unknown receipt status: pending.');
        $q(['awaiting', 'disputed'])->assertOk();
    }
}
