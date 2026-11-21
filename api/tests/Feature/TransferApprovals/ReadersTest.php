<?php

namespace Tests\Feature\TransferApprovals;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Services\BackfillPlanner;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** AC 21-24 and 28: lists, reports, dashboard, ledger:verify (V1 skip, V9 assert), back-fill, reversal, lock review, widget. */
class ReadersTest extends TransferApprovalTestCase
{
    public function test_ac21_lists_reports_and_dashboard(): void
    {
        $t = $this->t1();
        $galUser = $this->userIn($this->gal, 'Accountant');
        foreach ([[$this->acc, $this->kdy], [$galUser, $this->gal]] as [$u, $b]) {
            $rows = $this->as($u, $b)->getJson('/api/v1/transactions?status=pending')->assertOk()->json('data');
            $this->assertSame([$t->id], array_column($rows, 'id'), $b->code);
            $this->assertSame('pending', $rows[0]['approval_status']);
        }
        $r = $this->t1('100000.01');
        $c = $this->t1('100000.02');
        $x = $this->t1('100000.03');
        $this->reject($r->id)->assertOk();
        $this->cancel($c->id)->assertOk();
        Transaction::withoutGlobalScopes()->whereKey($x->id)->update(['requested_on' => '2026-10-01']);
        $this->artisan('transfers:expire-approvals')->assertExitCode(0);
        $rejected = $this->as($this->acc, $this->kdy)->getJson('/api/v1/transactions?status=rejected')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$r->id, $c->id, $x->id], array_column($rejected, 'id'));
        $this->assertSame($this->mainBa->name, collect($rejected)->firstWhere('id', $r->id)['decided_by']['name']);

        // Detail: the receiving branch and Main's approver read it; buttons follow R3 / R4.
        $this->as($galUser, $this->gal)->getJson("/api/v1/transactions/{$t->id}")->assertOk()->assertJsonPath('can_approve', false)->assertJsonPath('can_cancel', false);
        $this->as($this->mainBa, $this->main)->getJson("/api/v1/transactions/{$t->id}")->assertOk()->assertJsonPath('can_approve', true)
            ->assertJsonPath('approval_threshold', '100000.00')->assertJsonPath('expires_on', '2026-12-20');
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$t->id}")->assertOk()->assertJsonPath('can_approve', false)->assertJsonPath('can_cancel', true);

        // Transfers report: not in the figures, listed as pending now.
        $rep = $this->as($this->acc, $this->kdy)->getJson('/api/v1/reports/transfers?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->json();
        $this->assertNotContains($t->id, array_column($rep['movements']['data'], 'id'));
        // Only the setUp top-up MAIN -> KDY (100,000.00) moved in November.
        $this->assertSame('100000.00', $rep['net_moved']);
        $this->assertSame([$t->id], array_column($rep['pending_approval']['data'], 'id'));
        $this->assertSame([1, '150000.00'], [$rep['pending_approval']['count'], $rep['pending_approval']['amount']]);
        $cell = collect($rep['matrix']['cells'])->first(fn ($c) => $c['from_id'] === $this->kdy->id && $c['to_id'] === $this->gal->id);
        $this->assertNull($cell, 'GAL has no counted movement in November, so the matrix has no KDY -> GAL cell');

        // Dashboard: money figures and Recent exclude requests.
        $d = $this->as($this->acc, $this->kdy)->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('200000.00', $d['total_balance']);
        $this->assertSame([], array_values(array_intersect([$t->id, $r->id, $c->id, $x->id], array_column($d['recent'], 'id'))));

        // Excel: label, approval columns.
        $sheet = $this->xlsx($this->as($this->acc, $this->kdy)->postJson('/api/v1/transactions/export', ['status' => 'pending,rejected']));
        $rows = collect($sheet)->keyBy('Ref no');
        $this->assertSame(['Pending approval', 'Pending approval', ''], [$rows[$t->ref_no]['Status'], $rows[$t->ref_no]['Approval'], $rows[$t->ref_no]['Decided by']]);
        $this->assertSame(['Rejected', $this->mainBa->name], [$rows[$r->ref_no]['Status'], $rows[$r->ref_no]['Decided by']]);
        $this->assertSame('Cancelled by requester', $rows[$c->ref_no]['Approval']);
        $this->assertSame('Expired', $rows[$x->ref_no]['Status']);
    }

    public function test_ac21_the_report_export_adds_the_pending_sheet(): void
    {
        $this->t1();
        $res = $this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/transfers/export', ['from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'xlsx'])->assertOk();
        $book = IOFactory::createReader('Xlsx')->load($res->getFile()->getPathname());
        $this->assertContains('Pending approval', $book->getSheetNames());
    }

    public function test_ac22_verify_backfill_and_reversal_ignore_a_request(): void
    {
        $t = $this->t1();
        $this->artisan('ledger:verify')->assertExitCode(0);
        $this->assertSame(BackfillPlanner::SKIP, app(BackfillPlanner::class)->plan($t)['status']);
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$t->id}/reverse", ['reason' => 'Wrong'])
            ->assertStatus(422)->assertJsonPath('message', 'Only posted transactions can be reversed.');
    }

    public function test_ac23_lock_review_counts_pending_requests_and_never_blocks(): void
    {
        $this->t1();
        $this->sa()->getJson('/api/v1/period-locks/review?period=2026-11')->assertOk()
            ->assertJsonPath('transfers_pending_approval', 1)->assertJsonPath('transfers_pending_approval_amount', '150000.00');
        $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->assertOk()->assertJsonPath('transfers_pending_approval', 0);
        $this->lock('2026-11', null)->assertCreated();
    }

    public function test_ac24_the_widget(): void
    {
        $t = $this->t1();
        $w = $this->widget($this->mainBa, $this->main);
        $this->assertSame('100000.00', $w['threshold']);
        $this->assertSame([1, '150000.00'], [$w['to_approve']['total'], $w['to_approve']['amount']]);
        $row = $w['to_approve']['data'][0];
        $this->assertSame([$t->id, '200000.00', true, false, '0.00', 'KDY', 'GAL'], [$row['id'], $row['from']['available'], $row['can_approve'],
            $row['requested_by_you'], $row['other_pending_from_account'], $row['from']['branch_code'], $row['to']['branch_code']]);

        $mine = $this->widget($this->acc, $this->kdy);
        $this->assertSame([], $mine['to_approve']['data']);
        $this->assertSame($t->id, $mine['my_requests']['data'][0]['id']);
        $this->assertTrue($mine['my_requests']['data'][0]['can_cancel']);
        $this->assertSame('2026-12-20', $mine['my_requests']['data'][0]['expires_on']);

        $this->assertSame([], $this->widget($this->kdyBa, $this->kdy)['to_approve']['data']);
        $this->assertSame([], $this->widget($this->mainAccUser, $this->main)['to_approve']['data']);
        $this->assertSame([$t->id], array_column($this->widget($this->admin, 'all')['to_approve']['data'], 'id'));
    }

    public function test_ac28_verify_asserts_requests_have_no_lines_and_states_agree(): void
    {
        $t = $this->t1();
        $this->reject($t->id)->assertOk();
        $this->artisan('ledger:verify')->assertExitCode(0);

        $line = LedgerEntry::where('account_id', $this->kdyCash->id)->first();
        DB::table('ledger_entries')->insert(['transaction_id' => $t->id, 'account_id' => $line->account_id, 'branch_id' => $line->branch_id,
            'entry_date' => '2026-11-20', 'debit' => '1.00', 'credit' => '0.00', 'origin' => 'posting', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('ledger:verify')->expectsOutputToContain('V9 transfer requests')->expectsOutputToContain('request that never posted has ledger lines')
            ->expectsOutputToContain($t->ref_no)->assertExitCode(1);
    }

    public function test_ac28_verify_fails_when_status_and_approval_status_disagree(): void
    {
        $u = $this->t1('100000.01');
        DB::table('transactions')->where('id', $u->id)->update(['approval_status' => 'rejected']);
        $this->artisan('ledger:verify')->expectsOutputToContain('V9 transfer requests')
            ->expectsOutputToContain('status pending disagrees with approval_status rejected')->assertExitCode(1);
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
