<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\PeriodLock;
use App\Services\StatementAttachments;
use App\Services\YearEnd;
use Illuminate\Support\Facades\DB;

/** AG-033b removal and statement state: bank-statement-attachment.md AC 7, 11, 12, 13, 24. */
class StateTest extends StatementFilesTestCase
{
    public function test_ac7_remove_with_reason(): void
    {
        $oct = $this->attach('oct.pdf');
        $this->remove($oct['id'], 'x')->assertStatus(422)->assertJsonValidationErrors('reason');
        $res = $this->remove($oct['id'], 'Uploaded to the wrong account')->assertOk();
        $this->assertNotNull($res->json('removed_at'));
        $this->assertSame($this->acc->id, $res->json('removed_by.id'));
        $this->assertSame('Uploaded to the wrong account', $res->json('removal_reason'));
        $this->assertNull($res->json('replaced_by_id'));
        $this->assertFalse($res->json('can_remove'));
        $item = $this->historyItem($this->s1);
        $this->assertSame([0, 1], [$item['attachments_count'], $item['attachments_removed_count']]);
        $this->remove($oct['id'])->assertStatus(422)->assertJsonPath('message', 'This file has already been removed.');
        $this->assertCount(1, $this->disk()->allFiles(), 'the file is kept');
        $row = AuditLog::where('event', 'attachment.removed')->sole();
        $this->assertNull($row->new_values['replaced_by_id']);
        $this->assertSame('Uploaded to the wrong account', $row->new_values['reason']);

        // Removed rows are listed only on request.
        $this->assertSame([], $this->listing()->json('data'));
        $this->assertSame($oct['id'], $this->listing(removed: true)->json('data.0.id'));
        $this->assertSame([0, 1, 1], [$this->listing()->json('active_count'), $this->listing()->json('removed_count'), $this->listing()->json('total_uploaded')]);
    }

    public function test_ac11_locked_month_adds_after_close_and_never_removes(): void
    {
        $before = $this->attach('sep.pdf');
        $this->lock('2026-10');
        $a = $this->attach('oct.pdf');
        $this->assertSame('close', $a['added_after']);
        $this->assertNull(Attachment::findOrFail($before['id'])->added_after);
        $this->assertSame('close', AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['added_after']);
        $list = $this->listing()->assertOk();
        $this->assertSame('October 2026 is locked: files can be added as evidence but not removed or replaced.', $list->json('note'));
        $this->assertTrue($list->json('can_add'));
        $this->assertFalse($list->json('can_remove'));
        $this->assertFalse($list->json('data.0.can_remove'));

        $reprint = $this->report(['statement_id' => $this->s1->id])->json('statement.attachments');
        $this->assertStringContainsString(' - added after close', StatementAttachments::reprintLine(collect($reprint)->firstWhere('id', $a['id'])));
        $this->assertStringNotContainsString('added after close', StatementAttachments::reprintLine(collect($reprint)->firstWhere('id', $before['id'])));

        foreach ([[$this->acc, $this->kdy], [$this->admin, 'all']] as [$u, $b]) {
            $this->remove($a['id'], u: $u, branch: $b)->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
            $this->replace($a['id'], 'nov.pdf', 'Wrong month', u: $u, branch: $b)->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        }
        $this->assertSame(0, Attachment::whereNotNull('removed_at')->count());
    }

