<?php

namespace Tests\Feature\ReconciliationChecks;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\BankStatementCheck;
use App\Models\PeriodLock;
use App\Notifications\InAppNotification as N;
use App\Services\ReconciliationChecks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** AC 15 and 17-21, 28: re-check, undo, withdraw, locks, immutability and "posts nothing". */
class LifecycleTest extends ChecksTestCase
{
    private const NOTE = 'Late cheque 25 Oct agreed to chq book';

    public function test_ac15_late_entry_after_the_check_needs_a_recheck(): void
    {
        $first = $this->check($this->st3, $this->vw)->assertCreated()->json('id');
        Carbon::setTestNow('2026-12-10 11:00:00');
        $exp = $this->expense($this->acc, '2000.00', '2026-11-20', '001400');
        $this->assertSame('active', BankStatementCheck::withoutGlobalScopes()->find($first)->status);

        $row = $this->listRow($this->st3);
        $this->assertSame(['recheck', 1], [$row['check_status'], $row['check']['late_since_check']]);
        $this->assertSame('Re-check needed (1 since check)', ReconciliationChecks::listText($row));
        $st = $this->reprint($this->st3);
        $this->assertSame(['recheck', 1], [$st['check_status'], $st['late_since_check']]);
        $this->assertContains('Re-check needed: 1 entry was recorded or voided after the check.', ReconciliationChecks::blockLines($st));
        $this->assertSame([$exp->ref_no => true], collect($st['late_entries'])->mapWithKeys(fn ($l) => [$l['ref_no'] => $l['after_check']])->all());
        $this->assertSame(['can_check' => true, 'can_withdraw' => true, 'blocked_reason' => null], $st['check_actions']);

        // A re-check needs a note.
        $this->check($this->st3, $this->vw)->assertStatus(422)->assertJsonPath('message', 'Explain the late entries in the note (at least 10 characters).');
        Carbon::setTestNow('2026-12-10 12:00:00');
        $r = $this->check($this->st3, $this->vw, ['note' => 'New cheque 001400 agreed to the cheque book'])->assertCreated()
            ->assertJsonPath('superseded.id', $first)->assertJsonPath('superseded.status', 'superseded');
        $second = $r->json('id');
        $old = BankStatementCheck::withoutGlobalScopes()->find($first);
        $this->assertSame(['superseded', $second, $this->vw->id], [$old->status, (int) $old->superseded_by_id, (int) $old->ended_by]);
        $this->assertNotNull($old->ended_at);
        $audit = AuditLog::where('event', 'reconciliation.checked')->orderByDesc('id')->first();
        $this->assertSame($first, $audit->new_values['supersedes_id']);
        $this->assertSameJson(['check_id' => $first, 'status' => 'active', 'checked_by' => $this->vw->id, 'late_since_check' => 1],
            collect($audit->old_values)->except('checked_at')->all());
        $this->assertSame('KDY Bank statement of 30 Nov 2026 was checked again', $this->notes($this->ba, N::RECONCILIATION_CHECKED)->last()->data['title']);
        $this->assertSame('checked', $this->reprint($this->st3)['check_status']);

        // Voiding the late expense writes a contra line: a re-check is needed again.
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$exp->id}/void", ['reason' => 'Entered against the wrong account'])->assertOk();
        $this->assertSame(1, $this->reprint($this->st3)['late_since_check']);
        $this->assertSame('recheck', $this->reprint($this->st3)['check_status']);
        $this->assertVerifies();
    }

    public function test_ac17_undo_cancels_the_check(): void
    {
        $id = $this->check($this->st3, $this->vw)->assertCreated()->json('id');
        $this->undo($this->st3->id, 'Wrong closing balance typed', $this->ba)->assertOk()->assertJsonPath('check_status', 'cancelled');
        $c = BankStatementCheck::withoutGlobalScopes()->find($id);
        $this->assertSame(['cancelled', $this->ba->id, 'Statement undone: Wrong closing balance typed'], [$c->status, (int) $c->ended_by, $c->end_reason]);
        $audit = AuditLog::where('event', 'reconciliation.check_cancelled')->sole();
        $this->assertSame([BankStatement::class, $this->st3->id, $this->kdy->id], [$audit->auditable_type, (int) $audit->auditable_id, (int) $audit->branch_id]);
        $this->assertSameJson(['status' => 'cancelled', 'undo_reason' => 'Wrong closing balance typed'], $audit->new_values);
        $this->assertSameJson(['check_id' => $id, 'status' => 'active', 'checked_by' => $this->vw->id], collect($audit->old_values)->except('checked_at')->all());
        $n = $this->notes($this->vw, N::RECONCILIATION_CHECK_CANCELLED)->sole();
        $this->assertSame(['Your check of KDY Bank 30 Nov 2026 no longer applies', 'The statement was undone by '.$this->ba->name.'.'], [$n->data['title'], $n->data['body']]);

        $row = $this->listRow($this->st3);
        $this->assertSame(['undone', 'cancelled', null], [$row['status'], $row['check_status'], $row['check']]);
        $this->assertSame('Check cancelled', ReconciliationChecks::listText($row));
        $this->assertNull($this->listRow($this->st2)['check_status']); // undone without a check: blank

        // A new statement for the same date starts as Not checked.
        $new = $this->statement($this->complete('2026-11-30', '469500.00', $this->acc));
        $this->assertSame('not_checked', $this->listRow($new)['check_status']);
    }

    public function test_ac18_checker_withdraws(): void
    {
        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->json('id');
        $this->withdraw($this->st1, $id, $this->vw, 'too short')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->withdraw($this->st1, $id, $this->vw)->assertOk()->assertJsonPath('status', 'withdrawn')
            ->assertJsonPath('end_reason', 'Compared with the September statement by mistake')->assertJsonPath('ended_by.id', $this->vw->id);
        $audit = AuditLog::where('event', 'reconciliation.check_withdrawn')->sole();
        $this->assertSameJson(['status' => 'withdrawn', 'reason' => 'Compared with the September statement by mistake', 'self' => true], $audit->new_values);
        $this->assertSame($this->kdy->id, (int) $audit->branch_id);
        $n = $this->notes($this->acc, N::RECONCILIATION_CHECK_WITHDRAWN)->sole();
        $this->assertSame(['Check withdrawn on KDY Bank statement of 31 Oct 2026', 'Withdrawn by '.$this->vw->name.'.'], [$n->data['title'], $n->data['body']]);
        $this->assertStringNotContainsString('September', json_encode($n->data));
        $this->assertCount(0, $this->notes($this->vw));

        $this->assertSame('not_checked', $this->listRow($this->st1)['check_status']);
        $st = $this->reprint($this->st1);
        $this->assertSame('withdrawn', $st['checks'][0]['status']);
        $lines = ReconciliationChecks::blockLines($st);
        $this->assertSame('Not checked in the system.', $lines[0]);
        $this->assertStringStartsWith('Withdrawn: checked by '.$this->vw->name.' on 2026-12-10 15:30; withdrawn by '.$this->vw->name, $lines[1]);
        $this->withdraw($this->st1, $id, $this->vw)->assertStatus(422)->assertJsonPath('message', 'This check is no longer active.');
    }

    public function test_ac19_only_the_checker_or_a_super_admin_withdraws(): void
    {
        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->json('id');
        $this->withdraw($this->st1, $id, $this->ba)->assertForbidden()->assertJsonPath('message', 'Only the checker or a Super Admin can withdraw this check.');
        $this->withdraw($this->st1, $id, $this->admin, branch: 'all')->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->assertFalse(AuditLog::where('event', 'reconciliation.check_withdrawn')->sole()->new_values['self']);
        $this->assertCount(1, $this->notes($this->acc, N::RECONCILIATION_CHECK_WITHDRAWN));
        $this->assertCount(1, $this->notes($this->vw, N::RECONCILIATION_CHECK_WITHDRAWN));
    }

    public function test_ac20_locked_month_check_allowed_withdraw_refused_then_unlock_withdraw_relock(): void
    {
        $lock = $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-10', 'branch_id' => $this->kdy->id])->assertCreated()->json('id');
        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->assertJsonPath('after_close', 'close')->json('id');
        $this->assertContains(' - checked after close', ReconciliationChecks::tags($this->reprint($this->st1)['checks'][0]));
        $this->withdraw($this->st1, $id, $this->vw)->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        $this->withdraw($this->st1, $id, $this->admin, branch: 'all')->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');

        $this->sa()->postJson("/api/v1/period-locks/{$lock}/unlock", ['reason' => 'Withdraw a wrong bank check'])->assertOk();
        $this->withdraw($this->st1, $id, $this->vw)->assertOk();
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-10', 'branch_id' => $this->kdy->id])->assertCreated();
        $this->assertSame(['period.unlocked', 'reconciliation.check_withdrawn', 'period.locked'],
            AuditLog::whereIn('event', ['period.locked', 'period.unlocked', 'reconciliation.check_withdrawn'])->orderBy('id')->pluck('event')->slice(1)->values()->all());
    }

    public function test_ac20_cumulative_november_lock_and_closed_fiscal_year(): void
    {
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-11', 'branch_id' => $this->kdy->id])->assertCreated();
        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->assertJsonPath('after_close', 'close')->json('id');
        $this->withdraw($this->st1, $id, $this->vw)->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        PeriodLock::query()->update(['unlocked_at' => now(), 'unlocked_by' => $this->admin->id, 'unlock_reason' => 'Test reset of the lock']);

        DB::table('year_end_closes')->insert(['fiscal_year' => 2025, 'period_start' => '2025-12-01', 'period_end' => '2026-11-30', 'status' => 'closed',
            'closed_by' => $this->admin->id, 'closed_at' => now(), 'summary' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $msg = $this->withdraw($this->st1, $id, $this->admin, branch: 'all')->assertStatus(422)->json('message');
        $this->assertStringEndsWith(' is closed.', $msg);
        $this->assertStringStartsWith('FY ', $msg);
        $this->check($this->st3, $this->vw)->assertCreated()->assertJsonPath('after_close', 'close');
    }

    public function test_ac21_checks_are_never_deleted_or_edited(): void
    {
        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->json('id');
        $before = DB::table('bank_statement_checks')->where('id', $id)->first();
        foreach ([
            fn () => DB::table('bank_statement_checks')->where('id', $id)->delete(),
            fn () => DB::table('bank_statement_checks')->where('id', $id)->update(['checked_by' => $this->ba->id]),
            fn () => DB::table('bank_statement_checks')->where('id', $id)->update(['note' => 'Something else entirely']),
            fn () => DB::table('bank_statement_checks')->where('id', $id)->update(['max_entry_id' => 999999]),
            // Ending it without ended_by / ended_at, or with another column changed.
            fn () => DB::table('bank_statement_checks')->where('id', $id)->update(['status' => 'withdrawn']),
            fn () => DB::table('bank_statement_checks')->where('id', $id)->update(['status' => 'withdrawn', 'ended_by' => $this->vw->id, 'ended_at' => now(), 'note' => 'x']),
            fn () => DB::table('bank_statements')->where('id', $this->st1->id)->delete(),
        ] as $i => $change) {
            try {
                $change();
                $this->fail("change {$i} was not refused");
            } catch (QueryException) {
                $this->assertEquals($before, DB::table('bank_statement_checks')->where('id', $id)->first());
            }
        }
        // An ended check cannot change again.
        $this->withdraw($this->st1, $id, $this->vw)->assertOk();
        $ended = DB::table('bank_statement_checks')->where('id', $id)->first();
        foreach ([['status' => 'active'], ['status' => 'cancelled'], ['end_reason' => 'A different reason here'], ['ended_by' => $this->ba->id]] as $change) {
            try {
                DB::table('bank_statement_checks')->where('id', $id)->update($change);
                $this->fail('an ended check changed');
            } catch (QueryException) {
                $this->assertEquals($ended, DB::table('bank_statement_checks')->where('id', $id)->first());
            }
        }
        // The model refuses the same.
        $m = BankStatementCheck::withoutGlobalScopes()->find($id);
        $this->expectException(ImmutableRecordException::class);
        try {
            $m->delete();
        } finally {
            $this->assertNotNull(BankStatementCheck::withoutGlobalScopes()->find($id));
            $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'checks') && in_array('DELETE', $r->methods(), true)));
            $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'checks') && array_intersect(['PUT', 'PATCH'], $r->methods())));
        }
    }

    public function test_ac28_checks_post_nothing_and_verify_passes(): void
    {
        $books = $this->books();
        $marks = DB::table('bank_reconciliation_marks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $statements = DB::table('bank_statements')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $attachments = DB::table('attachments')->count();
        $changed = $this->reprint($this->st1)['changed_since_completion'];

        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->json('id');
        $this->withdraw($this->st1, $id, $this->vw)->assertOk();
        $this->check($this->st1, $this->ba, ['note' => self::NOTE])->assertCreated();
        $three = $this->check($this->st3, $this->vw)->assertCreated()->json('id');

        $this->assertSame($books, $this->books());
        $this->assertSame($marks, DB::table('bank_reconciliation_marks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($statements, DB::table('bank_statements')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($attachments, DB::table('attachments')->count());
        $this->assertSame($changed, $this->reprint($this->st1)['changed_since_completion']);
        $this->assertVerifies();

        // V11: a check whose checker is the statement's preparer fails ledger:verify. The check row itself cannot be edited
        // (triggers); making the checker the statement's completed_by is the same offence.
        DB::table('bank_statements')->where('id', $this->st3->id)->update(['completed_by' => $this->vw->id]);
        $this->artisan('ledger:verify')->expectsOutputToContain("check #{$three} of statement #{$this->st3->id} (2026-11-30): the checker is a preparer of the statement")
            ->assertExitCode(1);
    }
}
