<?php

namespace Tests\Feature\ReconciliationStatus;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Services\BankReconciliation;
use App\Services\ReconciliationChecks;
use App\Services\ReportExporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * AG-033e bank reconciliation status per account (docs/specs/reconciliation-overdue.md section 7): AC 1-17, 20, 21 on
 * the API. AC 2, 12, 18 and 19 are also covered by the web spec (reconciliation-status.component.spec.ts).
 */
class ReconciliationStatusTest extends StatusTestCase
{
    public function test_ac1_rows_order_summary_and_check_counts(): void
    {
        $r = $this->statusReport()->assertOk()
            ->assertJsonPath('as_at', '2026-12-15')->assertJsonPath('grace_days', 10)
            ->assertJsonPath('due_month_end', '2026-11-30')->assertJsonPath('overdue_through', '2026-11-30');
        $this->assertSame([
            [$this->bank5->id, 'overdue', 3],
            [$this->bank2->id, 'overdue', 1],
            [$this->kdyBank->id, 'up_to_date', 0],
            [$this->bank3->id, 'not_required', null],
            [$this->bank4->id, 'not_required', null],
        ], $this->brief($r));
        $this->assertTrue($this->rowOf($r, $this->bank2)['never_reconciled']);
        $this->assertFalse($this->rowOf($r, $this->bank5)['never_reconciled']);
        $this->assertSame('no_entries', $this->rowOf($r, $this->bank3)['not_required_reason']);
        $this->assertSame('closed_nil', $this->rowOf($r, $this->bank4)['not_required_reason']);
        foreach ([$this->kdyCash, $this->galBank, $this->mainAcc] as $absent) {
            $this->assertNull($this->rowOf($r, $absent));
        }
        $this->assertSame(['overdue' => 2, 'due' => 0, 'up_to_date' => 1, 'not_required' => 2, 'not_checked' => 2, 'recheck' => 0], $r->json('summary'));
        $this->assertSame('Overdue 2 · Due 0 · Up to date 1 · Not required 2', BankReconciliation::statusSummaryText($r->json('summary')));
        $this->assertSame('Latest statement not checked 2 · Re-check needed 0', BankReconciliation::statusCheckText($r->json('summary')));
        $this->assertSame('As at 15 Dec 2026. A month\'s bank reconciliation is due within 10 days after month-end. Overdue counts the month-ends '
            .'after the last completed statement, up to 30 Nov 2026. Undone statements do not count.', $r->json('basis'));
        // No money fields.
        $this->assertArrayNotHasKey('statement_balance', $r->json('data.0.last_statement'));
        // Any other parameter is ignored: always as at today.
        $this->assertSame($r->json('data'), $this->statusReport(['as_at' => '2026-10-31', 'from' => '2026-01-01', 'to' => '2026-01-31'])->assertOk()->json('data'));
    }

    public function test_ac2_row_contents_and_texts(): void
    {
        $r = $this->statusReport();
        $due = $r->json('due_month_end');
        $tag = fn (Account $a) => BankReconciliation::statusTagText($this->rowOf($r, $a), $due);

        $five = $this->rowOf($r, $this->bank5);
        $this->assertSame('Overdue 3 months', $tag($this->bank5));
        $this->assertSame(['id' => $this->st7->id, 'statement_date' => '2026-09-15'], array_intersect_key($five['last_statement'], ['id' => 0, 'statement_date' => 0]));
        $this->assertSame($this->acc->name, $five['last_statement']['completed_by']['name']);
        $this->assertSame('not_checked', $five['check_status']);

        $two = $this->rowOf($r, $this->bank2);
        $this->assertSame('Never reconciled · overdue 1 month', $tag($this->bank2));
        $this->assertNull($two['last_statement']);
        $this->assertNull($two['check_status']);
        $this->assertSame('', ReconciliationChecks::listText($two));

        $kdy = $this->rowOf($r, $this->kdyBank);
        $this->assertSame('Up to date', $tag($this->kdyBank));
        $this->assertSame(['2026-11-30', $this->ba->name], [$kdy['last_statement']['statement_date'], $kdy['last_statement']['completed_by']['name']]);
        $this->assertSame('Not checked', ReconciliationChecks::listText($kdy));

        $this->assertSame('Not required: no entries up to 30 Nov 2026', $tag($this->bank3));

        $four = $this->rowOf($r, $this->bank4);
        $this->assertSame(['id' => $this->bank4->id, 'code' => $this->bank4->code, 'name' => 'KDY Bank 4', 'status' => 'closed'], $four['account']);
        $this->assertSame('KDY Bank 4 ('.$this->bank4->code.') (inactive)', ReportExporter::listAccountText($four['account']));
        $this->assertSame('Not required: inactive, nil balance, reconciled to its last month (30 Jun 2026)', $tag($this->bank4));
        $this->assertSame(['2026-06-30', null, 'checked'], [$four['final_month_end'], $four['months_overdue'], $four['check_status']]);
        $this->assertNull($kdy['final_month_end']); // F only for a closed account
    }

