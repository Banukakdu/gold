<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * AC 21-24, 26, 34, 35, 35e, 35f: lists, detail, reports, dashboard, ledger:verify, lock review, widget, files, changes
 * after approval, year-end preview and the export's "Requested for".
 */
class ReadersTest extends ExpenseApprovalTestCase
{
    public function test_ac21_lists_detail_reports_and_dashboard(): void
    {
        $e1 = $this->e1();
        $rows = $this->as($this->acc, $this->kdy)->getJson('/api/v1/transactions?status=pending')->assertOk()->json('data');
        $this->assertSame([$e1->id], array_column($rows, 'id'));
        $this->assertSame('pending', $rows[0]['approval_status']);
        $this->assertSame([], $this->as($this->ba, $this->gal)->getJson('/api/v1/transactions?status=pending')->assertOk()->json('data'));
        $this->as($this->ba, $this->gal)->getJson("/api/v1/transactions/{$e1->id}")->assertNotFound();
        $this->as($this->mainBa, $this->main)->getJson("/api/v1/transactions/{$e1->id}")->assertNotFound();

        // Detail buttons (S5).
        $this->as($this->kdyBa, $this->kdy)->getJson("/api/v1/transactions/{$e1->id}")->assertOk()->assertJsonPath('can_approve', true)
            ->assertJsonPath('can_cancel', false)->assertJsonPath('expires_on', '2026-12-20')->assertJsonPath('approval_threshold', '50000.00')
            ->assertJsonPath('super_admin_only', false)->assertJsonPath('requested_period_locked', false);
        $this->as($this->csh, $this->kdy)->getJson("/api/v1/transactions/{$e1->id}")->assertOk()->assertJsonPath('can_approve', false)->assertJsonPath('can_cancel', true);
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$e1->id}")->assertOk()->assertJsonPath('can_approve', false)->assertJsonPath('can_cancel', false);
        $this->sa()->getJson("/api/v1/transactions/{$e1->id}")->assertOk()->assertJsonPath('can_approve', true);

