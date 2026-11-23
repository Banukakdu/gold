<?php

namespace Tests\Feature\ReconciliationChecks;

use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\BankStatementCheck;
use App\Notifications\InAppNotification as N;
use App\Services\ReconciliationChecks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** AC 2, 4-9, 11, 12, 14, 16, 27 (recording), 31 (audit): docs/specs/reconciliation-checked-by.md. */
class RecordTest extends ChecksTestCase
{
    public function test_ac2_without_the_keys_403_and_no_button(): void
    {
        $this->check($this->st3, $this->acc)->assertForbidden()->assertJsonPath('message', 'Missing permission: account.reconcile_check');
        $this->check($this->st3, $this->csh)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.view');
        $this->check($this->st3, $this->vw2)->assertForbidden()->assertJsonPath('message', 'Missing permission: account.reconcile_check');
        $this->assertSame(['can_check' => false, 'can_withdraw' => false, 'blocked_reason' => null], $this->reprint($this->st3, $this->acc)['check_actions']);
        $this->assertSame(0, BankStatementCheck::withoutGlobalScopes()->count());
    }

    public function test_ac4_viewer_records_a_check_with_audit_and_notification(): void
    {
        $note = 'Late cheque 25 Oct agreed to chq book';
        $r = $this->check($this->st1, $this->vw, ['note' => $note])->assertCreated()
            ->assertJsonPath('checked_by.id', $this->vw->id)->assertJsonPath('status', 'active')->assertJsonPath('changed_at_check', true)
            ->assertJsonPath('late_count_at_check', 1)->assertJsonPath('checker_recorded_count', 0)->assertJsonPath('after_close', null)
            ->assertJsonPath('branch.id', $this->kdy->id)->assertJsonPath('branch.code', 'KDY')->assertJsonPath('confirmed', true)
            ->assertJsonPath('compared_with', 'internet_banking')->assertJsonPath('note', $note)->assertJsonPath('bank_statement_id', $this->st1->id)
            ->assertJsonPath('entries_count', 7)->assertJsonMissingPath('superseded');
        $c = BankStatementCheck::withoutGlobalScopes()->findOrFail($r->json('id'));
        $this->assertSame($this->kdy->id, (int) $c->branch_id);
        $this->assertSame((int) DB::table('ledger_entries')->where('account_id', $this->kdyBank->id)->max('id'), $c->max_entry_id);

        $audit = AuditLog::where('event', 'reconciliation.checked')->sole();
        $this->assertSame([BankStatement::class, $this->st1->id, $this->kdy->id, $this->vw->id],
            [$audit->auditable_type, (int) $audit->auditable_id, (int) $audit->branch_id, (int) $audit->user_id]);
        $this->assertNull($audit->old_values);
        $this->assertSameJson(['check_id' => $c->id, 'statement_id' => $this->st1->id, 'account_id' => $this->kdyBank->id, 'statement_date' => '2026-10-31',
            'statement_balance' => '479500.00', 'compared_with' => 'internet_banking', 'confirmed' => true, 'note' => $note, 'changed_at_check' => true,
            'late_count_at_check' => 1, 'checker_recorded_count' => 0, 'checker_uncleared_count' => 0, 'entries_count' => 7, 'after_close' => null,
            'supersedes_id' => null], $audit->new_values);

        $n = $this->notes($this->acc, N::RECONCILIATION_CHECKED)->sole();
        $this->assertNull($n->read_at);
        $this->assertSame('KDY Bank statement of 31 Oct 2026 was checked', $n->data['title']);
        $this->assertSame('Checked by '.$this->vw->name.' against internet banking.', $n->data['body']);
        $this->assertSame("/reports/bank-reconciliation?statement_id={$this->st1->id}", $n->data['url']);
        $this->assertSame([$this->kdy->id, 'KDY'], [$n->data['branch_id'], $n->data['branch_code']]);
        $this->assertStringNotContainsString('chq book', json_encode($n->data));
        // Never the actor; one row only.
        $this->assertCount(0, $this->notes($this->vw));
    }