    public function test_ac3_grace_period(): void
    {
        Carbon::setTestNow('2026-12-10 10:00:00');
        $all = $this->statusReport([], $this->admin, 'all')->assertOk()->assertJsonPath('overdue_through', '2026-10-31');
        $gal = $this->rowOf($all, $this->galBank);
        $this->assertSame(['due', 0, '2026-12-10'], [$gal['status'], $gal['months_overdue'], $gal['due_by']]);
        $this->assertSame('Due by 10 Dec 2026', BankReconciliation::statusTagText($gal, '2026-11-30'));

        $r = $this->statusReport();
        $two = $this->rowOf($r, $this->bank2);
        $this->assertSame(['due', 0, true], [$two['status'], $two['months_overdue'], $two['never_reconciled']]);
        $this->assertSame('Never reconciled · due by 10 Dec 2026', BankReconciliation::statusTagText($two, '2026-11-30'));
        $this->assertSame(['overdue', 2], [$this->rowOf($r, $this->bank5)['status'], $this->rowOf($r, $this->bank5)['months_overdue']]);
        // due_by only when Due.
        $this->assertNull($this->rowOf($r, $this->bank5)['due_by']);

        Carbon::setTestNow('2026-12-11 10:00:00');
        $gal = $this->rowOf($this->statusReport([], $this->admin, 'all')->assertJsonPath('overdue_through', '2026-11-30'), $this->galBank);
        $this->assertSame('Overdue 1 month', BankReconciliation::statusTagText($gal, '2026-11-30'));
    }

    public function test_ac4_last_day_of_the_month(): void
    {
        Carbon::setTestNow('2026-12-31 10:00:00');
        $r = $this->statusReport()->assertJsonPath('due_month_end', '2026-12-31')->assertJsonPath('overdue_through', '2026-11-30');
        $kdy = $this->rowOf($r, $this->kdyBank);
        $this->assertSame(['due', 0, '2027-01-10'], [$kdy['status'], $kdy['months_overdue'], $kdy['due_by']]);
        $this->assertSame('Due by 10 Jan 2027', BankReconciliation::statusTagText($kdy, '2026-12-31'));
    }

    public function test_ac5_latest_statement_undone(): void
    {
        $this->undo($this->st3->id, 'Wrong closing balance typed again', $this->ba)->assertOk();
        $kdy = $this->rowOf($this->statusReport(), $this->kdyBank);
        $this->assertSame(['overdue', 1], [$kdy['status'], $kdy['months_overdue']]);
        $this->assertSame(['2026-10-31', $this->acc->name], [$kdy['last_statement']['statement_date'], $kdy['last_statement']['completed_by']['name']]);
        $this->assertSame('checked', $kdy['check_status']);
        $this->assertSame('Checked by '.$this->vw->name.', 2026-12-10', ReconciliationChecks::listText($kdy));
    }

    public function test_ac6_recheck_does_not_change_the_status(): void
    {
        $this->check($this->st3, $this->vw)->assertCreated();
        $this->expense($this->acc, '2000.00', '2026-11-20', '001400');
        $r = $this->statusReport();
        $kdy = $this->rowOf($r, $this->kdyBank);
        $this->assertSame(['up_to_date', 'recheck', true, 1], [$kdy['status'], $kdy['check_status'], $kdy['recheck_needed'], $kdy['check']['late_since_check']]);
        $this->assertSame('Re-check needed (1 since check)', ReconciliationChecks::listText($kdy));
        $this->assertSame(['not_checked' => 1, 'recheck' => 1], array_intersect_key($r->json('summary'), ['not_checked' => 0, 'recheck' => 0]));
    }

