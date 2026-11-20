<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\AuditLog;
use App\Models\BankReconciliationMark;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Section 8 "Ticking": AC 5-12 and review AC 39. */
class MarksTest extends BankReconciliationTestCase
{
    public function test_ac5_tick_creates_one_active_mark_and_posts_nothing(): void
    {
        $before = $this->books();
        $this->tick([[$this->top, '2026-10-31']])->assertOk()->assertExactJson(['marked' => 1, 'unmarked' => 0]);
        $m = BankReconciliationMark::withoutGlobalScopes()->sole();
        $this->assertNull($m->unmarked_at);
        $this->assertSame($this->acc->id, (int) $m->marked_by);
        $this->assertSame('2026-10-31', $m->cleared_date->format('Y-m-d'));
        $this->assertSame($this->kdy->id, (int) $m->branch_id);
        $this->assertNull($m->statement_id);
        $this->assertSame($before, $this->books());
    }

    public function test_ac6_future_or_badly_formatted_cleared_date_saves_nothing(): void
    {
        foreach (['2026-11-21' => 'The cleared date cannot be in the future.', '31/10/2026' => 'The cleared date must be in YYYY-MM-DD format.'] as $date => $message) {
            $this->tick([[$this->top, '2026-10-31'], [$this->e1, $date]])->assertStatus(422)->assertJsonPath('message', $message);
        }
        $this->assertSame(0, BankReconciliationMark::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::where('event', 'reconciliation.saved')->count());
    }

    public function test_ac7_line_of_another_account_is_refused(): void
    {
        $cashIncome = $this->income($this->csh, '100.00', '2026-10-31', $this->kdyCash);
        $galIncome = $this->income($this->gacc, '100.00', '2026-10-31', $this->galBank);
        foreach ([$this->line($cashIncome, $this->kdyCash)->id, $this->line($galIncome, $this->galBank)->id] as $id) {
            $this->as($this->acc, $this->kdy)->postJson($this->url(null, '/marks'), ['mark' => [['entry_id' => $id, 'cleared_date' => '2026-10-31']]])
                ->assertStatus(422)->assertJsonPath('message', "Line {$id} is not on this account.");
        }
        $this->assertSame(0, BankReconciliationMark::withoutGlobalScopes()->count());
    }