    public function test_ac5_note_required_with_late_entries_optional_otherwise(): void
    {
        $this->check($this->st1, $this->vw)->assertStatus(422)->assertJsonPath('message', 'Explain the late entries in the note (at least 10 characters).');
        $this->check($this->st1, $this->vw, ['note' => '   ok    '])->assertStatus(422)->assertJsonPath('message', 'Explain the late entries in the note (at least 10 characters).');
        $this->check($this->st1, $this->vw, ['note' => str_repeat('x', 501)])->assertStatus(422)->assertJsonPath('message', 'The note may be at most 500 characters.');
        $this->assertSame(0, BankStatementCheck::withoutGlobalScopes()->count());
        $this->check($this->st3, $this->vw)->assertCreated()->assertJsonPath('note', null)->assertJsonPath('changed_at_check', false);
    }

    public function test_ac6_confirmation_and_compared_with_are_required(): void
    {
        $url = $this->checkUrl($this->st3);
        foreach ([['compared_with' => 'internet_banking', 'confirmed' => false], ['compared_with' => 'internet_banking']] as $body) {
            $this->as($this->vw, $this->kdy)->postJson($url, $body)->assertStatus(422)
                ->assertJsonPath('message', "Confirm that you compared the statement with the bank's own record.");
        }
        foreach ([['compared_with' => 'copy', 'confirmed' => true], ['confirmed' => true]] as $body) {
            $this->as($this->vw, $this->kdy)->postJson($url, $body)->assertStatus(422)->assertJsonPath('message', 'Choose what you compared the statement with.');
        }
        $this->assertSame(0, BankStatementCheck::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::where('event', 'like', 'reconciliation.check%')->count());
    }

    public function test_ac7_the_preparer_who_completed_cannot_check(): void
    {
        $this->check($this->st3, $this->ba)->assertForbidden()->assertJsonPath('message', ReconciliationChecks::PREPARER_COMPLETED);
        $this->assertSame(['can_check' => false, 'can_withdraw' => false, 'blocked_reason' => 'You completed this statement. Someone else must check it.'],
            $this->reprint($this->st3, $this->ba)['check_actions']);
        $this->assertSame(['can_check' => true, 'can_withdraw' => false, 'blocked_reason' => null], $this->reprint($this->st3, $this->vw)['check_actions']);
    }

    public function test_ac8_a_user_who_ticked_a_frozen_line_cannot_check(): void
    {
        $s = $this->bankTwoStatement($this->ba);
        $this->check($s, $this->ba)->assertForbidden()->assertJsonPath('message', 'You ticked lines in this statement. Someone else must check it.');
        $this->assertSame(ReconciliationChecks::PREPARER_TICKED, $this->reprint($s, $this->ba)['check_actions']['blocked_reason']);
    }

    public function test_ac9_checker_recorded_entries_are_counted_and_shown(): void
    {
        $r = $this->check($this->st1, $this->ba, ['note' => 'Late cheque 25 Oct agreed to chq book'])->assertCreated()
            ->assertJsonPath('checker_recorded_count', 1)->assertJsonPath('checker_uncleared_count', 0)->assertJsonPath('entries_count', 7);
        $tag = ' - the checker recorded 1 of the 7 entries on this statement';
        $this->assertStringContainsString($tag, implode("\n", ReconciliationChecks::blockLines($this->reprint($this->st1))));
        $text = implode(' ', $this->pdfPages($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export',
            ['statement_id' => $this->st1->id, 'format' => 'pdf'])->assertOk()->getContent()));
        $this->assertStringContainsString(trim($tag), $text);
        $cells = $this->sheetText($this->st1);
        $this->assertNotEmpty(array_filter($cells, fn ($c) => str_contains($c, $tag)));
        $this->assertStringNotContainsString('of them uncleared', implode(' ', $cells));
        $this->assertSame(1, $r->json('checker_recorded_count'));