    public function test_ac7_reopened_account_and_first_entry_after_the_cut_off(): void
    {
        $this->sa($this->kdy)->putJson("/api/v1/accounts/{$this->bank4->id}", ['status' => 'active'])->assertOk();
        $four = $this->rowOf($this->statusReport(), $this->bank4);
        $this->assertSame(['overdue', 5, null], [$four['status'], $four['months_overdue'], $four['final_month_end']]);

        $this->allocate($this->bank3, '1000.00', '2026-12-02');
        $three = $this->rowOf($this->statusReport(), $this->bank3);
        $this->assertSame(['not_required', 'no_entries'], [$three['status'], $three['not_required_reason']]);
        Carbon::setTestNow('2027-01-11 10:00:00');
        $three = $this->rowOf($this->statusReport(), $this->bank3);
        $this->assertSame('Never reconciled · overdue 1 month', BankReconciliation::statusTagText($three, '2026-12-31'));
    }

    public function test_ac8_status_filter(): void
    {
        $all = $this->statusReport()->json('summary');
        $r = $this->statusReport(['status' => 'overdue']);
        $this->assertSame([$this->bank5->id, $this->bank2->id], $this->accountIds($r));
        $this->assertSame($all, $r->json('summary'));
        $this->assertSame('Filters: Status: Overdue', BankReconciliation::statusFilterText($r->json()));
        $this->statusReport(['status' => 'late'])->assertStatus(422)->assertJsonPath('errors.status.0', 'Choose a valid status.');
        $this->statusReport(['status' => ['overdue']])->assertStatus(422)->assertJsonPath('errors.status.0', 'Choose a valid status.');
        $this->assertSame([], $this->statusReport(['status' => 'due'])->assertOk()->json('data'));
        $this->assertSame([$this->kdyBank->id], $this->accountIds($this->statusReport(['status' => 'up_to_date'])));
        $this->assertSame([$this->bank3->id, $this->bank4->id], $this->accountIds($this->statusReport(['status' => 'not_required'])));
    }

    public function test_ac9_super_admin_all_mode_and_branch_mode(): void
    {
        $r = $this->statusReport([], $this->admin, 'all')->assertOk()->assertJsonPath('all_branches', true);
        $main = $r->json('data.0');
        $this->assertSame([$this->mainAcc->id, 'overdue', 4, true], [$main['account']['id'], $main['status'], $main['months_overdue'], $main['never_reconciled']]);
        $gal = $this->rowOf($r, $this->galBank);
        $this->assertSame(['overdue', 1, 'GAL'], [$gal['status'], $gal['months_overdue'], $gal['branch']['code']]);
        $this->assertNotNull($this->rowOf($r, $this->bank5));
        // Overdue rows: by months (highest first), then MAIN first, branch code, account code.
        $this->assertSame([$this->mainAcc->id, $this->bank5->id, $this->galBank->id, $this->bank2->id],
            array_slice($this->accountIds($r), 0, 4));

        $this->assertSame([$this->galBank->id], $this->accountIds($this->statusReport(['branches' => [$this->gal->id]], $this->admin, 'all')));
        $kdyOnly = $this->statusReport([], $this->admin, $this->kdy);
        $this->assertSame([$this->kdy->id], array_unique(array_column(array_column($kdyOnly->json('data'), 'branch'), 'id')));
        $this->assertCount(5, $kdyOnly->json('data'));
        $this->statusReport(['branches' => [999999]], $this->admin, 'all')->assertStatus(422)->assertJsonPath('errors.branches.0', 'Unknown branch.');
    }

    public function test_ac10_branch_isolation(): void
    {
        $this->statusReport(['branches' => [$this->gal->id]])->assertForbidden()->assertJsonPath('message', 'You do not have access to branch GAL.');
        $this->assertSame([$this->kdyBank->id], array_values(array_intersect([$this->kdyBank->id, $this->galBank->id],
            $this->accountIds($this->statusReport(['branches' => [$this->kdy->id]])))));
        $this->assertSame([$this->galBank->id], $this->accountIds($this->statusReport([], $this->gacc, $this->gal)));
        // A KDY user cannot read GAL through the header either.
        $this->statusReport([], $this->acc, $this->gal)->assertForbidden();
    }