    public function test_ac11_cumulative_lock_of_november_only(): void
    {
        $this->lock('2026-11');
        $a = $this->attach('oct.pdf');
        $this->assertSame('close', $a['added_after']);
        $this->remove($a['id'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
    }

    public function test_ac11_closed_fiscal_year(): void
    {
        // A closed fiscal year ending 2026-11-30 (as the AG-033 tests; the close itself is covered by its own tests).
        DB::table('year_end_closes')->insert(['fiscal_year' => 2025, 'period_start' => '2025-12-01', 'period_end' => '2026-11-30', 'status' => 'closed',
            'closed_by' => $this->admin->id, 'closed_at' => now(), 'summary' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $message = app(YearEnd::class)->closedYearOf('2026-10-31')->label().' is closed.';
        $a = $this->attach('oct.pdf');
        $this->assertSame('close', $a['added_after']);
        $this->assertStringStartsWith(substr($message, 0, -1), (string) $this->listing()->json('note'));
        foreach ([[$this->acc, $this->kdy], [$this->admin, 'all']] as [$u, $b]) {
            $this->remove($a['id'], u: $u, branch: $b)->assertStatus(422)->assertJsonPath('message', $message);
            $this->replace($a['id'], 'nov.pdf', 'Wrong month', u: $u, branch: $b)->assertStatus(422)->assertJsonPath('message', $message);
        }
    }

    public function test_ac12_undone_statement_is_read_only_and_keeps_its_files(): void
    {
        $a = $this->attach('oct.pdf');
        $this->undo($this->s1->id)->assertOk()->assertJsonPath('status', 'undone');
        $this->upload(file: $this->file('nov.pdf', self::bytes('nov.pdf')))->assertStatus(422)
            ->assertJsonPath('message', 'This statement was undone. Attach the bank statement to the statement that replaces it.');
        $this->remove($a['id'])->assertStatus(422)->assertJsonPath('message', 'Files of an undone statement cannot be removed.');
        $this->replace($a['id'], 'nov.pdf')->assertStatus(422)
            ->assertJsonPath('message', 'This statement was undone. Attach the bank statement to the statement that replaces it.');
        $list = $this->listing()->assertOk();
        $this->assertSame('This statement was undone. Attach the bank statement to the statement that replaces it.', $list->json('note'));
        $this->assertFalse($list->json('can_add'));
        $this->assertFalse($list->json('data.0.can_remove'));
        $this->download($a['id'])->assertOk();
        $this->download($a['id'], $this->vw)->assertOk();
        $this->assertSame(1, Attachment::whereNull('removed_at')->count());
        $this->assertSame($a['id'], $this->report(['statement_id' => $this->s1->id])->json('statement.attachments.0.id'));
    }

    public function test_ac13_file_actions_change_no_figure(): void
    {
        $books = $this->frozen();
        $sep = $this->attach('sep.pdf');
        $this->replace($sep['id'], 'oct.pdf')->assertCreated();
        $p2 = $this->attach('page2.pdf');
        $this->remove($p2['id'])->assertOk();
        $this->download($sep['id'])->assertOk();
        $this->listing(removed: true)->assertOk();
        $this->assertSame($books, $this->frozen());
        $this->assertFalse($this->historyItem($this->s1)['changed_since_completion']);
        $this->assertFalse($this->report(['statement_id' => $this->s1->id])->json('statement.changed_since_completion'));
        $this->assertVerifies();
    }

    public function test_ac24_wrong_file_in_a_locked_month_goes_through_an_audited_unlock(): void
    {
        // October is locked before S1 is completed (AG-033 Q3 allows that): S1 is undone by SA and completed again.
        $this->lock('2026-10');
        $this->undo($this->s1->id, by: $this->admin, branch: 'all')->assertOk();
        $s1 = BankStatement::withoutGlobalScopes()->findOrFail($this->complete('2026-10-31', '479500.00')->assertCreated()->json('id'));
        $sep = $this->upload(s: $s1, file: $this->file('sep.pdf', self::bytes('sep.pdf')))->assertCreated()->json();
        $this->assertSame('close', $sep['added_after']);
        foreach ([[$this->acc, $this->kdy], [$this->admin, 'all']] as [$u, $b]) {
            $this->replace($sep['id'], 'oct.pdf', 'Wrong month', $u, $s1, $b)->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
            $this->remove($sep['id'], 'Wrong month', $u, $s1, $b)->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        }

        $first = AuditLog::max('id');
        $lock = PeriodLock::active()->where('period', '2026-10')->where('branch_id', $this->kdy->id)->sole();
        $this->sa()->postJson("/api/v1/period-locks/{$lock->id}/unlock", ['reason' => 'Wrong bank statement attached to October'])->assertOk();
        $res = $this->replace($sep['id'], 'oct.pdf', 'September statement uploaded by mistake', s: $s1)->assertCreated();
        $this->assertNull($res->json('added_after'));
        $this->assertSame('close', $res->json('replaced.added_after'));
        $this->assertSame('close', Attachment::findOrFail($sep['id'])->added_after);
        $this->lock('2026-10');

        $events = AuditLog::where('id', '>', $first)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['period.unlocked', 'attachment.added', 'attachment.removed', 'period.locked'], $events);
    }
}
