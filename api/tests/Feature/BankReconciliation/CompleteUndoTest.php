<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\AuditLog;
use App\Models\BankReconciliationMark;
use App\Models\BankStatement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Section 8 "Complete and undo": AC 13-21, review AC 36 and 38. */
class CompleteUndoTest extends BankReconciliationTestCase
{
    public function test_ac13_complete_refused_with_a_difference(): void
    {
        $this->tick([[$this->top, '2026-10-31'], [$this->e1, '2026-10-31'], [$this->i1, '2026-10-31']])->assertOk();
        $this->screen()->assertOk()->assertJsonPath('reconciled_at', '480000.00')->assertJsonPath('book_balance_at', '470000.00');
        $this->complete('2026-10-31', '479500.00')->assertStatus(422)
            ->assertJsonPath('message', 'Difference is -500.00. The statement balance must equal the reconciled balance (480,000.00).');
        $this->assertSame(0, DB::table('bank_statements')->count());
    }

    public function test_ac14_complete_stores_the_figures_and_freezes_the_marks(): void
    {
        $before = $this->books();
        $s = $this->statementOne();
        $this->assertSame([479500_00, 479500_00, 469500_00, 15000_00, 25000_00, 0, 4],
            [$s->statement_balance_cents, $s->reconciled_cents, $s->book_balance_cents, $s->uncleared_receipts_cents, $s->uncleared_payments_cents,
                $s->cleared_booked_later_cents, $s->lines_count]);
        $this->assertSame(BankStatement::COMPLETED, $s->status);
        $this->assertSame($this->acc->id, (int) $s->completed_by);
        $this->assertSame(4, BankReconciliationMark::withoutGlobalScopes()->where('statement_id', $s->id)->count());
        $audit = AuditLog::where('event', 'reconciliation.completed')->sole();
        $this->assertSame($this->kdy->id, (int) $audit->branch_id);
        $v = $audit->new_values;
        ksort($v);
        $this->assertSame(['book_balance' => '469500.00', 'cleared_booked_later' => '0.00', 'lines_count' => 4, 'reconciled' => '479500.00', 'self_count' => 2,
            'statement_balance' => '479500.00', 'statement_date' => '2026-10-31', 'statement_id' => $s->id, 'uncleared_payments' => '25000.00',
            'uncleared_receipts' => '15000.00'], $v);
        // Posts nothing: only E3 (recorded through the expense form) changed the books.
        $after = $this->books();
        $this->assertCount(count($before['entries']) + 2, $after['entries']);
        $this->assertSame(array_slice($after['entries'], 0, count($before['entries'])), $before['entries']);
        $this->assertVerifies();
    }

    public function test_ac15_statement_date_rules(): void
    {
        $this->statementOne();
        foreach (['2026-10-31' => 'The statement date must be after 31 Oct 2026.', '2026-10-15' => 'The statement date must be after 31 Oct 2026.',
            '2026-11-21' => 'The statement date cannot be in the future.'] as $date => $message) {
            $this->complete($date, '479500.00')->assertStatus(422)->assertJsonPath('message', $message);
        }
        $this->complete('2026-11-20', '12,000')->assertStatus(422)->assertJsonPath('message', 'Enter the statement balance (for example 125000.00 or -2500.00).');
        $this->complete('2026-11-20', '1.234')->assertStatus(422)->assertJsonPath('message', 'Enter the statement balance (for example 125000.00 or -2500.00).');
        $this->assertSame(1, DB::table('bank_statements')->count());
    }

    public function test_ac18_a_mark_cleared_after_s_stays_a_draft(): void
    {
        $this->tick([[$this->i2, '2026-11-02']])->assertOk();
        $this->statementOne();
        $m = $this->activeMark($this->i2);
        $this->assertNull($m->statement_id);
        $this->tick([], null, [$this->i2])->assertOk();
    }

    public function test_ac19_undo_latest_only_with_a_reason(): void
    {
        $s1 = $this->statementOne();
        $s2 = $this->statementTwo();
        $this->assertSame([469500_00, 2], [$s2->reconciled_cents, $s2->lines_count]);
        $this->undo($s1->id)->assertStatus(422)->assertJsonPath('message', 'Only the latest statement (30 Nov 2026) can be undone.');
        $this->undo($s2->id, 'Too short')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->undo($s2->id, '   short    ')->assertStatus(422)->assertJsonValidationErrors('reason');

        $r = $this->undo($s2->id)->assertOk()->assertJsonPath('status', 'undone')->assertJsonPath('undo_reason', 'Wrong closing balance typed')
            ->assertJsonPath('undone_by.name', $this->acc->name);
        $this->assertNotNull($r->json('undone_at'));
        $this->undo($s2->id)->assertStatus(422)->assertJsonPath('message', 'This statement is already undone.');
        // Its marks keep their row and statement_id but are editable again.
        $m = $this->activeMark($this->e2);
        $this->assertSame($s2->id, (int) $m->statement_id);
        $this->tick([[$this->e2, '2026-11-04']])->assertOk();
        $this->tick([], null, [$this->i2])->assertOk();
        $audit = AuditLog::where('event', 'reconciliation.undone')->sole();
        $this->assertSame('completed', $audit->old_values['status']);
        $this->assertSame($this->acc->id, (int) $audit->old_values['completed_by']);
        $this->assertSame(['reason' => 'Wrong closing balance typed', 'statement_date' => '2026-11-30', 'statement_id' => $s2->id, 'status' => 'undone'],
            collect($audit->new_values)->sortKeys()->all());
        // History: S2 undone, S1 now the latest completed and can be undone.
        $history = collect($this->screen(['statement_date' => '2026-11-30'])->json('statements'))->keyBy('id');
        $this->assertSame('undone', $history[$s2->id]['status']);
        $this->assertFalse($history[$s2->id]['can_undo']);
        $this->assertTrue($history[$s1->id]['can_undo']);
        // The next Complete re-points the marks.
        $this->tick([[$this->i2, '2026-11-02']])->assertOk();
        $s3 = $this->complete('2026-11-30', '469500.00')->assertCreated()->json('id');
        $this->assertSame($s3, (int) $this->activeMark($this->e2)->statement_id);
        $this->assertVerifies();
    }

