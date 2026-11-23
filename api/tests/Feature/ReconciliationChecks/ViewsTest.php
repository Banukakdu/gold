<?php

namespace Tests\Feature\ReconciliationChecks;

use App\Models\AuditLog;
use App\Models\BankStatementCheck;
use App\Notifications\InAppNotification as N;
use App\Services\ReconciliationChecks;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** AC 13, 22-27, 29: branch isolation, S6 list and exports, S3 reprint, S1 history, the lock confirm, the audit log. */
class ViewsTest extends ChecksTestCase
{
    private const NOTE = 'Late cheque 25 Oct agreed to chq book';

    public function test_ac13_branch_isolation(): void
    {
        $this->check($this->st4, $this->vw, [], $this->kdy)->assertNotFound();
        // A statement on another account of the same branch, and a forged check id, are 404 too.
        $this->as($this->vw, $this->kdy)->postJson("/api/v1/accounts/{$this->kdyCash->id}/reconciliation/statements/{$this->st1->id}/checks",
            ['compared_with' => 'internet_banking', 'confirmed' => true])->assertNotFound();
        $this->check($this->st4, $this->admin, [], $this->kdy)->assertNotFound();
        $this->report(['statement_id' => $this->st4->id], $this->admin, $this->kdy)->assertNotFound();

        $id = $this->check($this->st4, $this->admin, [], 'all')->assertCreated()->assertJsonPath('branch.id', $this->gal->id)->json('id');
        $this->assertSame($this->gal->id, (int) BankStatementCheck::withoutGlobalScopes()->find($id)->branch_id);
        $this->assertSame($this->gal->id, (int) AuditLog::where('event', 'reconciliation.checked')->sole()->branch_id);
        $n = $this->notes($this->gacc, N::RECONCILIATION_CHECKED)->sole();
        $this->assertSame([$this->gal->id, 'GAL'], [$n->data['branch_id'], $n->data['branch_code']]);
        $this->withdraw($this->st1, $id, $this->vw, branch: $this->kdy)->assertNotFound(); // the check is not on ST1

        // F6: All mode lists every branch's unchecked statements; KDY mode only KDY.
        $all = $this->listIds(['checked' => 'not_checked'], $this->admin, 'all');
        $this->assertEqualsCanonicalizing([$this->st1->id, $this->st3->id], $all);
        $this->assertSame([$this->st4->id], $this->listIds(['checked' => 'checked'], $this->admin, 'all'));
        $this->assertSame([], $this->listIds(['checked' => 'checked'], $this->admin, $this->kdy));
        $this->assertSame([$this->st4->id], $this->listIds(['checked' => 'checked'], $this->gvw, $this->gal));
    }

    public function test_ac22_list_column_and_filter(): void
    {
        $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated();
        $r = $this->listRow($this->st1);
        $this->assertSame('checked', $r['check_status']);
        $this->assertSame(['id' => $this->vw->id, 'name' => $this->vw->name, 'tag' => null], $r['check']['checked_by']);
        $this->assertSame(['internet_banking', null, 0], [$r['check']['compared_with'], $r['check']['after_close'], $r['check']['late_since_check']]);
        $this->assertSame('Checked by '.$this->vw->name.', 2026-12-10', ReconciliationChecks::listText($r));
        $this->assertSame('Not checked', ReconciliationChecks::listText($this->listRow($this->st3)));
        $this->assertSame('', ReconciliationChecks::listText($this->listRow($this->st2)));

        $this->assertSame([$this->st3->id], $this->listIds(['checked' => 'not_checked']));
        $this->assertSame([$this->st1->id], $this->listIds(['checked' => 'checked']));
        $this->assertSame([], $this->listIds(['checked' => 'recheck']));
        $this->assertSame([$this->st3->id, $this->st2->id, $this->st1->id], $this->listIds(['checked' => 'any']));
        $this->as($this->vw, $this->kdy)->getJson('/api/v1/reports/reconciliation-statements?checked=some')->assertStatus(422)->assertJsonValidationErrors('checked');
    }