        // An uncleared payment recorded by the checker on KDY Bank 2 (completed and ticked by ACC).
        $s = $this->bankTwoStatement($this->acc);
        $this->check($s, $this->ba)->assertCreated()->assertJsonPath('checker_recorded_count', 1)->assertJsonPath('checker_uncleared_count', 1)
            ->assertJsonPath('entries_count', 2);
        $this->assertStringContainsString(' - the checker recorded 1 of the 2 entries on this statement, 1 of them uncleared',
            implode("\n", ReconciliationChecks::blockLines($this->reprint($s))));
    }

    public function test_ac11_an_undone_statement_cannot_be_checked(): void
    {
        $this->check($this->st2, $this->vw, ['note' => 'Checked the November statement'])->assertStatus(422)
            ->assertJsonPath('message', 'Only a completed statement can be checked.');
        $this->assertSame(['can_check' => false, 'can_withdraw' => false, 'blocked_reason' => null], $this->reprint($this->st2)['check_actions']);
        $this->assertNull($this->reprint($this->st2)['check_status']);
    }

    public function test_ac12_already_checked_and_one_active_check_under_a_race(): void
    {
        $this->check($this->st3, $this->vw)->assertCreated();
        $this->check($this->st3, $this->admin, [], 'all')->assertStatus(422)->assertJsonPath('message', 'This statement is already checked.');
        // SA may withdraw any check; BA (the preparer) is blocked as the preparer first.
        $this->assertSame(['can_check' => false, 'can_withdraw' => true, 'blocked_reason' => 'This statement is already checked.'],
            $this->reprint($this->st3, $this->admin, 'all')['check_actions']);
        $this->assertSame(['can_check' => false, 'can_withdraw' => false, 'blocked_reason' => ReconciliationChecks::PREPARER_COMPLETED],
            $this->reprint($this->st3, $this->ba)['check_actions']);
        $this->assertSame(['can_check' => false, 'can_withdraw' => true, 'blocked_reason' => 'This statement is already checked.'], $this->reprint($this->st3)['check_actions']);

        // The database keeps one active check per statement whatever the code does (the race backstop).
        $c = $this->activeCheck($this->st3);
        try {
            DB::table('bank_statement_checks')->insert(collect($c->getAttributes())->except(['id', 'active_key'])->all());
            $this->fail('two active checks on one statement');
        } catch (QueryException $e) {
            $this->assertTrue(ReconciliationChecks::isActiveCheckViolation($e));
        }
        $this->assertSame(1, BankStatementCheck::withoutGlobalScopes()->where('bank_statement_id', $this->st3->id)->count());
    }

    public function test_ac14_super_admin_who_completed_cannot_check(): void
    {
        $bank2 = $this->bankIn($this->kdy, 'KDY Bank 2');
        $alloc = $this->allocate($bank2, '5000.00', '2026-11-01');
        $this->tick([[$alloc, '2026-11-30']], $this->admin, [], $bank2, $this->kdy)->assertOk();
        $s = $this->statement($this->complete('2026-11-30', '5000.00', $this->admin, $bank2));
        $this->check($s, $this->admin, [], 'all')->assertForbidden()->assertJsonPath('message', 'You completed this statement. Someone else must check it.');
        $this->check($s, $this->admin, [], $this->kdy)->assertForbidden();
        $this->check($s, $this->vw)->assertCreated();
    }

    public function test_ac16_late_entries_before_the_check_do_not_need_a_recheck(): void
    {
        $this->check($this->st1, $this->vw, ['note' => 'Late cheque 25 Oct agreed to chq book'])->assertCreated();
        $st = $this->reprint($this->st1);
        $this->assertSame(['checked', 0], [$st['check_status'], $st['late_since_check']]);
        $this->assertFalse($st['late_entries'][0]['after_check']);
        $this->assertSame('checked', $this->listRow($this->st1)['check_status']);
    }

    public function test_ac27_deactivated_preparer_gets_no_notification(): void
    {
        $this->acc->update(['status' => 'inactive']);
        $this->check($this->st1, $this->vw, ['note' => 'Late cheque 25 Oct agreed to chq book'])->assertCreated();
        $this->assertCount(0, $this->notes($this->acc));
    }

    public function test_ac31_dialog_wording_does_not_change_the_audit(): void
    {
        $this->check($this->st1, $this->vw, ['confirmed' => '1', 'note' => 'Late cheque 25 Oct agreed to chq book'])->assertCreated();
        $this->assertTrue(AuditLog::where('event', 'reconciliation.checked')->sole()->new_values['confirmed']);
    }
}
