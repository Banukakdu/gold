<?php

namespace Tests\Feature\DoubleEntry;

use App\Models\Account;
use App\Models\LedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Double-entry spec ACs 18, 49-51, 53, 55 and the R9 lock control: database checks, ledger:verify V1-V6, entry detail. */
class VerifyAndControlsTest extends DoubleEntryTestCase
{
    private function rawTxn(string $type, string $status, string $date, ?int $categoryId = null, string $ref = 'RAW-1'): int
    {
        return DB::table('transactions')->insertGetId(['ref_no' => $ref, 'branch_id' => $this->kdy->id, 'type' => $type, 'txn_date' => $date, 'amount' => '100.00',
            'account_id' => $this->kdyCash->id, 'category_id' => $categoryId ?? $this->category($type === 'expense' ? 'expense' : 'income')->id,
            'status' => $status, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Insert a line the way a broken writer would (and keep the cached balance in step, so only the tested check fails). */
    private function rawLine(int $txn, Account $acc, string $debit, string $credit, string $date, ?int $contraOf = null): int
    {
        $id = DB::table('ledger_entries')->insertGetId(['transaction_id' => $txn, 'account_id' => $acc->id, 'branch_id' => $acc->branch_id, 'entry_date' => $date,
            'debit' => $debit, 'credit' => $credit, 'contra_of_id' => $contraOf, 'origin' => 'posting', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounts')->where('id', $acc->id)->update(['current_balance' => DB::raw("current_balance + {$debit} - {$credit}")]);

        return $id;
    }

    public function test_ac18_database_refuses_bad_lines(): void
    {
        $e = $this->expense('2026-11-10', '100.00');
        $line = LedgerEntry::where('transaction_id', $e['id'])->where('account_id', $this->kdyCash->id)->sole();
        $base = ['transaction_id' => $e['id'], 'account_id' => $this->kdyCash->id, 'branch_id' => $this->kdy->id, 'entry_date' => '2026-11-10'];
        $cases = [
            'D1' => [['debit' => 0, 'credit' => 0], ['debit' => '5.00', 'credit' => '5.00'], ['debit' => '-5.00', 'credit' => 0], ['debit' => '5.00', 'credit' => '-1.00']],
            'D2' => [['debit' => '5.00', 'credit' => 0, 'branch_id' => $this->gal->id]],
            'D3' => [['debit' => '50.00', 'credit' => 0, 'contra_of_id' => $line->id], ['debit' => 0, 'credit' => '100.00', 'contra_of_id' => $line->id],
                ['debit' => '100.00', 'credit' => 0, 'contra_of_id' => $line->id, 'account_id' => $this->galCash->id, 'branch_id' => $this->gal->id]],
        ];
        $count = LedgerEntry::count();
        foreach ($cases as $rule => $rows) {
            foreach ($rows as $row) {
                try {
                    DB::table('ledger_entries')->insert($row + $base);
                    $this->fail("{$rule} insert was not refused: ".json_encode($row));
                } catch (QueryException $ex) {
                    $this->assertStringContainsString("ledger_entries line check failed ({$rule}).", $ex->getMessage());
                }
            }
        }
        $this->assertSame($count, LedgerEntry::count());
    }

    public function test_ac49_verify_passes_after_every_kind_of_posting(): void
    {
        $e = $this->expense('2026-11-10', '500.00');
        $this->accKdy()->putJson("/api/v1/transactions/{$e['id']}", ['category_id' => $this->cat('expense', 'Water')->id])->assertOk();
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/void", ['reason' => 'x'])->assertOk();
        $i = $this->income('2026-11-10', '700.00');
        $this->accKdy()->postJson("/api/v1/transactions/{$i['id']}/reverse", ['reason' => 'x'])->assertCreated();
        $t = $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '300.00', 'txn_date' => '2026-11-10', 'note' => 'x'])->assertCreated()->json();
        $this->accKdy()->postJson("/api/v1/transactions/{$t['id']}/reverse", ['reason' => 'x'])->assertCreated();
        $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id, 'to_account_id' => $this->mainAcc->id,
            'amount' => '200.00', 'txn_date' => '2026-11-10', 'note' => 'x'])->assertCreated();

        Artisan::call('ledger:verify');
        $out = Artisan::output();
        foreach (['V1', 'V2', 'V3', 'V4', 'V5', 'V6'] as $v) {
            $this->assertMatchesRegularExpression("/{$v} .*: 0/", $out);
        }
        $this->assertStringContainsString('ledger:verify passed.', $out);
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_ac50_unbalanced_entry_fails_v1_v3_and_blocks_the_lock(): void
    {
        $id = $this->rawTxn('income', 'posted', '2026-11-05');
        $this->rawLine($id, $this->kdyCash, '100.00', '0', '2026-11-05');
        Artisan::call('ledger:verify');
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/V1 .*: 1/', $out);
        $this->assertMatchesRegularExpression('/V3 .*: 2/', $out); // KDY and all branches
        $this->assertMatchesRegularExpression('/RAW-1 .*2026-11-05 .*KDY .*100\.00/', $out);
        $this->assertMatchesRegularExpression('/KDY .*100\.00 .*RAW-1 \(100\.00\)/', substr($out, strpos($out, 'V3')));
        $this->artisan('ledger:verify')->assertExitCode(1);

        $this->lock('2026-11', null)->assertStatus(422)->assertJsonPath('message', '1 entries up to November 2026 are not balanced. Run ledger:verify.');
        $this->lock('2026-11', $this->kdy)->assertStatus(422);
        $this->lock('2026-11', $this->gal)->assertCreated();
        $this->lock('2026-10', $this->kdy)->assertCreated();
    }

    public function test_ac51_v2_reports_a_void_line_not_dated_its_entry_date(): void
    {
        $cat = $this->catAcc($this->category('income'), $this->kdy);
        $id = $this->rawTxn('income', 'void', '2026-11-05');
        $a = $this->rawLine($id, $this->kdyCash, '100.00', '0', '2026-11-05');
        $b = $this->rawLine($id, $cat, '0', '100.00', '2026-11-05');
        $this->rawLine($id, $this->kdyCash, '0', '100.00', '2026-11-06', $a);
        $this->rawLine($id, $cat, '100.00', '0', '2026-11-06', $b);
        Artisan::call('ledger:verify');
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/V2 .*: 2/', $out);
        $this->assertMatchesRegularExpression('/D4 .*RAW-1 .*2026-11-06/', $out);
        $this->assertMatchesRegularExpression('/V1 .*: 0/', $out);
        $this->artisan('ledger:verify')->assertExitCode(1);
    }

    public function test_v4_v5_v6_report_their_offenders(): void
    {
        // V4: a head office line without the matching MAIN side (balanced inside KDY).
        $id = $this->rawTxn('fund_return', 'posted', '2026-11-05', null, 'RAW-4');
        $this->rawLine($id, $this->ho($this->kdy), '100.00', '0', '2026-11-05');
        $this->rawLine($id, $this->kdyCash, '0', '100.00', '2026-11-05');
        // V5: an income whose category line is on another category than the entry's.
        $id5 = $this->rawTxn('income', 'posted', '2026-11-05', $this->cat('income', 'Interest')->id, 'RAW-5');
        $this->rawLine($id5, $this->kdyCash, '100.00', '0', '2026-11-05');
        $this->rawLine($id5, $this->catAcc($this->cat('income', 'Consulting'), $this->kdy), '0', '100.00', '2026-11-05');
        // V6: a voided income with money lines only (it nets to zero, so V1 passes).
        $id6 = $this->rawTxn('income', 'void', '2026-11-05', null, 'RAW-6');
        $l = $this->rawLine($id6, $this->kdyCash, '100.00', '0', '2026-11-05');
        $this->rawLine($id6, $this->kdyCash, '0', '100.00', '2026-11-05', $l);

        Artisan::call('ledger:verify');
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/V1 .*: 0/', $out);
        $this->assertMatchesRegularExpression('/V4 .*: 1/', $out);
        $this->assertMatchesRegularExpression('/MAIN <-> KDY/', $out);
        $this->assertMatchesRegularExpression('/V5 .*: 2/', $out);
        $this->assertMatchesRegularExpression('/V6 .*: 1/', $out);
        $this->assertMatchesRegularExpression('/RAW-6 .*income/', $out);
        $this->artisan('ledger:verify')->assertExitCode(1);
    }

    public function test_ac53_entry_detail_lists_the_lines_with_totals(): void
    {
        $t = $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '100000.00', 'txn_date' => '2026-11-05', 'kind' => 'top_up'])->assertCreated()->json();
        $d = $this->sa()->getJson("/api/v1/transactions/{$t['id']}")->assertOk()->json();
        $this->assertTrue($d['balanced']);
        $this->assertSame(['debit' => '200000.00', 'credit' => '200000.00'], $d['totals']);
        $this->assertCount(4, $d['entries']);
        $this->assertSame(['MAIN', 'MAIN', 'KDY', 'KDY'], array_column(array_column($d['entries'], 'branch'), 'code'));
        $this->assertSame(['Main Account', 'Branch current account – KDY', 'Cash', 'Head office current account'], array_column(array_column($d['entries'], 'account'), 'name'));
        $this->assertSame('11001', $d['entries'][0]['account']['code']);
        $this->assertSame(['posting'], array_values(array_unique(array_column($d['entries'], 'origin'))));
        $this->assertArrayNotHasKey('current_balance', $d['entries'][1]['account']);
    }