    public function test_ac20_undo_in_a_locked_month_or_closed_year_is_super_admin_only(): void
    {
        $this->statementOne();
        $s2 = $this->statementTwo();
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-11', 'branch_id' => $this->kdy->id])->assertCreated();
        foreach ([$this->acc, $this->ba] as $u) {
            $this->undo($s2->id, by: $u)->assertForbidden()->assertJsonPath('message', 'The statement date is in a locked period. Only a Super Admin can undo it.');
        }
        $this->assertFalse(collect($this->screen(['statement_date' => '2026-11-30'], $this->ba)->json('statements'))->firstWhere('id', $s2->id)['can_undo']);
        $this->assertTrue(collect($this->screen(['statement_date' => '2026-11-30'], $this->admin, $this->kdy)->json('statements'))->firstWhere('id', $s2->id)['can_undo']);
        $this->undo($s2->id, by: $this->admin, branch: 'all')->assertOk()->assertJsonPath('status', 'undone');
    }

    public function test_ac20_closed_fiscal_year(): void
    {
        $this->statementOne();
        $s2 = $this->statementTwo();
        // A closed fiscal year ending 2026-11-30 (the year-end close itself is covered by its own tests).
        DB::table('year_end_closes')->insert(['fiscal_year' => 2025, 'period_start' => '2025-12-01', 'period_end' => '2026-11-30', 'status' => 'closed',
            'closed_by' => $this->admin->id, 'closed_at' => now(), 'summary' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->undo($s2->id, by: $this->ba)->assertForbidden()->assertJsonPath('message', 'The statement date is in a locked period. Only a Super Admin can undo it.');
        $this->undo($s2->id, by: $this->admin, branch: $this->kdy)->assertOk();
    }

    public function test_ac21_locks_never_block_ticking_or_completing(): void
    {
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-10', 'branch_id' => $this->kdy->id])->assertCreated();
        $s = $this->statementOneWithLateCharge();
        $this->assertSame(479500_00, $s->statement_balance_cents);
    }

    /** As statementOne(), but E3 is dated in open November (October is locked) and cleared 2026-10-31. */
    private function statementOneWithLateCharge(): BankStatement
    {
        $this->tick([[$this->top, '2026-10-31'], [$this->e1, '2026-10-31'], [$this->i1, '2026-10-31']])->assertOk();
        $e3 = $this->expense($this->acc, '500.00', '2026-11-02', null, null, 'Bank charges');
        $this->as($this->acc, $this->kdy)->postJson($this->url(null, '/marks'), ['mark' => [['entry_id' => $this->line($e3)->id, 'cleared_date' => '2026-10-31']]])->assertOk();

        return BankStatement::withoutGlobalScopes()->findOrFail($this->complete('2026-10-31', '479500.00')->assertCreated()->json('id'));
    }

    public function test_ac36_overdrawn_balance_is_stored_signed(): void
    {
        // A bank account that starts overdrawn on the statement: one payment cleared, no receipt yet.
        $this->tick([[$this->e2, '2026-10-31']])->assertOk();
        $s = $this->complete('2026-10-31', '-25000.00')->assertCreated()->assertJsonPath('statement_balance', '-25000.00')->json('id');
        $this->assertSame(-2500000, BankStatement::withoutGlobalScopes()->find($s)->statement_balance_cents);
        $this->tick([[$this->top, '2026-11-01'], [$this->e1, '2026-11-01']])->assertOk();
        $this->complete('2026-11-01', '415000')->assertCreated();
    }

    public function test_ac38_preparer_and_self_count(): void
    {
        $s = $this->statementOne();
        $this->assertSame(2, $s->self_count);
        $row = collect($this->screen()->json('statements'))->sole();
        $this->assertSame(['id' => $this->acc->id, 'name' => $this->acc->name, 'tag' => null], $row['completed_by']);
        $this->assertSame([2, 4], [$row['self_count'], $row['lines_count']]);
    }

    public function test_r12_deactivated_preparer_keeps_name_with_tag(): void
    {
        $this->statementOne();
        DB::table('users')->where('id', $this->acc->id)->update(['status' => 'inactive']);
        $this->assertSame('inactive', collect($this->screen(by: $this->ba)->json('statements'))->sole()['completed_by']['tag']);
    }

    public function test_r12_closed_bank_account_and_first_reconciliation_from_zero(): void
    {
        // A second KDY bank account funded and emptied: it can be closed and still reconciled.
        $old = $this->bankIn($this->kdy, 'KDY Old Bank');
        $in = $this->allocate($old, '1000.00', '2026-10-02');
        $out = $this->expense($this->acc, '1000.00', '2026-10-03', null, $old);
        $this->sa($this->kdy)->putJson("/api/v1/accounts/{$old->id}", ['status' => 'closed'])->assertOk();
        $this->tick([[$in, '2026-10-02'], [$out, '2026-10-03']], null, [], $old)->assertOk();
        $this->complete('2026-10-31', '0.00', null, $old)->assertCreated()->assertJsonPath('lines_count', 2);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->assertVerifies();
    }
}