        // Income & Expense report: not in the figures, listed as pending now.
        $rep = $this->as($this->acc, $this->kdy)->getJson('/api/v1/reports/income-expense?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-30', 'type' => 'all']))
            ->assertOk()->json();
        $this->assertSame('0.00', $rep['expense']['total']);
        $this->assertSame([1, '75000.00'], [$rep['pending_approval']['count'], $rep['pending_approval']['amount']]);
        $this->assertSame([$e1->id, 'KDY', 'Utilities > Electricity'], [$rep['pending_approval']['data'][0]['id'],
            $rep['pending_approval']['data'][0]['branch']['code'], $rep['pending_approval']['data'][0]['category_path']]);
        $gal = $this->as($this->ba, $this->gal)->getJson('/api/v1/reports/income-expense?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-30', 'type' => 'all']))
            ->assertOk()->json();
        $this->assertSame(0, $gal['pending_approval']['count']);
        $before = $this->as($this->acc, $this->kdy)->getJson('/api/v1/reports/income-expense?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-17', 'type' => 'all']))
            ->assertOk()->json();
        $this->assertSame(0, $before['pending_approval']['count'], 'requested date after To');
        $res = $this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/income-expense/export', ['from' => '2026-11-01', 'to' => '2026-11-30', 'type' => 'all', 'format' => 'xlsx'])->assertOk();
        $book = IOFactory::createReader('Xlsx')->load($res->getFile()->getPathname());
        $this->assertContains('Pending approval', $book->getSheetNames());
        // SHOULD 10: "Requested on" (sent) and "Requested for" (bill date) side by side.
        $cells = $book->getSheetByName('Pending approval')->toArray(null, true, true, false);
        $head = collect($cells)->first(fn ($r) => in_array('Requested for', $r, true));
        $this->assertSame(['Requested on', 'Requested for', 'Ref no'], array_slice(array_values(array_filter($head, fn ($v) => $v !== null)), 0, 3));
        $line = collect($cells)->first(fn ($r) => in_array($e1->ref_no, $r, true));
        $this->assertSame(['20 Nov 2026', '18 Nov 2026', $e1->ref_no], array_slice(array_values(array_filter($line, fn ($v) => $v !== null)), 0, 3));

        // Dashboard figures and Recent exclude it.
        $d = $this->as($this->acc, $this->kdy)->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('0.00', $d['expense']);
        $this->assertNotContains($e1->id, array_column($d['recent'], 'id'));

        // Edit and void refuse it.
        $this->as($this->acc, $this->kdy)->putJson("/api/v1/transactions/{$e1->id}", ['payee_payer' => 'Someone else'])->assertStatus(422)
            ->assertJsonPath('message', 'This entry cannot be edited.');
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$e1->id}/void", ['reason' => 'Wrong'])->assertStatus(422)
            ->assertJsonPath('message', 'Only posted entries can be voided.');

        // Excel: status label and approval columns.
        $sheet = collect($this->xlsx($this->as($this->acc, $this->kdy)->postJson('/api/v1/transactions/export', ['status' => 'pending'])))->keyBy('Ref no');
        $this->assertSame(['Pending approval', 'Pending approval', '18 Nov 2026'], [$sheet[$e1->ref_no]['Status'], $sheet[$e1->ref_no]['Approval'],
            $sheet[$e1->ref_no]['Requested for']]);
    }

    public function test_ac22_verify_skips_requests_in_v1_and_asserts_them_in_v9(): void
    {
        $e1 = $this->e1();
        $this->artisan('ledger:verify')->doesntExpectOutputToContain('FAILED')->assertExitCode(0);

        $this->reject($e1->id)->assertOk();
        $line = LedgerEntry::where('account_id', $this->kdyCash->id)->first();
        DB::table('ledger_entries')->insert(['transaction_id' => $e1->id, 'account_id' => $line->account_id, 'branch_id' => $line->branch_id,
            'entry_date' => '2026-11-20', 'debit' => '1.00', 'credit' => '0.00', 'origin' => 'posting', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('ledger:verify')->expectsOutputToContain('request that never posted has ledger lines')->expectsOutputToContain($e1->ref_no)->assertExitCode(1);
    }

    public function test_ac22_v9_fails_on_an_approval_status_of_a_type_never_held(): void
    {
        $inc = $this->income('2026-11-20', '1000.00');
        DB::table('transactions')->where('id', $inc['id'])->update(['approval_status' => 'pending']);
        $this->artisan('ledger:verify')->expectsOutputToContain('approval_status pending on a income entry (never held)')
            ->expectsOutputToContain($inc['ref_no'])->assertExitCode(1);
    }

    /** Review 1 (blocker): an approved expense voided through the normal void (R1) keeps verify green; a voided transfer would not. */
    public function test_ac22_an_approved_expense_voided_later_passes_v9(): void
    {
        $e1 = $this->e1();
        $this->approve($e1->id, body: ['comment' => 'Bill seen at the counter'])->assertOk();
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$e1->id}/void", ['reason' => 'Paid twice by mistake'])->assertOk();
        $this->assertSame(['void', 'approved'], [$e1->fresh()->status, $e1->fresh()->approval_status]);
        $this->assertVerifies();

        DB::table('transactions')->where('id', $e1->id)->update(['type' => 'branch_transfer']);
        $this->artisan('ledger:verify')->expectsOutputToContain('status void disagrees with approval_status approved')->assertExitCode(1);
    }

    /** Review 6: the widget's query count does not grow with its rows (no per-row R4a / R4b / lock queries). */
    public function test_ac24_the_widget_has_no_per_row_queries(): void
    {
        $count = function (): int {
            $this->widget($this->kdyBa, $this->kdy); // warm caches (permissions, settings)
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->widget($this->kdyBa, $this->kdy);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $this->e1();
        $one = $count();
        foreach (['2026-11-10', '2026-11-01', '2026-11-19', '2026-11-12'] as $date) {
            $this->e1('60000.00', $date);
        }
        $this->assertCount(5, $this->widget($this->kdyBa, $this->kdy)['to_approve']['data']);
        $this->assertSame($one, $count()); // lock checks are per requested month, everything else per page
    }

    public function test_ac23_lock_review_counts_pending_expenses_and_never_blocks(): void
    {
        $this->e1();
        $this->sa()->getJson('/api/v1/period-locks/review?period=2026-11')->assertOk()
            ->assertJsonPath('expenses_pending_approval', 1)->assertJsonPath('expenses_pending_approval_amount', '75000.00')
            ->assertJsonPath('transfers_pending_approval', 0);
        $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->assertOk()->assertJsonPath('expenses_pending_approval', 0);
        $this->sa()->getJson('/api/v1/period-locks/review?'.http_build_query(['period' => '2026-11', 'branch_id' => $this->gal->id]))->assertOk()
            ->assertJsonPath('expenses_pending_approval', 0);
        $this->lock('2026-11', $this->kdy)->assertCreated();
    }

    public function test_ac24_the_widget(): void
    {
        $e1 = $this->e1();
        $w = $this->widget($this->kdyBa, $this->kdy);
        $this->assertSame('50000.00', $w['threshold']);
        $this->assertSame([1, '75000.00'], [$w['to_approve']['total'], $w['to_approve']['amount']]);
        $row = $w['to_approve']['data'][0];
        $this->assertSame([$e1->id, '200000.00', 0, true, false, 'KDY', 'Utilities > Electricity', 'Ceylon Electricity Board', 0, '2026-12-20', false, false],
            [$row['id'], $row['account']['available'], $row['files_count'], $row['can_approve'], $row['requested_by_you'], $row['branch']['code'],
                $row['category_path'], $row['payee_payer'], $row['age_days'], $row['expires_on'], $row['requester_inactive'], $row['requester_left_branch']]);

        $mine = $this->widget($this->csh, $this->kdy);
        $this->assertSame([], $mine['to_approve']['data']);
        $this->assertSame([$e1->id, true, '2026-12-20'], [$mine['my_requests']['data'][0]['id'], $mine['my_requests']['data'][0]['can_cancel'],
            $mine['my_requests']['data'][0]['expires_on']]);

        $all = $this->widget($this->admin, 'all');
        $this->assertNull($all['threshold']);
        $this->assertSame([$e1->id, 'KDY'], [$all['to_approve']['data'][0]['id'], $all['to_approve']['data'][0]['branch']['code']]);
        $this->assertSame([], $all['my_requests']['data']);
        // Other branches' approvers and non-approvers see nothing (R20).
        $this->assertSame([], $this->widget($this->ba, $this->gal)['to_approve']['data']);
        $this->assertSame([], $this->widget($this->acc, $this->kdy)['to_approve']['data']);
        $this->assertSame([], $this->widget($this->admin, $this->gal)['to_approve']['data']);

        // Requester deactivated or removed from the branch: tags, approval still allowed (R15).
        $this->csh->update(['status' => 'inactive']);
        DB::table('branch_user')->where('user_id', $this->csh->id)->delete();
        $row = $this->widget($this->kdyBa, $this->kdy)['to_approve']['data'][0];
        $this->assertSame([true, true], [$row['requester_inactive'], $row['requester_left_branch']]);
        $this->approve($e1->id)->assertOk();
    }

    public function test_ac26_below_the_limit_in_the_same_category_last_7_days(): void
    {
        $this->record('45000.00', '2026-11-19')->assertCreated()->assertJsonPath('status', 'posted');
        $this->record('45000.00', '2026-11-19', cat: $this->water)->assertCreated()->assertJsonPath('status', 'posted');
        $this->record('10000.00', '2026-11-13', $this->acc)->assertCreated()->assertJsonPath('status', 'posted'); // 8 days ago
        $e1 = $this->e1();
        $row = collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $e1->id);
        $this->assertSame(['count' => 1, 'amount' => '45000.00'], $row['below_threshold_7d']);
    }

    public function test_ac34_files_on_a_pending_request_and_frozen_after_rejection(): void
    {
        $e1 = $this->e1();
        $att = $this->as($this->csh, $this->kdy)->withHeader('Accept', 'application/json')
            ->post("/api/v1/transactions/{$e1->id}/attachments", ['file' => $this->pdf('bill.pdf')])->assertCreated()->json();
        $this->assertSame(1, collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $e1->id)['files_count']);
        // Pending: removal allowed under the AG-062 rights.
        $second = $this->as($this->csh, $this->kdy)->withHeader('Accept', 'application/json')
            ->post("/api/v1/transactions/{$e1->id}/attachments", ['file' => $this->pdf('bill2.pdf', 'b')])->assertCreated()->json();
        $this->as($this->csh, $this->kdy)->postJson("/api/v1/transactions/{$e1->id}/attachments/{$second['id']}/remove", ['reason' => 'Wrong bill'])->assertOk();

        $this->reject($e1->id)->assertOk();
        $this->as($this->kdyBa, $this->kdy)->withHeader('Accept', 'application/json')
            ->post("/api/v1/transactions/{$e1->id}/attachments", ['file' => $this->pdf('late.pdf', 'c')])->assertStatus(422)
            ->assertJsonPath('message', 'This request was not posted; its files cannot be changed.');
        $this->as($this->kdyBa, $this->kdy)->postJson("/api/v1/transactions/{$e1->id}/attachments/{$att['id']}/remove", ['reason' => 'Wrong bill'])
            ->assertStatus(422)->assertJsonPath('message', 'This request was not posted; its files cannot be changed.');
        $this->as($this->kdyBa, $this->kdy)->getJson("/api/v1/transactions/{$e1->id}/attachments")->assertOk()->assertJsonPath('can_add', false)
            ->assertJsonPath('note', 'This request was not posted; its files cannot be changed.');
    }

    public function test_ac35_balances_change_only_through_the_approval_posting(): void
    {
        $balances = fn () => Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all();
        $start = $balances();
        $a = $this->e1();
        $r = $this->e1('60000.00', '2026-11-19');
        $c = $this->e1('55000.00', '2026-11-19');
        $this->reject($r->id)->assertOk();
        $this->cancel($c->id)->assertOk();
        Carbon::setTestNow('2026-12-21 10:00:00');
        $x = $this->e1('51000.00', '2026-11-19');
        Transaction::withoutGlobalScopes()->whereKey($x->id)->update(['requested_on' => '2026-11-20']);
        $this->artisan('expenses:expire-approvals')->assertExitCode(0);
        $this->assertSame('expired', $this->txn($x->id)->approval_status);
        $this->assertSame('expired', $this->txn($a->id)->approval_status);
        $this->assertSame($start, $balances());
        $this->assertVerifies();

        $e = $this->e1('70000.00', '2026-12-21');
        $this->assertSame($start, $balances());
        $this->approve($e->id)->assertOk();
        $after = $balances();
        $changed = array_keys(array_diff_assoc($after, $start));
        sort($changed);
        $expected = [$this->kdyCash->id, $this->catAcc($this->electricity, $this->kdy)->id];
        sort($expected);
        $this->assertSame($expected, $changed);
        $this->assertSame('130000.00', $this->bal($this->kdyCash));
        $this->assertVerifies();
    }

    public function test_ac35e_changes_after_approval_are_flagged(): void
    {
        $e1 = $this->e1();
        $this->approve($e1->id)->assertOk();
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$e1->id}")->assertOk()->assertJsonPath('changed_after_approval', false);
        $this->as($this->acc, $this->kdy)->putJson("/api/v1/transactions/{$e1->id}", ['payee_payer' => 'CEB Kandy'])->assertOk();
        $this->as($this->acc, $this->kdy)->putJson("/api/v1/transactions/{$e1->id}", ['category_id' => $this->water->id])->assertOk();
        $this->assertTrue($this->audits($e1, 'transaction.updated')[0]->new_values['after_approval']);
        $this->assertTrue($this->audits($e1, 'transaction.reclassified')[0]->new_values['after_approval']);
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$e1->id}")->assertOk()->assertJsonPath('changed_after_approval', true);
        $rows = $this->sa()->getJson('/api/v1/audit-logs?from=2026-11-20&to=2026-11-20&approval=after_approval')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['transaction.updated', 'transaction.reclassified'], array_column($rows, 'event'));

        // An expense that never needed approval carries no flag.
        $plain = $this->record('100.00', '2026-11-20')->json('id');
        $this->as($this->acc, $this->kdy)->putJson("/api/v1/transactions/{$plain}", ['payee_payer' => 'Other'])->assertOk();
        $this->assertArrayNotHasKey('after_approval', $this->audits($this->txn($plain), 'transaction.updated')[0]->new_values);
        $this->assertVerifies();
    }

    public function test_ac35f_locked_requested_month_year_end_list_and_requested_for(): void
    {
        $old = $this->e1('75000.00', '2026-10-30');
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $this->assertTrue(collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $old->id)['requested_period_locked']);
        $this->as($this->kdyBa, $this->kdy)->getJson("/api/v1/transactions/{$old->id}")->assertOk()->assertJsonPath('requested_period_locked', true);

        $e1 = $this->e1();
        $this->approve($e1->id)->assertOk();
        $sheet = collect($this->xlsx($this->as($this->acc, $this->kdy)->postJson('/api/v1/transactions/export', ['status' => 'posted'])))->keyBy('Ref no');
        $this->assertSame(['20 Nov 2026', '18 Nov 2026'], [$sheet[$e1->ref_no]['Date'], $sheet[$e1->ref_no]['Requested for']]);

        // Year-end preview lists the pending requests of the fiscal year (Apr 2026 - Mar 2027), oldest first; the close
        // is not refused because of them.
        Carbon::setTestNow('2027-04-10 10:00:00');
        $p = $this->sa()->getJson('/api/v1/fiscal-years/2026/preview')->assertOk()->json();
        $this->assertSame([1, '75000.00'], [$p['expenses_pending_approval'], $p['expenses_pending_approval_amount']]);
        $this->assertSame([[$old->ref_no, 'KDY', '2026-10-30', '75000.00']], array_map(fn ($r) => [$r['ref_no'], $r['branch']['code'], $r['requested_date'], $r['amount']],
            $p['expenses_pending_approval_list']));
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2027-03', 'branch_id' => null])->assertCreated();
        $this->sa()->postJson('/api/v1/fiscal-years/2026/close', ['confirm' => '2026-27'])->assertCreated();
        $this->assertSame('pending', $this->txn($old->id)->approval_status);
    }

    private function pdf(string $name, string $seed = 'a'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%{$seed}\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    /** Rows of the first sheet, by column label. */
    private function xlsx($response): array
    {
        $response->assertOk();
        $ws = IOFactory::createReader('Xlsx')->load($response->getFile()->getPathname())->getSheet(0);
        $r = 1;
        while (($v = $ws->getCell([1, $r])->getValue()) !== null && $v !== '') {
            $r++;
        }
        $r++;
        $labels = [];
        for ($c = 1; ($v = $ws->getCell([$c, $r])->getValue()) !== null; $c++) {
            $labels[] = (string) $v;
        }
        $rows = [];
        for ($r++; $ws->getCell([2, $r])->getValue() !== null; $r++) {
            $row = [];
            foreach ($labels as $i => $label) {
                $row[$label] = (string) $ws->getCell([$i + 1, $r])->getFormattedValue();
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