    public function test_ac23_list_exports_and_excel_note_as_text(): void
    {
        $this->check($this->st1, $this->vw, ['note' => '=SUM(A1:A9) agreed to the chq book'])->assertCreated();
        $text = implode(' ', $this->pdfPages($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/reconciliation-statements/export', ['format' => 'pdf'])
            ->assertOk()->getContent()));
        foreach (['CHECKED', 'Checked by '.$this->vw->name.', 2026-12-10', 'Not checked', 'Checked: All'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        foreach (['CHECKED (INITIALS, DATE)', 'Signature'] as $gone) {
            $this->assertStringNotContainsString($gone, $text);
        }

        $ws = $this->xlsx($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/reconciliation-statements/export', ['format' => 'xlsx', 'checked' => 'checked'])
            ->assertOk())->getSheet(0);
        $grid = $ws->toArray(null, false, false);
        $lines = array_map(fn ($row) => (string) $row[0], $grid);
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_contains($l, 'Checked: Checked')));
        $head = array_search('Branch', $lines, true);
        $cols = array_flip(array_map(fn ($v) => (string) $v, $grid[$head]));
        foreach (['Check status', 'Checked by', 'Checked at', 'Compared with'] as $c) {
            $this->assertArrayHasKey($c, $cols, $c);
        }
        $this->assertSame(['Checked', $this->vw->name, 'Internet banking'],
            [$grid[$head + 1][$cols['Check status']], $grid[$head + 1][$cols['Checked by']], $grid[$head + 1][$cols['Compared with']]]);
        $at = $ws->getCell([$cols['Checked at'] + 1, $head + 2]);
        $this->assertSame(DataType::TYPE_NUMERIC, $at->getDataType());
        $this->assertSame('2026-12-10 15:30', ExcelDate::excelToDateTimeObject($at->getValue())->format('Y-m-d H:i'));

        // S3 Excel: the note is a text cell, never a formula.
        $ws = $this->xlsx($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export', ['statement_id' => $this->st1->id, 'format' => 'xlsx'])
            ->assertOk())->getSheet(0);
        $found = false;
        foreach ($ws->getRowIterator() as $row) {
            $cell = $ws->getCell([1, $row->getRowIndex()]);
            if (str_contains((string) $cell->getValue(), '=SUM(A1:A9)')) {
                $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_ac24_reprint_screen_pdf_and_excel(): void
    {
        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->json('id');
        $line = 'Checked by '.$this->vw->name.' on 2026-12-10 15:30 against internet banking.';
        $st = $this->reprint($this->st1);
        $this->assertSame(['checked', 0], [$st['check_status'], $st['late_since_check']]);
        $this->assertCount(1, $st['checks']);
        $this->assertSame([$id, 'active', self::NOTE, true], [$st['checks'][0]['id'], $st['checks'][0]['status'], $st['checks'][0]['note'], $st['checks'][0]['confirmed']]);
        $this->assertNull($st['checker_attachment_ids']); // AG-033b not merged: no copy line
        $this->assertStringStartsWith($line, ReconciliationChecks::blockLines($st)[0]);

        $text = implode(' ', $this->pdfPages($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export',
            ['statement_id' => $this->st1->id, 'format' => 'pdf'])->assertOk()->getContent()));
        foreach ([$line, 'Note: '.self::NOTE, ' - changed since completion when checked', 'Checked by '.$this->vw->name.' on 2026-12-10 15:30 (recorded in Account-gold, check #'.$id.')',
            'Prepared by '.$this->acc->name] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('Approved by', $text);
        $cells = $this->sheetText($this->st1);
        foreach ([$line, 'Note: '.self::NOTE] as $needle) {
            $this->assertNotEmpty(array_filter($cells, fn ($c) => str_contains($c, $needle)), $needle);
        }

        // ST3 not checked: the blank Checked by line stays.
        $st3 = $this->reprint($this->st3);
        $this->assertSame(['not_checked', []], [$st3['check_status'], $st3['checks']]);
        $text = implode(' ', $this->pdfPages($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export',
            ['statement_id' => $this->st3->id, 'format' => 'pdf'])->assertOk()->getContent()));
        foreach (['Not checked in the system.', 'Checked by', 'Signature', 'Not checked in the system'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('Approved by', $text);
    }

    public function test_ac25_history_on_s1(): void
    {
        $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated();
        $rows = collect($this->screen([], $this->acc)->assertOk()->json('statements'))->keyBy('id');
        $this->assertSame('Checked by '.$this->vw->name.', 2026-12-10', ReconciliationChecks::listText($rows[$this->st1->id]));
        $this->assertSame('not_checked', $rows[$this->st3->id]['check_status']);
        $this->assertNull($rows[$this->st2->id]['check_status']);
    }

    public function test_ac26_lock_confirm_lines_and_lock_audit(): void
    {
        $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated();
        Carbon::setTestNow('2026-12-10 11:00:00');
        $this->expense($this->acc, '200.00', '2026-10-28', '001500'); // a late entry after the check: ST1 needs a re-check
        $r = $this->sa()->getJson('/api/v1/period-locks/review?'.http_build_query(['period' => '2026-11', 'branch_id' => $this->kdy->id]))->assertOk();
        $rows = $r->json('bank_reconciliation_checks');
        $this->assertCount(1, $rows);
        $this->assertSame(['id' => $this->kdy->id, 'code' => 'KDY'], $rows[0]['branch']);
        $this->assertSame([['statement_id' => $this->st3->id, 'account' => ['id' => $this->kdyBank->id, 'code' => $this->kdyBank->code, 'name' => 'KDY Bank'],
            'statement_date' => '2026-11-30']], $rows[0]['not_checked']);
        $this->assertSame([$this->st1->id], array_column($rows[0]['recheck'], 'statement_id'));

        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-11', 'branch_id' => $this->kdy->id])->assertCreated();
        $this->assertSameJson([['branch_id' => $this->kdy->id, 'not_checked' => [$this->st3->id], 'recheck' => [$this->st1->id]]],
            AuditLog::where('event', 'period.locked')->sole()->new_values['bank_reconciliation_checks']);
        // Nothing newly closed for KDY now: no rows.
        $this->assertSame([], $this->sa()->getJson('/api/v1/period-locks/review?'.http_build_query(['period' => '2026-11', 'branch_id' => $this->kdy->id]))
            ->assertOk()->json('bank_reconciliation_checks'));
    }

    public function test_ac29_audit_log_labels_and_subject(): void
    {
        $id = $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated()->json('id');
        $this->withdraw($this->st1, $id, $this->vw)->assertOk();
        $this->check($this->st3, $this->vw)->assertCreated();
        $this->undo($this->st3->id, 'Wrong closing balance typed', $this->ba)->assertOk();

        $rows = collect($this->sa()->getJson('/api/v1/audit-logs?'.http_build_query(['event_group' => 'bank_reconciliation', 'from' => '2026-12-01', 'to' => '2026-12-31']))->assertOk()->json('data'))
            ->whereIn('event', ['reconciliation.checked', 'reconciliation.check_withdrawn', 'reconciliation.check_cancelled']);
        $this->assertEqualsCanonicalizing(['Bank statement checked', 'Bank statement check withdrawn', 'Bank statement check cancelled'],
            $rows->pluck('event_label')->unique()->values()->all());
        $code = $this->kdyBank->code;
        $this->assertSame("{$code} KDY Bank, statement 2026-10-31", $rows->firstWhere('event', 'reconciliation.check_withdrawn')['subject']);
        $this->assertSame("{$code} KDY Bank, statement 2026-11-30", $rows->firstWhere('event', 'reconciliation.check_cancelled')['subject']);
    }

    public function test_ac27_notification_opens_the_reprint_in_its_branch(): void
    {
        $this->check($this->st1, $this->vw, ['note' => self::NOTE])->assertCreated();
        $n = $this->notes($this->acc, N::RECONCILIATION_CHECKED)->sole();
        $this->actingAs($this->acc)->postJson("/api/v1/notifications/{$n->id}/read")->assertOk();
        $this->assertNotNull($n->fresh()->read_at);
        $row = $this->actingAs($this->acc)->getJson("/api/v1/notifications/{$n->id}")->assertOk()->json();
        $this->assertSame(["/reports/bank-reconciliation?statement_id={$this->st1->id}", ['id' => $this->kdy->id, 'code' => 'KDY']], [$row['url'], $row['branch']]);
    }
}
