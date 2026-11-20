<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Section 8 "Statement, report, review": AC 26-33; review AC 37 and 41; SHOULD 10. */
class ReportTest extends BankReconciliationTestCase
{
    private function export(array $q, string $format, ?User $by = null, $branch = null)
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export', $q + ['format' => $format]);
    }

    public function test_ac26_account_statement_cleared_column(): void
    {
        $this->tick([[$this->top, '2026-10-31']])->assertOk();
        $rows = collect($this->as($this->vw, $this->kdy)->getJson("/api/v1/accounts/{$this->kdyBank->id}/statement?from=2026-10-01&to=2026-10-31")->assertOk()->json('entries'));
        $this->assertSame('2026-10-31', $rows->firstWhere('transaction_id', $this->top->id)['cleared_date']);
        $this->assertSame([null], $rows->where('transaction_id', '!=', $this->top->id)->pluck('cleared_date')->unique()->values()->all());
        $cash = $this->as($this->vw, $this->kdy)->getJson("/api/v1/accounts/{$this->kdyCash->id}/statement?from=2026-10-01&to=2026-11-30")->assertOk();
        $this->income($this->csh, '10.00', '2026-11-01', $this->kdyCash);
        foreach ($this->as($this->vw, $this->kdy)->getJson("/api/v1/accounts/{$this->kdyCash->id}/statement?from=2026-10-01&to=2026-11-30")->json('entries') as $e) {
            $this->assertArrayNotHasKey('cleared_date', $e);
        }
        $this->assertNotNull($cash);
    }

    public function test_ac27_unreconciled_report_as_at_october_and_november(): void
    {
        $this->statementOne();
        $r = $this->report(['as_at' => '2026-10-31'])->assertOk()->assertJsonPath('provisional', false)->assertJsonPath('statement', null);
        $this->assertCount(1, $r->json('accounts'));
        $a = $r->json('accounts.0');
        $this->assertSame([$this->i2->ref_no, $this->e2->ref_no], collect($a['rows'])->sortByDesc('date')->pluck('ref_no')->values()->all());
        $this->assertSame([0, 1], collect($a['rows'])->sortByDesc('date')->pluck('age_days')->values()->all());
        $this->assertSame(['469500.00', '15000.00', '25000.00', '0.00', '479500.00'],
            [$a['book_balance'], $a['uncleared_receipts'], $a['uncleared_payments'], $a['cleared_booked_later'], $a['expected_bank_balance']]);
        $this->assertSame('2026-10-31', $a['last_statement']['statement_date']);
        $this->assertSame('479500.00', $a['last_statement']['statement_balance']);
        $this->assertSame('11002', $a['account']['code']);

        $this->statementTwo();
        $a = $this->report(['as_at' => '2026-11-20'])->assertOk()->json('accounts.0');
        $this->assertSame([], $a['rows']);
        $this->assertSame($a['book_balance'], $a['expected_bank_balance']);
        // AC 28: a branch user asking for another branch gets 403 (reports R1).
        $this->report(['as_at' => '2026-11-20', 'branches' => [$this->gal->id]], $this->acc)->assertForbidden();
        $this->report(['as_at' => '2026-12-02'])->assertStatus(422)->assertJsonValidationErrors('as_at');
        $this->report([])->assertStatus(422)->assertJsonValidationErrors('as_at');
    }

    public function test_ac28_super_admin_all_mode_one_section_per_bank_account(): void
    {
        $r = $this->report(['as_at' => '2026-11-20', 'branches' => [$this->kdy->id, $this->gal->id]], $this->admin, 'all')->assertOk();
        $this->assertSame(['KDY Bank', 'GAL Bank'], collect($r->json('accounts'))->pluck('account.name')->sort()->reverse()->values()->all());
        $this->assertSame(['GAL', 'KDY'], collect($r->json('accounts'))->pluck('account.branch.code')->values()->all());
        $this->report(['as_at' => '2026-11-20', 'account_ids' => [$this->kdyCash->id]], $this->admin, 'all')->assertStatus(422)->assertJsonValidationErrors('account_ids');
        $this->report(['as_at' => '2026-11-20', 'account_ids' => [$this->galBank->id]])->assertStatus(422);
    }

    public function test_ac29_older_than(): void
    {
        $this->expense($this->csh, '10.00', '2026-11-01');
        $rows = $this->report(['as_at' => '2026-11-20', 'older_than' => 20])->assertOk()->json('accounts.0.rows');
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertLessThanOrEqual('2026-10-31', $r['date']);
        }
        $this->assertCount(6, $this->report(['as_at' => '2026-11-20'])->json('accounts.0.rows'));
        $this->report(['as_at' => '2026-11-20', 'older_than' => 3651])->assertStatus(422);
    }

    public function test_ac30_pdf_and_excel_exports(): void
    {
        $this->statementOne();
        $res = $this->export(['as_at' => '2026-10-31'], 'pdf')->assertOk();
        $this->assertSame('attachment; filename="bank-reconciliation_2026-10-31.pdf"', $res->headers->get('Content-Disposition'));
        $text = implode(' ', $this->pdfPages($res->getContent()));
        foreach (['Unreconciled bank entries', 'Branches: KDY Branch KDY', 'As at:', 'Printed by '.$this->acc->name, $this->i2->ref_no, $this->e2->ref_no,
            '469,500.00', '15,000.00', '25,000.00', '479,500.00', 'Expected bank balance', 'Prepared by', 'Checked by', 'Approved by'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('PROVISIONAL', $text);

        $res = $this->export(['as_at' => '2026-10-31'], 'xlsx')->assertOk();
        $this->assertSame('attachment; filename="bank-reconciliation_2026-10-31.xlsx"', $res->headers->get('Content-Disposition'));
        $ws = $this->xlsx($res)->getSheet(0);
        $cells = collect($ws->toArray(null, false, false))->flatten()->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (string) $v)->values()->all();
        foreach (['Unreconciled bank entries', 'Branches: KDY Branch KDY', $this->i2->ref_no, $this->e2->ref_no, '469500', '15000', '25000', '479500', '= Expected bank balance'] as $needle) {
            $this->assertNotEmpty(array_filter($cells, fn ($c) => str_contains($c, $needle)), $needle);
        }
        $audits = AuditLog::where('event', 'report.exported')->orderBy('id')->get();
        $this->assertSame(['pdf', 'xlsx'], $audits->map(fn ($a) => $a->new_values['format'])->all());
        $this->assertSame('bank-reconciliation', $audits[0]->new_values['name']);
        $this->assertSame($this->kdy->id, (int) $audits[0]->branch_id);

        $this->export(['as_at' => '2026-10-31'], 'pdf', $this->vw)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.export');
    }

    public function test_statement_reprint_and_signature_block(): void
    {
        $s = $this->statementOne();
        $r = $this->report(['statement_id' => $s->id, 'as_at' => '2026-01-01'], $this->acc)->assertOk()->assertJsonPath('as_at', '2026-10-31')
            ->assertJsonPath('statement.difference', '0.00')->assertJsonPath('statement.changed_since_completion', false)
            ->assertJsonPath('statement.statement_balance', '479500.00')->assertJsonPath('statement.self_count', 2)->assertJsonPath('statement.lines_count', 4);
        $this->assertCount(1, $r->json('accounts'));
        $text = implode(' ', $this->pdfPages($this->export(['statement_id' => $s->id], 'pdf')->assertOk()->getContent()));
        foreach (['Bank reconciliation statement', 'Balance per bank statement', 'Difference', 'Prepared by '.$this->acc->name, '2 of 4 lines were recorded by the preparer',
            'Checked by', 'Approved by'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        // Another branch's statement is not in scope.
        $this->report(['statement_id' => $s->id], $this->gacc, $this->gal)->assertNotFound();
        $this->report(['statement_id' => $s->id], $this->admin, 'all')->assertOk();
    }

    public function test_ac36_overdrawn_on_the_pdf(): void
    {
        $this->tick([[$this->e2, '2026-10-31']])->assertOk();
        $id = $this->complete('2026-10-31', '-25000.00')->assertCreated()->json('id');
        $text = implode(' ', $this->pdfPages($this->export(['statement_id' => $id], 'pdf')->assertOk()->getContent()));
        $this->assertStringContainsString('Overdrawn 25,000.00', $text);
    }

    public function test_ac37_undone_statement_banner_on_every_page(): void
    {
        $this->statementOne();
        $s2 = $this->statementTwo();
        // Enough uncleared lines for more than one page.
        for ($i = 0; $i < 45; $i++) {
            $this->income($this->csh, '1.00', '2026-11-1'.($i % 9));
        }
        $this->undo($s2->id)->assertOk();
        $pages = $this->pdfPages($this->export(['statement_id' => $s2->id], 'pdf')->assertOk()->getContent());
        $this->assertGreaterThan(1, count($pages));
        $date = app(Settings::class)->formatDate('2026-12-01');
        foreach ($pages as $p) {
            $this->assertStringContainsString("UNDONE on {$date} by {$this->acc->name}: Wrong closing balance typed. This statement is superseded.", $p);
        }
        $ws = $this->xlsx($this->export(['statement_id' => $s2->id], 'xlsx')->assertOk())->getSheet(0);
        $this->assertStringContainsString('This statement is superseded.', implode(' ', array_map(fn ($r) => (string) $r[0], $ws->toArray(null, false, false))));
    }

    public function test_ac41_stale_cheque(): void
    {
        $this->allocate($this->kdyBank, '1000.00', '2026-04-30');
        $old = $this->expense($this->acc, '700.00', '2026-05-01', '000900');
        $row = collect($this->report(['as_at' => '2026-11-20'])->json('accounts.0.rows'))->firstWhere('ref_no', $old->ref_no);
        $this->assertSame(203, $row['age_days']);
        $this->assertTrue($row['stale']);
        $this->assertFalse(collect($this->report(['as_at' => '2026-11-20'])->json('accounts.0.rows'))->firstWhere('ref_no', $this->i2->ref_no)['stale']);
    }

    public function test_ac31_period_lock_review_lists_bank_accounts_not_reconciled_and_never_blocks(): void
    {
        // KDY Bank reconciled to 2026-09-30 (nothing to tick before October).
        $this->complete('2026-09-30', '0.00')->assertCreated();
        $review = $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10&branch_id='.$this->kdy->id)->assertOk()->json('bank_reconciliation');
        $this->assertSame([['branch' => ['id' => $this->kdy->id, 'code' => 'KDY'], 'account' => ['id' => $this->kdyBank->id, 'code' => '11002', 'name' => 'KDY Bank'],
            'last_statement_date' => '2026-09-30', 'changed_since_completion' => false]], $review);
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-10', 'branch_id' => $this->kdy->id])->assertCreated();
        // All branches: GAL Bank was never reconciled.
        $all = collect($this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->json('bank_reconciliation'));
        $this->assertNull($all->firstWhere('account.name', 'GAL Bank')['last_statement_date']);
    }

    public function test_ac32_audit_log_group(): void
    {
        $s = $this->statementOne();
        $this->undo($s->id)->assertOk();
        $rows = $this->as($this->acc, $this->kdy)->getJson('/api/v1/audit-logs?event_group=bank_reconciliation&from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(['reconciliation.completed', 'reconciliation.saved', 'reconciliation.undone'], collect($rows)->pluck('event')->unique()->sort()->values()->all());
        $this->assertSame(['reconciliation.completed' => 'Bank statement completed', 'reconciliation.saved' => 'Bank lines reconciled',
            'reconciliation.undone' => 'Bank statement undone'], collect($rows)->pluck('event_label', 'event')->sortKeys()->all());
        $this->assertSame('KDY Bank', $rows[0]['subject']);
    }

    public function test_ac33_ledger_verify_v10(): void
    {
        $s = $this->statementOne();
        $this->assertVerifies();
        // A mark edited in the database to point at a line of a void transaction.
        $e4 = $this->expense($this->acc, '100.00', '2026-11-05');
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$e4->id}/void", ['reason' => 'Duplicate'])->assertOk();
        DB::table('bank_reconciliation_marks')->insert(['branch_id' => $this->kdy->id, 'account_id' => $this->kdyBank->id, 'ledger_entry_id' => $this->line($e4)->id,
            'cleared_date' => '2026-11-06', 'marked_by' => $this->acc->id, 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('ledger:verify')->expectsOutputToContain('V10')->expectsOutputToContain('transaction is void')->assertExitCode(1);
        DB::table('bank_reconciliation_marks')->where('ledger_entry_id', $this->line($e4)->id)->update(['unmarked_at' => now(), 'unmarked_by' => $this->acc->id]);
        $this->assertVerifies();
        // A completed statement whose stored reconciled figure no longer matches.
        DB::table('bank_statements')->where('id', $s->id)->update(['reconciled_cents' => 1]);
        $this->artisan('ledger:verify')->expectsOutputToContain("statement #{$s->id}")->assertExitCode(1);
    }

    public function test_should10_account_list_carries_the_last_reconciled_date(): void
    {
        $this->statementOne();
        $list = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/accounts')->assertOk()->json())->keyBy('id');
        $this->assertSame('2026-10-31', $list[$this->kdyBank->id]['last_reconciled_date']);
        $this->assertArrayNotHasKey('last_reconciled_date', $list[$this->kdyCash->id]);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->assertSame(BankStatement::COMPLETED, BankStatement::withoutGlobalScopes()->sole()->status);
    }
}
