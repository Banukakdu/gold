<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Services\BankReconciliation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * AG-033c bank reconciliation statements list (docs/specs/reconciliation-statements-list.md section 7), on `main`
 * without AG-033b (no attachments.bank_statement_id): AC 1-4, 6-13, 15-17, 20, 21 and the R1 branch isolation.
 * AC 14 (copies supported) is StatementListCopiesTest; AC 5 (opening a row), 18 and 19 are also covered by the web spec.
 */
class StatementListTest extends StatementListTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    public function test_ac1_default_list_last_12_months_newest_first(): void
    {
        $r = $this->list()->assertOk()
            ->assertJsonPath('from', '2025-12-10')->assertJsonPath('to', '2026-12-10')
            ->assertJsonPath('provisional', false)->assertJsonPath('total', 3)->assertJsonPath('page', 1)->assertJsonPath('per_page', 50)
            ->assertJsonPath('basis', BankReconciliation::LIST_BASIS);
        $this->assertSame([$this->st3->id, $this->st2->id, $this->st1->id], $this->ids($r));
    }

    public function test_ac2_cashier_gets_403(): void
    {
        $this->list([], $this->csh)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.view');
        $this->exportList([], 'pdf', $this->csh)->assertForbidden();
    }

    public function test_ac3_ac4_row_contents(): void
    {
        $r = $this->list();
        $st1 = $this->row($r, $this->st1);
        $this->assertSame(['479500.00', 4, 2, 'completed', true, 1, 0], [$st1['statement_balance'], $st1['lines_count'], $st1['self_count'],
            $st1['status'], $st1['changed_since_completion'], $st1['late_entries_count'], $st1['late_voided_count']]);
        $this->assertSame(['id' => $this->acc->id, 'name' => $this->acc->name, 'tag' => null], $st1['completed_by']);
        $this->assertSame(['id' => $this->kdyBank->id, 'code' => $this->kdyBank->code, 'name' => 'KDY Bank', 'status' => 'active'], $st1['account']);
        $this->assertSame(['id' => $this->kdy->id, 'code' => 'KDY'], $st1['branch']);
        $this->assertSame('2026-10-31', $st1['statement_date']);
        $this->assertStringStartsWith('2026-11-03', $st1['completed_at']);
        $this->assertNull($st1['undone_by']);
        // Null on main; 0 once AG-033b is merged (no files here).
        $this->assertSame($this->copiesOn() ? 0 : null, $st1['attachments_count']);
        $this->assertSame($this->copiesOn() ? 0 : null, $st1['attachments_removed_count']);

        $st2 = $this->row($r, $this->st2);
        $this->assertSame(['undone', 'Wrong closing balance typed', false, null, null], [$st2['status'], $st2['undo_reason'],
            $st2['changed_since_completion'], $st2['late_entries_count'], $st2['late_voided_count']]);
        $this->assertSame($this->ba->name, $st2['undone_by']['name']);
        $this->assertStringStartsWith('2026-12-03', $st2['undone_at']);
        $this->assertSame($this->acc->name, $st2['completed_by']['name']);

        $st3 = $this->row($r, $this->st3);
        $this->assertSame(['completed', false, 0, 0], [$st3['status'], $st3['changed_since_completion'], $st3['late_entries_count'], $st3['late_voided_count']]);
        $this->assertSame($this->ba->name, $st3['completed_by']['name']);
    }

    public function test_ac5_ac21_late_count_matches_the_reprint(): void
    {
        $late = $this->report(['statement_id' => $this->st1->id])->assertOk()->json('statement.late_entries');
        $this->assertSame([['recorded', '-1000.00']], array_map(fn ($l) => [$l['kind'], $l['amount']], $late));
        $this->assertSame(count($late), $this->row($this->list(), $this->st1)['late_entries_count']);
    }

    public function test_ac6_ac21_void_after_completion(): void
    {
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->late->id}/void", ['reason' => 'Cheque never issued'])->assertOk();
        $r = $this->list();
        $st1 = $this->row($r, $this->st1);
        $st3 = $this->row($r, $this->st3);
        $st2 = $this->row($r, $this->st2);
        $this->assertSame([2, 1, false], [$st1['late_entries_count'], $st1['late_voided_count'], $st1['changed_since_completion']]);
        $this->assertSame([1, 1, true], [$st3['late_entries_count'], $st3['late_voided_count'], $st3['changed_since_completion']]);
        $this->assertSame([null, null, false], [$st2['late_entries_count'], $st2['late_voided_count'], $st2['changed_since_completion']]);

        // AC 21: the counts equal the "Recorded" and "Voided by" lines of each reprint.
        foreach ([[$this->st1, $st1], [$this->st3, $st3]] as [$s, $row]) {
            $kinds = array_column($this->report(['statement_id' => $s->id])->json('statement.late_entries'), 'kind');
            $this->assertSame($row['late_entries_count'], count($kinds));
            $this->assertSame($row['late_voided_count'], count(array_keys($kinds, 'voided', true)));
        }

        $this->assertSame([$this->st3->id, $this->st1->id], $this->ids($this->list(['late' => 'some'])));
        $this->assertSame([], $this->ids($this->list(['late' => 'none'])));
        // Changed = Yes misses ST1 (figures back to the stored ones); Late entries = Some keeps it (finding 3).
        $this->assertSame([$this->st3->id], $this->ids($this->list(['changed' => 'yes'])));
    }

    public function test_ac7_status_and_changed_filters(): void
    {
        $this->assertSame([$this->st1->id], $this->ids($this->list(['status' => 'completed', 'changed' => 'yes'])));
        $this->assertSame([$this->st3->id], $this->ids($this->list(['status' => 'completed', 'changed' => 'no'])));
        $this->assertSame([$this->st2->id], $this->ids($this->list(['status' => 'undone'])));
        $this->list(['status' => 'undone', 'changed' => 'yes'])->assertOk()->assertJsonPath('total', 0)->assertJsonPath('data', []);
        $this->assertSame([$this->st3->id, $this->st1->id], $this->ids($this->list(['status' => 'completed', 'late' => 'any'])));
        $this->assertSame([$this->st3->id], $this->ids($this->list(['late' => 'none'])));
        // Changed = No with Status = All also lists the undone statement (never "changed", R8).
        $this->assertSame([$this->st3->id, $this->st2->id], $this->ids($this->list(['changed' => 'no'])));
        $this->list(['status' => 'open'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->list(['changed' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('changed');
        $this->list(['late' => 'all'])->assertStatus(422)->assertJsonValidationErrors('late');
    }

    public function test_ac8_date_range(): void
    {
        $this->assertSame([$this->st5->id], $this->ids($this->list(['from' => '2025-09-01', 'to' => '2025-09-30'])));
        $this->list(['to' => '2026-12-11'])->assertStatus(422)->assertJsonPath('errors.to.0', 'The To date cannot be in the future.');
        $this->list(['from' => '2026-12-01', 'to' => '2026-11-01'])->assertStatus(422)->assertJsonPath('errors.from.0', 'From must be on or before To.');
        $this->list(['from' => '31/10/2026'])->assertStatus(422)->assertJsonValidationErrors('from');
        // Inclusive on both ends.
        $this->assertSame([$this->st1->id], $this->ids($this->list(['from' => '2026-10-31', 'to' => '2026-10-31'])));
    }

    public function test_ac9_branch_user_accounts_and_forged_ids(): void
    {
        $this->list(['account_ids' => [$this->galBank->id]], $this->acc)->assertStatus(422)->assertJsonPath('errors.account_ids.0', 'Unknown bank account.');
        $this->list(['account_ids' => [$this->kdyCash->id]], $this->acc)->assertStatus(422)->assertJsonPath('errors.account_ids.0', 'Unknown bank account.');
        $this->list(['branches' => [$this->gal->id]], $this->acc)->assertForbidden();
        $r = $this->list([], $this->acc);
        $this->assertSame([$this->kdyBank->id], array_column($r->json('accounts'), 'id'));
        $this->assertSame(['id' => $this->kdyBank->id, 'code' => $this->kdyBank->code, 'name' => 'KDY Bank', 'branch' => ['id' => $this->kdy->id, 'code' => 'KDY'],
            'status' => 'active', 'last_statement_date' => '2026-11-30'], $r->json('accounts.0'));
        $this->assertSame(3, count($this->ids($this->list(['account_ids' => [$this->kdyBank->id]], $this->acc))));
    }

    public function test_r1_gal_user_sees_gal_only(): void
    {
        $this->assertSame([$this->st4->id], $this->ids($this->list([], $this->gacc, $this->gal)));
        $this->list(['account_ids' => [$this->kdyBank->id]], $this->gacc, $this->gal)->assertStatus(422);
    }

    public function test_ac10_super_admin_all_mode_and_branch_mode(): void
    {
        $r = $this->list([], $this->admin, 'all')->assertOk()->assertJsonPath('all_branches', true);
        // Statement date newest first, then branch code (GAL before KDY on 31 Oct).
        $this->assertSame([$this->st3->id, $this->st2->id, $this->st4->id, $this->st1->id], $this->ids($r));
        $this->assertSame('GAL', $this->row($r, $this->st4)['branch']['code']);
        $accounts = array_column($r->json('accounts'), 'id');
        $this->assertContains($this->kdyBank->id, $accounts);
        $this->assertContains($this->galBank->id, $accounts);
        $this->assertNotContains($this->kdyCash->id, $accounts);
        $this->report(['statement_id' => $this->st4->id], $this->admin, 'all')->assertOk()->assertJsonPath('statement.id', $this->st4->id);
        $this->assertSame([$this->st4->id], $this->ids($this->list(['branches' => [$this->gal->id]], $this->admin, 'all')));

        $this->assertSame([$this->st3->id, $this->st2->id, $this->st1->id], $this->ids($this->list([], $this->admin, $this->kdy)));
        $this->report(['statement_id' => $this->st4->id], $this->admin, $this->kdy)->assertNotFound();
        $this->list(['account_ids' => [$this->galBank->id]], $this->admin, $this->kdy)->assertStatus(422);
        // All mode: an account outside the chosen branches is refused too.
        $this->list(['branches' => [$this->gal->id], 'account_ids' => [$this->kdyBank->id]], $this->admin, 'all')
            ->assertStatus(422)->assertJsonPath('errors.account_ids.0', 'Unknown bank account.');
        $this->exportList(['branches' => [$this->gal->id], 'account_ids' => [$this->kdyBank->id]], 'xlsx', $this->admin, 'all')->assertStatus(422);
    }

    public function test_ac11_deactivated_preparer_keeps_name_with_tag(): void
    {
        DB::table('users')->where('id', $this->acc->id)->update(['status' => 'inactive']);
        $st1 = $this->row($this->list(), $this->st1);
        $this->assertSame([$this->acc->name, 'inactive'], [$st1['completed_by']['name'], $st1['completed_by']['tag']]);
        // Removed from the branch: still listed, with the other tag.
        DB::table('users')->where('id', $this->acc->id)->update(['status' => 'active']);
        DB::table('branch_user')->where('user_id', $this->acc->id)->delete();
        $this->assertSame('no longer in this branch', $this->row($this->list(), $this->st1)['completed_by']['tag']);
    }

    public function test_ac12_inactive_account_still_listed(): void
    {
        DB::table('accounts')->where('id', $this->kdyBank->id)->update(['status' => 'closed']);
        $r = $this->list();
        $this->assertSame([$this->st3->id, $this->st2->id, $this->st1->id], $this->ids($r));
        $this->assertSame(['closed'], array_unique(array_column(array_column($r->json('data'), 'account'), 'status')));
        $this->assertSame('closed', collect($r->json('accounts'))->firstWhere('id', $this->kdyBank->id)['status']);
    }

    /** AG-033b (PR #25) merged: `attachments.bank_statement_id` exists, so the copy column and filter are on. */
    private function copiesOn(): bool
    {
        return Schema::hasColumn('attachments', 'bank_statement_id');
    }

    public function test_ac13_copies_not_supported_on_main(): void
    {
        if ($this->copiesOn()) {
            $this->markTestSkipped('AG-033b is merged: copies are supported (AC 14, StatementListCopiesTest).');
        }
        $r = $this->list()->assertJsonPath('copies_supported', false);
        foreach ($r->json('data') as $row) {
            $this->assertNull($row['attachments_count']);
            $this->assertNull($row['attachments_removed_count']);
        }
        $this->list(['copy' => 'none'])->assertStatus(422)->assertJsonPath('errors.copy.0', 'Bank statement copies are not available yet.');
        $this->list(['copy' => 'attached'])->assertStatus(422)->assertJsonPath('errors.copy.0', 'Bank statement copies are not available yet.');
        $this->list(['copy' => 'any'])->assertOk();
        $this->exportList(['copy' => 'none'], 'xlsx')->assertStatus(422);
        // The Excel file has no copy column.
        $ws = $this->xlsx($this->exportList([], 'xlsx')->assertOk())->getSheet(0);
        $cells = collect($ws->toArray(null, false, false))->flatten()->filter()->map(fn ($v) => (string) $v)->all();
        $this->assertNotContains('Bank statement copy', $cells);
    }

    public function test_ac15_pdf_export_is_the_checkers_working_paper(): void
    {
        $res = $this->exportList([], 'pdf')->assertOk();
        $this->assertSame('attachment; filename="reconciliation-statements_2025-12-10_2026-12-10.pdf"', $res->headers->get('Content-Disposition'));
        $text = implode(' ', $this->pdfPages($res->getContent()));
        foreach (['Bank reconciliation statements', 'Branches: KDY Branch KDY', 'Statement dates:', 'Amounts in LKR', 'Printed by '.$this->acc->name,
            'Completed and undone bank reconciliation statements by statement date.',
            'Filters: Status: All', 'Changed since completion: All', 'Late entries: All', 'Accounts: All',
            'Every bank account in scope is reconciled to 30 Nov 2026.', 'CHECKED', 'Not checked', 'Checked: Any',
            '479,500.00', '469,500.00', 'Undone by '.$this->ba->name, 'Wrong closing balance typed', 'Changed since completion', '3 statements'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('PROVISIONAL', $text);
        // AG-033d: the empty initials column and the signature footer are replaced by the recorded check.
        $this->assertStringNotContainsString('CHECKED (INITIALS, DATE)', $text);
        $this->assertStringNotContainsString('Signature', $text);
        if (! $this->copiesOn()) {
            $this->assertStringNotContainsString('Bank statement copy', $text);
        }
        // Branch mode: no Branch column (S6 shows it in All mode only); All mode has it.
        $this->assertStringNotContainsString('BRANCH ACCOUNT', $text);
        $this->assertStringContainsString('BRANCH ACCOUNT', implode(' ', $this->pdfPages($this->exportList([], 'pdf', $this->admin, 'all')->assertOk()->getContent())));
        $audit = AuditLog::where('event', 'report.exported')->orderBy('id')->first();
        $this->assertSame(['reconciliation-statements', 'pdf', 3], [$audit->new_values['name'], $audit->new_values['format'], $audit->new_values['rows']]);
        $this->assertSame($this->kdy->id, (int) $audit->branch_id);

        // Viewer: no report.export.
        $this->exportList([], 'pdf', $this->vw)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.export');
        $this->exportList([], 'xlsx', $this->vw)->assertForbidden();
    }

    public function test_ac15_excel_export_rows_values_and_formula_prefix(): void
    {
        // An undo reason that starts with "=" (ST3 is the latest completed statement, so BA can undo it).
        $this->undo($this->st3->id, '=HYPERLINK("http://x","typed wrong")', $this->ba)->assertOk();
        $res = $this->exportList([], 'xlsx')->assertOk();
        $this->assertSame('attachment; filename="reconciliation-statements_2025-12-10_2026-12-10.xlsx"', $res->headers->get('Content-Disposition'));
        $ws = $this->xlsx($res)->getSheet(0);
        $grid = $ws->toArray(null, false, false);
        $lines = array_map(fn ($r) => (string) $r[0], $grid);
        foreach (['Bank reconciliation statements', 'Statement dates: ', BankReconciliation::LIST_BASIS, 'Filters: Status: All', 'Not reconciled to 30 Nov 2026: KDY Bank'] as $needle) {
            $this->assertNotEmpty(array_filter($lines, fn ($l) => str_contains($l, $needle)), $needle);
        }
        $this->assertEmpty(array_filter($lines, fn ($l) => str_contains($l, 'PROVISIONAL')));
        $head = array_search('Branch', $lines, true);
        $this->assertNotFalse($head);
        $columns = $grid[$head];
        $col = fn (string $label) => array_search($label, $columns, true);
        $rows = array_slice($grid, $head + 1, 3);
        $this->assertSame(['Undone', 'Undone', 'Completed'], array_column($rows, $col('Status')));
        $this->assertSame(['2026-11-30', '2026-11-30', '2026-10-31'],
            array_map(fn ($v) => ExcelDate::excelToDateTimeObject($v)->format('Y-m-d'), array_column($rows, $col('Statement date'))));
        $this->assertEquals(479500, $rows[2][$col('Statement balance')]);
        $this->assertSame(['No', 'No', 'Yes'], array_column($rows, $col('Changed since completion')));
        $this->assertSame([null, null, 1], array_column($rows, $col('Late entries')));
        $this->assertSame('Wrong closing balance typed', $rows[1][$col('Undo reason')]);

        // Formula-injection protection: text cell with the quote prefix.
        $cell = $ws->getCell([$col('Undo reason') + 1, $head + 2]);
        $this->assertSame('=HYPERLINK("http://x","typed wrong")', $cell->getValue());
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertTrue($ws->getStyle($cell->getCoordinate())->getQuotePrefix());
        $this->assertSame('xlsx', AuditLog::where('event', 'report.exported')->sole()->new_values['format']);
    }

    public function test_ac17_read_only(): void
    {
        $before = $this->books();
        $tables = fn () => [DB::table('bank_statements')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('bank_reconciliation_marks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
        $marks = $tables();
        $audits = AuditLog::count();
        foreach ([[], ['late' => 'some'], ['changed' => 'yes'], ['status' => 'undone']] as $q) {
            $this->list($q)->assertOk();
            $this->list($q, $this->admin, 'all')->assertOk();
        }
        $this->assertSame($audits, AuditLog::count());
        $this->exportList([], 'pdf')->assertOk();
        $this->exportList([], 'xlsx')->assertOk();
        $this->assertSame($before, $this->books());
        $this->assertSame($marks, $tables());
        $this->assertSame(['report.exported', 'report.exported'], AuditLog::where('id', '>', AuditLog::max('id') - 2)->orderBy('id')->pluck('event')->all());
        $this->assertSame($audits + 2, AuditLog::count());
    }

    public function test_ac20_coverage_line(): void
    {
        $this->assertSame(['2026-11-30', []], [$this->list()->json('coverage.reconciled_to'), $this->list()->json('coverage.not_reconciled')]);

        $all = collect($this->list([], $this->admin, 'all')->json('coverage.not_reconciled'));
        $gal = $all->firstWhere('account.id', $this->galBank->id);
        $this->assertSame(['branch' => ['id' => $this->gal->id, 'code' => 'GAL'], 'last_statement_date' => '2026-10-31'],
            ['branch' => $gal['branch'], 'last_statement_date' => $gal['last_statement_date']]);
        $this->assertNull($all->firstWhere('account.id', $this->kdyBank->id));
        // Not affected by Status (nor by the other computed filters or paging).
        $this->assertSame($all->all(), $this->list(['status' => 'undone', 'late' => 'some', 'per_page' => 1], $this->admin, 'all')->json('coverage.not_reconciled'));
        // The Branches and Accounts filters do apply.
        $this->assertSame([], $this->list(['account_ids' => [$this->kdyBank->id]], $this->admin, 'all')->json('coverage.not_reconciled'));

        $this->assertSame('2026-11-30', $this->list(['to' => '2026-11-30'])->json('coverage.reconciled_to'));
        $this->assertSame('2026-10-31', $this->list(['to' => '2026-11-29'])->json('coverage.reconciled_to'));

        // ST3 undone: KDY Bank is reconciled to 31 Oct only (ST2 and ST3 undone do not count).
        $this->undo($this->st3->id, 'Wrong closing balance typed again', $this->ba)->assertOk();
        $this->assertSame([['account' => ['id' => $this->kdyBank->id, 'code' => $this->kdyBank->code, 'name' => 'KDY Bank', 'status' => 'active'],
            'branch' => ['id' => $this->kdy->id, 'code' => 'KDY'], 'last_statement_date' => '2026-10-31']], $this->list()->json('coverage.not_reconciled'));

        // A second KDY bank account with lines and no statement: never reconciled.
        Carbon::setTestNow('2026-11-20 10:00:00');
        $two = $this->bankIn($this->kdy, 'KDY Bank 2');
        $this->allocate($two, '1000.00', '2026-10-02');
        $this->expense($this->acc, '1000.00', '2026-10-03', null, $two);
        Carbon::setTestNow('2026-12-10 10:00:00');
        $row = collect($this->list()->json('coverage.not_reconciled'))->firstWhere('account.id', $two->id);
        $this->assertNull($row['last_statement_date']);
        // AG-033e accounting review finding 1 (amends R9): closed with 0.00 at the cut-off, it is still named "never
        // reconciled" until a statement to the month-end of its last line (31 Oct 2026) is completed; then it is left out.
        $this->sa($this->kdy)->putJson("/api/v1/accounts/{$two->id}", ['status' => 'closed'])->assertOk();
        $row = collect($this->list()->json('coverage.not_reconciled'))->firstWhere('account.id', $two->id);
        $this->assertSame(['status' => 'closed', 'last_statement_date' => null], ['status' => $row['account']['status'], 'last_statement_date' => $row['last_statement_date']]);
        $this->assertStringContainsString('KDY Bank 2 ('.$two->code.'), never reconciled', BankReconciliation::coverageText($this->list()->json('coverage')));
        $this->complete('2026-10-31', '0.00', $this->acc, $two)->assertCreated();
        $this->assertNull(collect($this->list()->json('coverage.not_reconciled'))->firstWhere('account.id', $two->id));
        // A bank account with no line on or before the cut-off is not in scope.
        $this->bankIn($this->kdy, 'KDY Bank 3');
        $this->assertSame([$this->kdyBank->id], array_column(array_column($this->list()->json('coverage.not_reconciled'), 'account'), 'id'));
        $this->assertSame('Not reconciled to 30 Nov 2026: KDY Bank ('.$this->kdyBank->code.'), last statement 31 Oct 2026.',
            BankReconciliation::coverageText($this->list()->json('coverage')));
    }

    public function test_r9_cut_off_and_coverage_text(): void
    {
        $this->assertSame('2026-11-30', BankReconciliation::reconciledTo('2026-12-10'));
        $this->assertSame('2026-11-30', BankReconciliation::reconciledTo('2026-11-30'));
        $this->assertSame('2026-10-31', BankReconciliation::reconciledTo('2026-11-29'));
        $this->assertSame('2024-02-29', BankReconciliation::reconciledTo('2024-02-29'));
        $this->assertSame('Not reconciled to 30 Nov 2026: GAL Bank (12002), last statement 31 Oct 2026; KDY Bank 2 (11003), never reconciled.',
            BankReconciliation::coverageText(['reconciled_to' => '2026-11-30', 'not_reconciled' => [
                ['account' => ['code' => '12002', 'name' => 'GAL Bank'], 'branch' => ['code' => 'GAL'], 'last_statement_date' => '2026-10-31'],
                ['account' => ['code' => '11003', 'name' => 'KDY Bank 2'], 'branch' => ['code' => 'KDY'], 'last_statement_date' => null],
            ]]));
        $this->assertSame('Every bank account in scope is reconciled to 30 Nov 2026.',
            BankReconciliation::coverageText(['reconciled_to' => '2026-11-30', 'not_reconciled' => []]));
    }

    public function test_late_count_is_one_grouped_query(): void
    {
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->late->id}/void", ['reason' => 'Cheque never issued'])->assertOk();
        // More completed statements without late lines must not add queries (no N+1 on late or changed).
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list()->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warms the per-process schema check (copies supported)
        $before = $count();
        $this->insertStatements(30, BankStatement::COMPLETED, '2026-01-01');
        $this->assertSame($before, $count());
    }

    /** Statements written directly (test factory): $n rows on KDY Bank, one per day going back from $last. */
    private function insertStatements(int $n, string $status, string $last, bool $sameDay = false, int $maxEntryId = 1_000_000_000): void
    {
        $rows = [];
        $day = Carbon::parse($last);
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['branch_id' => $this->kdy->id, 'account_id' => $this->kdyBank->id, 'statement_date' => ($sameDay ? $day : $day->copy()->subDays($i))->format('Y-m-d'),
                'statement_balance_cents' => 0, 'reconciled_cents' => 0, 'book_balance_cents' => 0, 'uncleared_receipts_cents' => 0, 'uncleared_payments_cents' => 0,
                'cleared_booked_later_cents' => 0, 'self_count' => 0, 'lines_count' => 0, 'status' => $status, 'completed_by' => $this->acc->id,
                'completed_at' => '2026-12-01 10:00:00', 'undone_by' => $status === BankStatement::UNDONE ? $this->ba->id : null,
                'undone_at' => $status === BankStatement::UNDONE ? '2026-12-01 11:00:00' : null, 'undo_reason' => $status === BankStatement::UNDONE ? 'Test factory row' : null,
                'max_entry_id' => $maxEntryId, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('bank_statements')->insert($chunk);
        }
    }

    public function test_ac16_cap_and_paging(): void
    {
        $this->insertStatements(57, BankStatement::COMPLETED, '2026-09-30');
        // 57 + ST1, ST2, ST3 = 60 in range.
        $r = $this->list(['per_page' => 50])->assertOk()->assertJsonPath('total', 60)->assertJsonPath('per_page', 50);
        $this->assertCount(50, $r->json('data'));
        $this->assertSame([$this->st3->id, $this->st2->id, $this->st1->id], array_slice($this->ids($r), 0, 3));
        $this->assertCount(10, $this->list(['per_page' => 50, 'page' => 2])->assertJsonPath('total', 60)->json('data'));
        $this->list(['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors('per_page');
        // The export holds every row, not one page.
        $this->exportList(['per_page' => 50, 'page' => 2], 'xlsx')->assertOk();
        $audit = AuditLog::where('event', 'report.exported')->sole()->new_values;
        $this->assertSame(60, $audit['rows']);
        // The export has no paging, so the audit params have none.
        $this->assertArrayNotHasKey('page', $audit['params']);
        $this->assertArrayNotHasKey('per_page', $audit['params']);

        // 2,001 statements matching the SQL filters: a refusal, also when a computed filter would leave fewer.
        $this->insertStatements(2001 - 60, BankStatement::UNDONE, '2026-06-01', true);
        $this->list()->assertStatus(422)->assertJsonPath('message', BankReconciliation::LIST_TOO_MANY);
        $this->list(['late' => 'some'])->assertStatus(422)->assertJsonPath('message', BankReconciliation::LIST_TOO_MANY);
        $this->exportList([], 'xlsx')->assertStatus(422)->assertJsonPath('message', BankReconciliation::LIST_TOO_MANY);
        // Narrowed by a SQL filter: listed again.
        $this->list(['status' => 'completed'])->assertOk()->assertJsonPath('total', 59);
    }

    public function test_changed_is_batched_per_account_when_statements_have_late_lines(): void
    {
        // Review SHOULD 3: statements that all have late lines (max_entry_id 0), with Changed filtered and in the export.
        $count = function (array $q) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list($q)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        // Dated after KDY Bank's first line (2026-10-01), so every one has late lines.
        $this->insertStatements(10, BankStatement::COMPLETED, '2026-11-29', false, 0);
        $count([]); // warms the per-process schema check (copies supported)
        $ten = $count(['changed' => 'yes']);
        $tenAny = $count(['per_page' => 100]);
        $this->insertStatements(30, BankStatement::COMPLETED, '2026-10-30', false, 0);
        $this->assertSame($ten, $count(['changed' => 'yes']));
        $this->assertSame($tenAny, $count(['per_page' => 100]));

        // The batched figures equal figures() and the flags equal changed(), statement by statement.
        $recon = app(BankReconciliation::class);
        $dates = ['2026-07-02', '2026-08-31', '2026-10-25', '2026-10-31', '2026-11-30', '2026-12-10'];
        $batched = $recon->figuresAt($this->kdyBank->id, $dates);
        foreach ($dates as $d) {
            $f = $recon->figures($this->kdyBank->id, $d);
            unset($f['expected'], $f['reconciled']);
            $this->assertSame($f, $batched[$d], $d);
        }
        $rows = collect($this->list(['per_page' => 100])->json('data'))->keyBy('id');
        $this->assertCount(43, $rows);
        foreach (BankStatement::withoutGlobalScopes()->whereIn('id', $rows->keys())->get() as $s) {
            $this->assertSame($s->status === BankStatement::COMPLETED && $recon->changed($s), $rows[$s->id]['changed_since_completion'], "statement {$s->id}");
        }
        $this->assertTrue($rows[$this->st1->id]['changed_since_completion']);
        $this->assertGreaterThan(35, $rows->where('changed_since_completion', true)->count());
        $this->assertSame(40, collect($this->list(['per_page' => 100])->json('data'))->where('late_entries_count', '>', 0)->count() - 1);
    }

    public function test_pdf_over_the_cap_is_refused(): void
    {
        $this->insertStatements(1001, BankStatement::UNDONE, '2026-06-01', true);
        $this->exportList([], 'pdf')->assertStatus(422)
            ->assertJsonPath('message', 'This export has 1,004 rows; the limit for PDF is 1,000. Use Excel or narrow the date range or branches.');
        $this->exportList([], 'xlsx')->assertOk();
    }

    public function test_inactive_account_label_in_exports_and_overdrawn(): void
    {
        DB::table('bank_statements')->where('id', $this->st1->id)->update(['statement_balance_cents' => -250000]);
        Account::withoutGlobalScopes()->whereKey($this->kdyBank->id)->update(['status' => 'closed']);
        $text = implode(' ', $this->pdfPages($this->exportList([], 'pdf')->assertOk()->getContent()));
        $this->assertStringContainsString('KDY Bank ('.$this->kdyBank->code.') (inactive)', $text);
        $this->assertStringContainsString('Overdrawn 2,500.00', $text);
    }
}
