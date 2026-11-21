<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\BankReconciliationMark;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Section 8 "Void, reverse, late entries": AC 22-25; review AC 35 and 40; R12 branch transfer. */
class VoidReverseLateTest extends BankReconciliationTestCase
{
    private function message(Transaction $t): string
    {
        return "{$t->ref_no} is reconciled on KDY Bank (cleared 2026-10-31). If the bank processed it, correct it with a reversal; if the tick was a mistake, untick it first.";
    }

    public function test_ac22_void_of_a_ticked_line_is_refused_frozen_or_not(): void
    {
        $this->tick([[$this->e1, '2026-10-31']])->assertOk();
        $before = $this->books();
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->e1->id}/void", ['reason' => 'Duplicate'])
            ->assertStatus(422)->assertJsonPath('message', $this->message($this->e1));
        $this->assertSame($before, $this->books());

        $this->tick([], null, [$this->e1])->assertOk();
        $this->statementOne();
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->e1->id}/void", ['reason' => 'Duplicate'])
            ->assertStatus(422)->assertJsonPath('message', $this->message($this->e1));
        // Untick first (draft only), then the void is allowed: section 7 step 5, an unticked November expense.
        $e5 = $this->expense($this->acc, '100.00', '2026-11-10');
        $this->tick([[$e5, '2026-11-11']])->assertOk();
        $this->tick([], null, [$e5])->assertOk();
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$e5->id}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->assertFalse(collect($this->screen(['statement_date' => '2026-11-20'])->json('lines'))->contains('transaction_id', $e5->id));
        $this->assertVerifies();
    }

    public function test_ac23_reverse_keeps_the_mark_and_lists_the_reversal_unticked(): void
    {
        $this->tick([[$this->e1, '2026-10-31']])->assertOk();
        $rev = $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->e1->id}/reverse", ['reason' => 'Cheque cancelled', 'txn_date' => '2026-11-05'])
            ->assertCreated()->json('id');
        $this->assertNotNull($this->activeMark($this->e1));
        $lines = collect($this->screen(['statement_date' => '2026-11-20'])->json('lines'));
        $r = $lines->firstWhere('transaction_id', $rev);
        $this->assertSame([null, $this->e1->ref_no, 'posted'], [$r['mark'], $r['reversal_of_ref'], $r['status']]);
        $this->assertSame('reversed', $lines->firstWhere('transaction_id', $this->e1->id)['status']);
    }

    public function test_ac24_reversal_pair_never_presented_adds_nothing(): void
    {
        $topUp = $this->allocate($this->kdyBank, '10000.00', '2026-11-08');
        $rev = Transaction::withoutGlobalScopes()->findOrFail($this->sa()->postJson("/api/v1/transactions/{$topUp->id}/reverse", ['reason' => 'Wrong branch', 'txn_date' => '2026-11-09'])
            ->assertCreated()->json('id'));
        $before = $this->screen(['statement_date' => '2026-11-10'])->json('reconciled_at');
        $this->tick([[$topUp, '2026-11-10'], [$rev, '2026-11-10']])->assertOk();
        $this->assertSame($before, $this->screen(['statement_date' => '2026-11-10'])->json('reconciled_at'));
    }

    public function test_ac25_late_entry_after_completion(): void
    {
        $s1 = $this->statementOne();
        Carbon::setTestNow('2026-11-20 11:00:00');
        $late = $this->expense($this->csh, '1000.00', '2026-10-25', '001236');
        $screen = $this->screen(['statement_date' => '2026-11-20'])->assertJsonPath('late_entries_count', 1);
        $this->assertTrue(collect($screen->json('statements'))->firstWhere('id', $s1->id)['changed_since_completion']);
        $r = $this->report(['statement_id' => $s1->id], $this->acc)->assertOk();
        $a = $r->json('accounts.0');
        $this->assertSame(['468500.00', '15000.00', '26000.00', '479500.00'], [$a['book_balance'], $a['uncleared_receipts'], $a['uncleared_payments'], $a['expected_bank_balance']]);
        $r->assertJsonPath('statement.difference', '0.00')->assertJsonPath('statement.changed_since_completion', true)
            ->assertJsonPath('statement.late_entries.0.ref_no', $late->ref_no)->assertJsonPath('statement.late_entries.0.amount', '-1000.00')
            ->assertJsonPath('statement.late_entries.0.kind', 'recorded')->assertJsonPath('statement.late_entries.0.by', $this->csh->name)
            ->assertJsonPath('statement.stored.book_balance', '469500.00');
        $this->assertCount(1, $r->json('statement.late_entries'));
        $this->assertVerifies();

        // Voided after completion (it was uncleared): a late entry too, listed as voided.
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->e2->id}/void", ['reason' => 'Cheque never issued'])->assertOk();
        $late = collect($this->report(['statement_id' => $s1->id], $this->acc)->json('statement.late_entries'));
        $this->assertSame(['recorded', 'voided'], $late->pluck('kind')->all());
        $this->assertSame($this->acc->name, $late[1]['by']);
        // Review finding 6: the S1 banner counts what it can list and tick; the voided entry is only on the reprint.
        $this->assertSame(1, $this->screen(['statement_date' => '2026-11-20'])->json('late_entries_count'));
        $this->assertVerifies();
    }

    public function test_ac35_cleared_before_booked(): void
    {
        $this->tick([[$this->top, '2026-10-31'], [$this->e1, '2026-10-31'], [$this->i1, '2026-10-31']])->assertOk();
        $e3 = $this->expense($this->acc, '500.00', '2026-11-02', null, null, 'Bank charges');
        $this->assertFalse(collect($this->screen()->json('lines'))->contains('transaction_id', $e3->id));
        $this->assertTrue(collect($this->screen(['statement_date' => '2026-10-31', 'show_later' => 1])->json('lines'))->contains('transaction_id', $e3->id));
        $this->tick([[$e3, '2026-10-31']])->assertOk();
        $row = collect($this->screen(['statement_date' => '2026-10-31', 'show_later' => 1])->json('lines'))->firstWhere('transaction_id', $e3->id);
        $this->assertTrue($row['cleared_before_booked']);

        $this->complete('2026-10-31', '479500.00')->assertCreated()->assertJsonPath('book_balance', '470000.00')
            ->assertJsonPath('uncleared_receipts', '15000.00')->assertJsonPath('uncleared_payments', '25000.00')
            ->assertJsonPath('cleared_booked_later', '-500.00')->assertJsonPath('reconciled', '479500.00');
        $a = $this->report(['as_at' => '2026-10-31'])->assertOk()->json('accounts.0');
        $this->assertSame('-500.00', $a['cleared_booked_later']);
        $this->assertSame([$e3->ref_no], array_column($a['cleared_booked_later_rows'], 'ref_no'));
        $this->assertSame('2026-10-31', $a['cleared_booked_later_rows'][0]['cleared_date']);
        $this->assertSame('479500.00', $a['expected_bank_balance']);
        $this->assertVerifies();
    }

    public function test_ac40_dishonoured_cheque_pair_nets_to_zero(): void
    {
        $i5 = $this->income($this->csh, '30000.00', '2026-11-05');
        $before = $this->screen(['statement_date' => '2026-11-12'])->json('reconciled_at');
        $this->tick([[$i5, '2026-11-06']])->assertOk();
        $this->assertSame('30000.00', $this->screen(['statement_date' => '2026-11-12'])->json('reconciled_at'));
        $rev = Transaction::withoutGlobalScopes()->findOrFail($this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$i5->id}/reverse",
            ['reason' => 'Cheque dishonoured', 'txn_date' => '2026-11-12'])->assertCreated()->json('id'));
        $this->tick([[$rev, '2026-11-12']])->assertOk();
        $this->assertSame($before, $this->screen(['statement_date' => '2026-11-12'])->json('reconciled_at'));
        $this->assertSame('2026-11-06', $this->activeMark($i5)->cleared_date->format('Y-m-d'));
    }

    public function test_r12_branch_transfer_each_side_reconciled_by_its_own_branch(): void
    {
        $this->allocate($this->galBank, '1000.00', '2026-10-01', 'opening');
        $t = Transaction::withoutGlobalScopes()->findOrFail($this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', [
            'from_account_id' => $this->kdyBank->id, 'to_account_id' => $this->galBank->id, 'amount' => '5000.00', 'txn_date' => '2026-11-10', 'note' => 'Cash for GAL'])->assertCreated()->json('id'));
        // KDY sees and ticks only its credit line; the GAL line is not on KDY Bank.
        $this->tick([[$t, '2026-11-10']])->assertOk();
        $this->as($this->acc, $this->kdy)->postJson($this->url(null, '/marks'), ['mark' => [['entry_id' => $this->line($t, $this->galBank)->id, 'cleared_date' => '2026-11-11']]])
            ->assertStatus(422);
        $this->tick([[$t, '2026-11-11']], $this->gacc, [], $this->galBank)->assertOk();
        $this->assertSame(2, BankReconciliationMark::withoutGlobalScopes()->active()->count());
        $this->assertSame($this->gal->id, (int) $this->activeMark($t, $this->galBank)->branch_id);
        $this->assertSame([], array_filter(collect($this->screen(['statement_date' => '2026-11-20'])->json('lines'))->pluck('entry_id')->all(),
            fn ($id) => DB::table('ledger_entries')->where('id', $id)->value('account_id') !== $this->kdyBank->id));
        $this->assertVerifies();
    }

    /** Review finding 7: an entry written in the same second as Complete is still a late entry (line id, not timestamp). */
    public function test_late_entry_in_the_same_second_as_completion(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $s1 = $this->statementOne();
        $this->assertSame(Carbon::now()->format('Y-m-d H:i:s'), $s1->completed_at->format('Y-m-d H:i:s'));
        $late = $this->expense($this->csh, '1000.00', '2026-10-25', '001236');
        $this->screen(['statement_date' => '2026-11-20'])->assertJsonPath('late_entries_count', 1);
        $this->report(['statement_id' => $s1->id], $this->acc)->assertJsonPath('statement.late_entries.0.ref_no', $late->ref_no)
            ->assertJsonPath('statement.changed_since_completion', true);
    }
}