    public function test_ac8_voided_entry_is_not_listed_and_cannot_be_ticked(): void
    {
        $e4 = $this->expense($this->acc, '1000.00', '2026-10-15', '001240');
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$e4->id}/void", ['reason' => 'Entered twice'])->assertOk();
        $lines = collect($this->screen()->assertOk()->json('lines'));
        $this->assertFalse($lines->contains('transaction_id', $e4->id));
        $contra = DB::table('ledger_entries')->where('transaction_id', $e4->id)->where('account_id', $this->kdyBank->id)->whereNotNull('contra_of_id')->value('id');
        foreach ([$this->line($e4)->id, $contra] as $id) {
            $this->as($this->acc, $this->kdy)->postJson($this->url(null, '/marks'), ['mark' => [['entry_id' => $id, 'cleared_date' => '2026-10-31']]])
                ->assertStatus(422)->assertJsonPath('message', "Voided entries cannot be reconciled ({$e4->ref_no}).");
        }
    }

    public function test_ac9_ticking_twice_and_one_active_mark_per_line(): void
    {
        $this->tick([[$this->top, '2026-10-31']])->assertOk();
        $this->tick([[$this->top, '2026-10-31']], $this->ba)->assertStatus(422)->assertJsonPath('message', "{$this->top->ref_no} is already reconciled.");
        $this->assertSame(1, BankReconciliationMark::withoutGlobalScopes()->active()->count());

        // The second of two simultaneous requests: the database refuses a second active mark on the same line.
        $this->expectException(QueryException::class);
        BankReconciliationMark::create(['branch_id' => $this->kdy->id, 'account_id' => $this->kdyBank->id, 'ledger_entry_id' => $this->line($this->top)->id,
            'cleared_date' => '2026-10-30', 'marked_by' => $this->ba->id, 'marked_at' => now()]);
    }

    public function test_ac10_untick_keeps_the_row_and_a_later_tick_adds_one(): void
    {
        $this->tick([[$this->top, '2026-10-31']])->assertOk();
        $this->tick([], null, [$this->top])->assertOk()->assertExactJson(['marked' => 0, 'unmarked' => 1]);
        $old = BankReconciliationMark::withoutGlobalScopes()->sole();
        $this->assertNotNull($old->unmarked_at);
        $this->assertSame($this->acc->id, (int) $old->unmarked_by);
        $this->tick([], null, [$this->top])->assertStatus(422)->assertJsonPath('message', "{$this->top->ref_no} is not reconciled.");

        $this->tick([[$this->top, '2026-10-30']])->assertOk();
        $this->assertSame(2, BankReconciliationMark::withoutGlobalScopes()->count());
        $this->assertSame('2026-10-30', $this->activeMark($this->top)->cleared_date->format('Y-m-d'));
    }

    public function test_ac11_changing_the_cleared_date_closes_the_old_mark(): void
    {
        $this->tick([[$this->top, '2026-10-31']])->assertOk();
        $this->tick([[$this->top, '2026-10-30']])->assertOk()->assertJsonPath('marked', 1);
        $rows = BankReconciliationMark::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->unmarked_at);
        $this->assertNull($rows[1]->unmarked_at);
        $this->assertSame('2026-10-30', $rows[1]->cleared_date->format('Y-m-d'));
        $audit = AuditLog::where('event', 'reconciliation.saved')->orderByDesc('id')->first();
        $this->assertSame([['entry_id' => $this->line($this->top)->id, 'ref_no' => $this->top->ref_no, 'old_cleared_date' => '2026-10-31', 'cleared_date' => '2026-10-30']],
            array_map(fn ($c) => self::sorted($c, ['entry_id', 'ref_no', 'old_cleared_date', 'cleared_date']), $audit->new_values['changed']));
        $this->assertSame([], $audit->new_values['marked']);
    }

    public function test_ac12_one_saved_audit_row_per_save_with_self_count(): void
    {
        $this->tick([[$this->top, '2026-10-31'], [$this->e1, '2026-10-31'], [$this->i1, '2026-10-31']])->assertOk();
        $this->tick([[$this->e2, '2026-10-31']], null, [$this->top])->assertOk();
        $rows = AuditLog::where('event', 'reconciliation.saved')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame($this->kdy->id, (int) $r->branch_id);
            $this->assertSame($this->acc->id, (int) $r->user_id);
            $this->assertSame($this->kdyBank->id, (int) $r->auditable_id);
            $this->assertSame($this->kdyBank->id, $r->new_values['account_id']);
        }
        // ACC recorded E1 only.
        $this->assertSame(1, $rows[0]->new_values['self_count']);
        $this->assertSame([$this->top->ref_no, $this->e1->ref_no, $this->i1->ref_no], array_column($rows[0]->new_values['marked'], 'ref_no'));
        $marked = collect($rows[0]->new_values['marked'])->keyBy('ref_no');
        $this->assertSame('500000.00', $marked[$this->top->ref_no]['amount']);
        $this->assertSame('-60000.00', $marked[$this->e1->ref_no]['amount']);
        $this->assertSame('2026-10-05', $marked[$this->e1->ref_no]['entry_date']);
        $this->assertSame('2026-10-31', $marked[$this->e1->ref_no]['cleared_date']);
        $this->assertSame(0, $rows[1]->new_values['self_count']);
        $this->assertSame([$this->top->ref_no], array_column($rows[1]->new_values['unmarked'], 'ref_no'));
        $this->assertSame('2026-10-31', $rows[1]->new_values['unmarked'][0]['cleared_date']);
    }

    public function test_r4_step6_and_empty_requests(): void
    {
        $id = $this->line($this->top)->id;
        $this->as($this->acc, $this->kdy)->postJson($this->url(null, '/marks'), ['mark' => [['entry_id' => $id, 'cleared_date' => '2026-10-31']], 'unmark' => [$id]])
            ->assertStatus(422)->assertJsonPath('message', 'A line cannot be ticked and unticked at once.');
        $this->as($this->acc, $this->kdy)->postJson($this->url(null, '/marks'), [])->assertStatus(422);
        $this->as($this->acc, $this->kdy)->postJson($this->url(null, '/marks'), ['unmark' => range(1, 501)])->assertStatus(422)->assertJsonValidationErrors('unmark');
    }

    public function test_ac39_a_frozen_mark_cannot_be_redated(): void
    {
        $this->statementOne();
        $before = $this->activeMark($this->e1)->toArray();
        $this->tick([[$this->e1, '2026-11-05']])->assertStatus(422)
            ->assertJsonPath('message', "{$this->e1->ref_no} is in the completed statement of 31 Oct 2026. Undo that statement first.");
        $this->assertSame($before, $this->activeMark($this->e1)->toArray());
    }

    public function test_ac16_ac17_after_a_statement(): void
    {
        $this->statementOne();
        // AC 16
        $this->tick([[$this->i2, '2026-10-31']])->assertStatus(422)
            ->assertJsonPath('message', 'The cleared date must be after 31 Oct 2026, the last completed statement.');
        // AC 17
        $this->tick([], null, [$this->e1])->assertStatus(422)
            ->assertJsonPath('message', "{$this->e1->ref_no} is in the completed statement of 31 Oct 2026. Undo that statement first.");
        $this->assertNotNull($this->activeMark($this->e1));
        // The screen lists the frozen line (read-only) only when asked, with the statement it belongs to.
        $this->assertFalse(collect($this->screen(['statement_date' => '2026-11-20'])->json('lines'))->contains('transaction_id', $this->e1->id));
        $line = collect($this->screen(['statement_date' => '2026-11-20', 'show_reconciled' => 1])->json('lines'))->firstWhere('transaction_id', $this->e1->id);
        $this->assertSame(['statement_id' => $this->activeMark($this->e1)->statement_id, 'statement_date' => '2026-10-31'], $line['frozen']);
    }

    private static function sorted(array $v, array $order): array
    {
        return array_merge(array_flip($order), $v);
    }
}