    public function test_ac55_head_office_reader_opens_a_branch_transfer_but_not_another_branch(): void
    {
        $t = $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '30000.00', 'txn_date' => '2026-11-05', 'note' => 'Support'])->assertCreated()->json();
        $mainUser = $this->userIn($this->main, 'Accountant');
        $st = $this->as($mainUser, $this->main)->getJson("/api/v1/accounts/{$this->bca($this->gal)->id}/statement?from=2026-11-01&to=2026-11-30")->assertOk()->json();
        $this->assertSame($t['id'], $st['entries'][0]['transaction_id']);
        $d = $this->as($mainUser, $this->main)->getJson("/api/v1/transactions/{$t['id']}")->assertOk()->json();
        $this->assertCount(6, $d['entries']);
        $this->assertFalse($d['in_active_branch'], 'edit, void and reverse follow transactions.branch_id');
        $this->assertTrue($this->as($this->ba, $this->gal)->getJson("/api/v1/transactions/{$t['id']}")->assertOk()->json('balanced'));
        $this->assertTrue($this->accKdy()->getJson("/api/v1/transactions/{$t['id']}")->assertOk()->json('in_active_branch'));
        $this->as($mainUser, $this->main)->postJson("/api/v1/transactions/{$t['id']}/reverse", ['reason' => 'x'])->assertNotFound();

        $mtr = $this->makeBranch('MTR');
        $mtrUser = $this->userIn($mtr, 'Accountant');
        $this->as($mtrUser, $mtr)->getJson("/api/v1/transactions/{$t['id']}")->assertNotFound();
        $this->as($mtrUser, $mtr)->getJson('/api/v1/transactions/999999')->assertNotFound();
        $this->sa()->getJson("/api/v1/transactions/{$t['id']}")->assertOk();
    }
}