    public function test_ac11_cashier_and_guest(): void
    {
        $this->statusReport([], $this->csh)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.view');
        $this->exportStatus([], 'pdf', $this->csh)->assertForbidden();
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_ac12_viewer_reads_and_follows_up_on_s6(): void
    {
        $this->assertCount(5, $this->statusReport([], $this->vw)->assertOk()->json('data'));
        $this->exportStatus([], 'pdf', $this->vw)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.export');
        $this->exportStatus([], 'xlsx', $this->vw)->assertForbidden();
        // "Statements" on KDY Bank: S6 filtered to that account.
        $this->assertSame([$this->st3->id, $this->st2->id, $this->st1->id], $this->listIds(['account_ids' => [$this->kdyBank->id]], $this->vw));
    }

    public function test_ac13_deactivated_preparer_keeps_name_with_tag(): void
    {
        $before = $this->rowOf($this->statusReport([], $this->vw), $this->bank5);
        DB::table('users')->where('id', $this->acc->id)->update(['status' => 'inactive']);
        $after = $this->rowOf($this->statusReport([], $this->vw), $this->bank5);
        $this->assertSame(['name' => $this->acc->name, 'tag' => 'inactive'], array_intersect_key($after['last_statement']['completed_by'], ['name' => 0, 'tag' => 0]));
        $before['last_statement']['completed_by']['tag'] = 'inactive';
        $this->assertSame($before, $after);
    }

    public function test_review_s2_checker_tags_are_per_branch_in_all_mode(): void
    {
        // VW is a member of KDY only. VW's check on GAL's ST4 and a Super Admin's check on KDY Bank 5 (ST7), written
        // directly (test data: the API would not let VW check in GAL).
        $insert = fn (BankStatement $s, int $by) => DB::table('bank_statement_checks')->insert(['branch_id' => $s->branch_id, 'bank_statement_id' => $s->id,
            'account_id' => $s->account_id, 'checked_by' => $by, 'checked_at' => '2026-12-11 04:30:00', 'compared_with' => 'internet_banking',
            'max_entry_id' => 1_000_000_000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $insert($this->st4, $this->vw->id);
        $insert($this->st7, $this->admin->id);
        $vw = ['id' => $this->vw->id, 'name' => $this->vw->name];
        $sa = ['id' => $this->admin->id, 'name' => $this->admin->name, 'tag' => null];

        // S7, All mode: the same user, tagged per branch; the Super Admin never tagged.
        $r = $this->statusReport([], $this->admin, 'all');
        $this->assertSame($vw + ['tag' => null], $this->rowOf($r, $this->bank4)['check']['checked_by']);
        $this->assertSame($vw + ['tag' => 'no longer in this branch'], $this->rowOf($r, $this->galBank)['check']['checked_by']);
        $this->assertSame($sa, $this->rowOf($r, $this->bank5)['check']['checked_by']);
        $this->assertSame('checked', $this->rowOf($r, $this->galBank)['check_status']);

        // S6, All mode (ReconciliationChecks::statusMap): the same tags.
        $rows = collect($this->as($this->admin, 'all')->getJson('/api/v1/reports/reconciliation-statements?per_page=100&from=2026-01-01')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($vw + ['tag' => null], $rows[$this->st1->id]['check']['checked_by']);
        $this->assertSame($vw + ['tag' => 'no longer in this branch'], $rows[$this->st4->id]['check']['checked_by']);
        $this->assertSame($sa, $rows[$this->st7->id]['check']['checked_by']);
    }

    public function test_ac14_same_accounts_as_the_s6_coverage_sentence(): void
    {
        // KDY Bank 6 (AC 21 case) makes the closed-account rule part of the comparison.
        $six = $this->closingBank('2026-11-20');
        foreach (['2026-12-10', '2026-12-15', '2026-12-31'] as $day) {
            Carbon::setTestNow("{$day} 10:00:00");
            foreach ([[$this->acc, $this->kdy], [$this->vw, $this->kdy], [$this->gacc, $this->gal], [$this->admin, 'all'], [$this->admin, $this->kdy]] as [$who, $branch]) {
                $rows = collect($this->statusReport([], $who, $branch)->json('data'));
                $named = $rows->whereIn('status', ['due', 'overdue'])->pluck('account.id')->sort()->values()->all();
                $s6 = collect($this->coverageIds($who, $branch))->sort()->values()->all();
                $this->assertSame($s6, $named, "{$day} {$who->name}");
                $this->assertNotEmpty($named, $day);
            }
            // With the same Branches selection in All mode.
            $named = collect($this->statusReport(['branches' => [$this->gal->id, $this->kdy->id]], $this->admin, 'all')->json('data'))
                ->whereIn('status', ['due', 'overdue'])->pluck('account.id')->sort()->values()->all();
            $this->assertSame(collect($this->coverageIds($this->admin, 'all', ['branches' => [$this->gal->id, $this->kdy->id]]))->sort()->values()->all(), $named);
        }
        $this->assertContains($six->id, $this->coverageIds($this->acc, $this->kdy));
    }

    public function test_ac15_pdf_export(): void
    {
        $res = $this->exportStatus([], 'pdf')->assertOk();
        $this->assertSame('attachment; filename="reconciliation-status_2026-12-15.pdf"', $res->headers->get('Content-Disposition'));
        // The core-font PDF text is WinAnsi ("·" is one byte).
        $text = preg_replace('/\s+/', ' ', mb_convert_encoding(implode(' ', $this->pdfPages($res->getContent())), 'UTF-8', 'Windows-1252'));
        foreach (['Bank reconciliation status', 'Branches: KDY Branch KDY', 'As at: 15 Dec 2026', 'Printed by '.$this->acc->name,
            'As at 15 Dec 2026. A month\'s bank reconciliation is due within 10 days after month-end.',
            'Overdue 2 · Due 0 · Up to date 1 · Not required 2', 'Latest statement not checked 2 · Re-check needed 0', 'Filters: Status: All',
            'Overdue 3 months', 'Never reconciled · overdue 1 month', 'Up to date', 'Not required: no entries up to 30 Nov 2026',
            'KDY Bank 4 ('.$this->bank4->code.') (inactive)', 'Not required: inactive, nil balance, reconciled to its last month (30 Jun 2026)',
            '15 Sep 2026 by '.$this->acc->name, '30 Nov 2026 by '.$this->ba->name, 'Not checked', 'Checked by '.$this->vw->name.', 20'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('Amounts in', $text);
        $this->assertStringNotContainsString('PROVISIONAL', $text);
        // Same order as the screen.
        $positions = array_map(fn ($n) => strpos($text, $n), ['KDY Bank 5', 'KDY Bank 2', 'KDY Bank ('.$this->kdyBank->code.')', 'KDY Bank 3', 'KDY Bank 4']);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        // Branch mode: no Branch column; All mode has it.
        $this->assertStringNotContainsString('BRANCH ACCOUNT', $text);
        $this->assertStringContainsString('BRANCH ACCOUNT', implode(' ', $this->pdfPages($this->exportStatus([], 'pdf', $this->admin, 'all')->assertOk()->getContent())));

        $audit = AuditLog::where('event', 'report.exported')->orderBy('id')->first();
        $this->assertSame(['reconciliation-status', 'pdf', 5], [$audit->new_values['name'], $audit->new_values['format'], $audit->new_values['rows']]);
        $this->assertSame($this->kdy->id, (int) $audit->branch_id);
        $this->assertSame('all', $audit->new_values['params']['status']);
    }

    public function test_ac15_excel_export(): void
    {
        DB::table('accounts')->where('id', $this->bank3->id)->update(['name' => '=SUM(A1)']);
        $res = $this->exportStatus(['as_at' => '2020-01-01'], 'xlsx')->assertOk();
        $this->assertSame('attachment; filename="reconciliation-status_2026-12-15.xlsx"', $res->headers->get('Content-Disposition'));
        $ws = $this->xlsx($res)->getSheet(0);
        $grid = $ws->toArray(null, false, false);
        $lines = array_map(fn ($r) => (string) $r[0], $grid);
        foreach (['Bank reconciliation status', 'As at: 15 Dec 2026', 'As at 15 Dec 2026. A month', 'Overdue 2 · Due 0 · Up to date 1 · Not required 2',
            'Latest statement not checked 2 · Re-check needed 0', 'Filters: Status: All'] as $needle) {
            $this->assertNotEmpty(array_filter($lines, fn ($l) => str_contains($l, $needle)), $needle);
        }
        $this->assertEmpty(array_filter($lines, fn ($l) => str_contains($l, 'PROVISIONAL') || str_contains($l, 'Amounts in')));
        $head = array_search('Branch', $lines, true);
        $this->assertNotFalse($head);
        $columns = $grid[$head];
        $this->assertSame(['Branch', 'Account code', 'Account', 'Account status', 'Status', 'Months overdue', 'Due by', 'Last statement date', 'Last completed by',
            'Check status', 'Checked by', 'Checked at', 'Re-check needed'], array_slice($columns, 0, 13));
        $col = fn (string $label) => array_search($label, $columns, true);
        $rows = array_slice($grid, $head + 1, 5);
        $this->assertSame([$this->bank5->code, $this->bank2->code, $this->kdyBank->code, $this->bank3->code, $this->bank4->code], array_column($rows, $col('Account code')));
        $this->assertSame(['Overdue 3 months', 'Never reconciled · overdue 1 month', 'Up to date', 'Not required: no entries up to 30 Nov 2026',
            'Not required: inactive, nil balance, reconciled to its last month (30 Jun 2026)'], array_column($rows, $col('Status')));
        $this->assertSame([3, 1, 0, null, null], array_map(fn ($v) => $v === null ? null : (int) $v, array_column($rows, $col('Months overdue'))));
        $this->assertSame(DataType::TYPE_NUMERIC, $ws->getCell([$col('Months overdue') + 1, $head + 2])->getDataType());
        $this->assertSame(['2026-09-15', null, '2026-11-30', null, '2026-06-30'],
            array_map(fn ($v) => $v === null ? null : ExcelDate::excelToDateTimeObject($v)->format('Y-m-d'), array_column($rows, $col('Last statement date'))));
        $this->assertSame(['Active', 'Active', 'Active', 'Active', 'Inactive'], array_column($rows, $col('Account status')));
        $this->assertSame(['Not checked', null, 'Not checked', null, 'Checked'], array_column($rows, $col('Check status')));
        $this->assertSame(['No', 'No', 'No', 'No', 'No'], array_column($rows, $col('Re-check needed')));
        $checkedAt = $ws->getCell([$col('Checked at') + 1, $head + 6]);
        $this->assertSame(DataType::TYPE_NUMERIC, $checkedAt->getDataType());
        $this->assertSame($this->vw->name, $rows[4][$col('Checked by')]);
        // Formula injection: the account name is text with the quote prefix.
        $cell = $ws->getCell([$col('Account') + 1, $head + 5]);
        $this->assertSame('=SUM(A1)', $cell->getValue());
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertTrue($ws->getStyle($cell->getCoordinate())->getQuotePrefix());
        $this->assertSame(['xlsx', 5], [AuditLog::where('event', 'report.exported')->sole()->new_values['format'], AuditLog::where('event', 'report.exported')->sole()->new_values['rows']]);

        // Due by as a date cell (2026-12-10: Due rows).
        Carbon::setTestNow('2026-12-10 10:00:00');
        $ws = $this->xlsx($this->exportStatus(['status' => 'due'], 'xlsx')->assertOk())->getSheet(0);
        $grid = $ws->toArray(null, false, false);
        $head = array_search('Branch', array_map(fn ($r) => (string) $r[0], $grid), true);
        $this->assertNotEmpty(array_filter(array_column($grid, 0), fn ($l) => (string) $l === 'Filters: Status: Due'));
        $this->assertSame('2026-12-10', ExcelDate::excelToDateTimeObject($grid[$head + 1][$col('Due by')])->format('Y-m-d'));
        $this->assertSame(DataType::TYPE_NUMERIC, $ws->getCell([$col('Due by') + 1, $head + 2])->getDataType());
    }

    public function test_ac16_read_only(): void
    {
        $tables = fn () => array_map(fn ($t) => DB::table($t)->orderBy($t === 'settings' ? 'key' : 'id')->get()->map(fn ($r) => (array) $r)->all(),
            ['ledger_entries', 'bank_reconciliation_marks', 'bank_statements', 'bank_statement_checks', 'settings', 'transactions']);
        $balances = fn () => DB::table('accounts')->orderBy('id')->pluck('current_balance', 'id')->all();
        $before = [$tables(), $balances()];
        $audits = AuditLog::count();
        foreach ([[], ['status' => 'overdue'], ['status' => 'not_required']] as $q) {
            $this->statusReport($q)->assertOk();
            $this->statusReport($q, $this->admin, 'all')->assertOk();
        }
        $this->assertSame($audits, AuditLog::count());
        $this->exportStatus([], 'pdf')->assertOk();
        $this->exportStatus([], 'xlsx', $this->admin, 'all')->assertOk();
        $this->assertSame($before, [$tables(), $balances()]);
        $this->assertSame(['report.exported', 'report.exported'], AuditLog::where('id', '>', AuditLog::max('id') - 2)->orderBy('id')->pluck('event')->all());
        $this->assertSame($audits + 2, AuditLog::count());
        $this->assertVerifies();
    }

    public function test_ac17_fixed_number_of_queries(): void
    {
        $count = function (array $branchIds) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $r = $this->statusReport(['branches' => $branchIds], $this->admin, 'all')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$n, $r];
        };
        // 1 branch with 1 bank account (with a completed and checked statement).
        $first = $this->makeBranch('B00');
        $branches = [$first];
        $this->seedAccounts($first, 1);
        $count([$first->id]); // warm-up (per-process schema checks)
        [$one, $r] = $count([$first->id]);
        $this->assertCount(1, $r->json('data'));

        // 20 branches with 5 bank accounts each.
        $this->seedAccounts($first, 4);
        for ($i = 1; $i < 20; $i++) {
            $b = $this->makeBranch(sprintf('B%02d', $i));
            $branches[] = $b;
            $this->seedAccounts($b, 5);
        }
        [$many, $r] = $count(array_map(fn (Branch $b) => $b->id, $branches));
        $this->assertCount(100, $r->json('data'));
        $this->assertSame(100, collect($r->json('data'))->whereNotNull('check')->count());
        $this->assertSame($one, $many);
    }

    /** $n bank accounts in $b, each with one line, a completed statement (by ACC) and an active check (by VW), written directly. */
    private function seedAccounts(Branch $b, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $a = $this->bankIn($b, "Bank {$b->code} {$i}");
            $this->allocate($a, '100.00', '2026-10-01');
            $sid = DB::table('bank_statements')->insertGetId(['branch_id' => $b->id, 'account_id' => $a->id, 'statement_date' => '2026-10-31',
                'statement_balance_cents' => 10000, 'reconciled_cents' => 10000, 'book_balance_cents' => 10000, 'uncleared_receipts_cents' => 0,
                'uncleared_payments_cents' => 0, 'cleared_booked_later_cents' => 0, 'self_count' => 0, 'lines_count' => 0, 'status' => BankStatement::COMPLETED,
                'completed_by' => $this->acc->id, 'completed_at' => '2026-11-02 10:00:00', 'max_entry_id' => 1_000_000_000, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bank_statement_checks')->insert(['branch_id' => $b->id, 'bank_statement_id' => $sid, 'account_id' => $a->id, 'checked_by' => $this->vw->id,
                'checked_at' => '2026-11-03 10:00:00', 'compared_with' => 'internet_banking', 'max_entry_id' => 1_000_000_000, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_ac20_schedules_refuse_the_report(): void
    {
        $this->as($this->admin, 'all')->postJson('/api/v1/report-schedules', ['name' => 'Overdue reconciliations', 'report' => 'reconciliation-status'])
            ->assertStatus(422)->assertJsonValidationErrors('report');
    }

    /** KDY Bank 6: 5,000.00 allocated 2026-10-10, reconciled to 31 Oct 2026, then emptied into KDY Bank on $emptied and closed. */
    private function closingBank(string $emptied): Account
    {
        $now = Carbon::now();
        Carbon::setTestNow('2026-11-02 10:00:00');
        $six = $this->bankIn($this->kdy, 'KDY Bank 6');
        $in = $this->allocate($six, '5000.00', '2026-10-10');
        $this->tick([[$in, '2026-10-12']], $this->acc, [], $six)->assertOk();
        $this->complete('2026-10-31', '5000.00', $this->acc, $six)->assertCreated();
        Carbon::setTestNow(max($now, Carbon::parse("{$emptied} 12:00:00")));
        $out = $this->transferOut($six, $this->kdyBank, '5000.00', $emptied);
        $this->sa($this->kdy)->putJson("/api/v1/accounts/{$six->id}", ['status' => 'closed'])->assertOk();
        $six->setAttribute('closing_transfer', $out);
        Carbon::setTestNow($now);

        return $six;
    }

    public function test_ac21_closed_account_until_its_final_month_is_reconciled(): void
    {
        $six = $this->closingBank('2026-11-20');
        $out = $six->getAttribute('closing_transfer');
        $row = $this->rowOf($this->statusReport(), $six);
        $this->assertSame(['overdue', 1, '2026-11-30', null], [$row['status'], $row['months_overdue'], $row['final_month_end'], $row['not_required_reason']]);
        $this->assertContains($six->id, $this->coverageIds($this->acc, $this->kdy));
        $this->assertStringContainsString('KDY Bank 6 ('.$six->code.'), last statement 31 Oct 2026',
            BankReconciliation::coverageText($this->as($this->acc, $this->kdy)->getJson('/api/v1/reports/reconciliation-statements')->json('coverage')));

        Carbon::setTestNow('2026-12-05 10:00:00');
        $this->assertSame('Due by 10 Dec 2026', BankReconciliation::statusTagText($this->rowOf($this->statusReport(), $six), '2026-11-30'));

        // The final month reconciled (0.00): not required, and S6 no longer names it.
        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->tick([[$out, '2026-11-20']], $this->acc, [], $six)->assertOk();
        $final = $this->complete('2026-11-30', '0.00', $this->acc, $six)->assertCreated()->json('id');
        $row = $this->rowOf($this->statusReport(), $six);
        $this->assertSame(['not_required', 'closed_nil', null], [$row['status'], $row['not_required_reason'], $row['months_overdue']]);
        $this->assertSame('Not required: inactive, nil balance, reconciled to its last month (30 Nov 2026)', BankReconciliation::statusTagText($row, '2026-11-30'));
        $this->assertNotContains($six->id, $this->coverageIds($this->acc, $this->kdy));
        Carbon::setTestNow('2027-02-15 10:00:00');
        $this->assertSame('not_required', $this->rowOf($this->statusReport(), $six)['status']);
        $this->assertNotContains($six->id, $this->coverageIds($this->acc, $this->kdy));

        // Undone again: on 2027-01-15 it is overdue 1 (November only: stop = F).
        Carbon::setTestNow('2027-01-15 10:00:00');
        $this->as($this->acc, $this->kdy)->postJson($this->url($six, "/statements/{$final}/undo"), ['reason' => 'Wrong closing balance typed'])->assertOk();
        $row = $this->rowOf($this->statusReport(), $six);
        $this->assertSame(['overdue', 1, '2026-11-30'], [$row['status'], $row['months_overdue'], $row['final_month_end']]);
        $this->assertContains($six->id, $this->coverageIds($this->acc, $this->kdy));
    }

    public function test_ac21_closed_after_the_cut_off_stays_required_until_reconciled_to_its_final_month(): void
    {
        Carbon::setTestNow('2026-12-15 10:00:00');
        $seven = $this->closingBank('2026-12-03');
        $out = $seven->getAttribute('closing_transfer');
        // Balance at 30 Nov 2026 is not nil: required like an active account.
        $row = $this->rowOf($this->statusReport(), $seven);
        $this->assertSame(['overdue', 1, null], [$row['status'], $row['months_overdue'], $row['not_required_reason']]);
        $this->assertContains($seven->id, $this->coverageIds($this->acc, $this->kdy));

        // 2027-01-15: L = 31 Dec, nil, F = 31 Dec: still required until reconciled to 31 Dec 2026.
        Carbon::setTestNow('2027-01-15 10:00:00');
        $row = $this->rowOf($this->statusReport(), $seven);
        $this->assertSame(['overdue', 2, '2026-12-31'], [$row['status'], $row['months_overdue'], $row['final_month_end']]);
        $this->assertContains($seven->id, $this->coverageIds($this->acc, $this->kdy));
        $this->tick([[$out, '2026-12-03']], $this->acc, [], $seven)->assertOk();
        $this->complete('2026-11-30', '0.00', $this->acc, $seven)->assertStatus(422); // reconciled at 30 Nov is 5,000.00
        $this->complete('2026-12-31', '0.00', $this->acc, $seven)->assertCreated();
        $this->assertSame('closed_nil', $this->rowOf($this->statusReport(), $seven)['not_required_reason']);
        $this->assertNotContains($seven->id, $this->coverageIds($this->acc, $this->kdy));
    }

    public function test_r4_month_counting_helpers(): void
    {
        $this->assertSame(3, BankReconciliation::monthEndsBetween('2026-09-15', '2026-11-30'));
        $this->assertSame(1, BankReconciliation::monthEndsBetween('2026-11-04', '2026-11-30'));
        $this->assertSame(4, BankReconciliation::monthEndsBetween('2026-07-31', '2026-11-30'));
        $this->assertSame(0, BankReconciliation::monthEndsBetween('2026-11-30', '2026-11-30'));
        $this->assertSame(0, BankReconciliation::monthEndsBetween('2026-11-30', '2026-10-31'));
        $this->assertSame(1, BankReconciliation::monthEndsBetween('2024-02-28', '2024-02-29'));
        $this->assertSame('2026-11-30', BankReconciliation::monthEnd('2026-11-20'));
        $this->assertNull(BankReconciliation::notRequired('closed', ['first' => '2026-06-05', 'last' => '2026-06-20', 'book' => 0], '2026-06-15'));
        $this->assertSame('closed_nil', BankReconciliation::notRequired('closed', ['first' => '2026-06-05', 'last' => '2026-06-20', 'book' => 0], '2026-06-30'));
        $this->assertNull(BankReconciliation::notRequired('closed', ['first' => '2026-06-05', 'last' => '2026-06-20', 'book' => 100], '2026-06-30'));
        $this->assertNull(BankReconciliation::notRequired('active', ['first' => '2026-06-05', 'last' => '2026-06-20', 'book' => 0], '2026-06-30'));
        $this->assertSame('no_entries', BankReconciliation::notRequired('active', null, null));
    }
}
