<?php

namespace Tests\Feature\Controls;

use App\Exceptions\ImmutableRecordException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Support\LegacyVoids;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** AG-063 void without delete: controls spec AC 1-9, 32, 34, 35. */
class VoidTest extends ControlsTestCase
{
    private function voidE(): array
    {
        $start = $this->balance($this->kdyCash);
        $e = $this->expense('2026-11-10', '5000.00');
        $this->assertSame(Money::add($start, '-5000.00'), $this->balance($this->kdyCash));
        // Double entry: the expense also has its category line; these checks are on the cash line.
        $original = LedgerEntry::where('transaction_id', $e['id'])->where('account_id', $this->kdyCash->id)->sole()->toArray();
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/void", ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('status', 'void');

        return [$e, $original];
    }

    public function test_ac1_ac34_void_posts_contra_entries_and_a_complete_audit_row(): void
    {
        $this->assertSame('100000.00', $this->balance($this->kdyCash));
        [$e, $original] = $this->voidE(); // asserts 95,000.00 after recording E

        $this->assertSame('void', $this->txn($e['id'])->status);
        // Double entry: 2 lines (cash, expense category) + 2 contras; the cash line and its contra are checked here.
        $this->assertSame(4, LedgerEntry::where('transaction_id', $e['id'])->count());
        $contraIds = LedgerEntry::where('transaction_id', $e['id'])->whereNotNull('contra_of_id')->orderBy('id')->pluck('id')->all();
        $entries = LedgerEntry::where('transaction_id', $e['id'])->where('account_id', $this->kdyCash->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame($original, $entries[0]->toArray(), 'original entry unchanged');
        $this->assertSame('5000.00', $entries[0]->credit);
        $contra = $entries[1];
        $this->assertSame('5000.00', $contra->debit);
        $this->assertSame('0.00', $contra->credit);
        $this->assertSame('2026-11-10', $contra->entry_date->format('Y-m-d'));
        $this->assertSame($entries[0]->id, $contra->contra_of_id);
        $this->assertSame($this->kdyCash->id, $contra->account_id);

        $acc = Account::withoutGlobalScopes()->find($this->kdyCash->id);
        $this->assertSame('100000.00', $acc->current_balance);
        $this->assertSame(0, Money::cmp($acc->ledgerBalance(), '100000.00'));

        // AC 34: what was voided is on the audit row itself.
        $row = AuditLog::where('event', 'transaction.voided')->where('auditable_id', $e['id'])->sole();
        $this->assertSame($this->kdy->id, $row->branch_id);
        $this->assertSame(['status' => 'posted'], $row->old_values);
        $new = $row->new_values;
        $this->assertSame('void', $new['status']);
        $this->assertSame('Duplicate', $new['reason']);
        $this->assertTrue($new['self']);
        $this->assertSame($contraIds, $new['contra_entry_ids']);
        $this->assertContains($contra->id, $contraIds);
        $this->assertSame($e['ref_no'], $new['ref_no']);
        $this->assertSame('5000.00', $new['amount']);
        $this->assertSame($this->kdyCash->id, $new['account_id']);
        $this->assertSame('2026-11-10', $new['txn_date']);

        // AC 34 (Excel part): the export row shows these values.
        $res = $this->accKdy()->post('/api/v1/audit-logs/export', ['from' => '2026-11-01', 'to' => '2026-11-30', 'event_group' => 'voids'])->assertOk();
        $ws = IOFactory::createReader('Xlsx')->load($res->getFile()->getPathname())->getActiveSheet();
        $found = false;
        foreach ($ws->toArray() as $cells) {
            if (($cells[8] ?? null) === 'transaction.voided') {
                $found = true;
                $this->assertSame('Expense voided', $cells[3]);
                $this->assertSame($e['ref_no'], $cells[4]);
                $this->assertSame('Self', $cells[6]);
                foreach (['"ref_no":"'.$e['ref_no'].'"', '"amount":"5000.00"', '"account_id":'.$this->kdyCash->id, '"txn_date":"2026-11-10"', '"reason":"Duplicate"', '"self":true', '"contra_entry_ids":['.implode(',', $contraIds).']'] as $part) {
                    $this->assertStringContainsString($part, $cells[10]);
                }
            }
        }
        $this->assertTrue($found);
    }

    public function test_ac2_entries_never_decrease_and_models_refuse_update_and_delete(): void
    {
        $counts = [LedgerEntry::count()];
        $e = $this->expense('2026-11-10', '5000.00');
        $counts[] = LedgerEntry::count();
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $counts[] = LedgerEntry::count();
        $e2 = $this->expense('2026-11-11', '100.00');
        $counts[] = LedgerEntry::count();
        $this->accKdy()->postJson("/api/v1/transactions/{$e2['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-12'])->assertCreated();
        $counts[] = LedgerEntry::count();
        $sorted = $counts;
        sort($sorted);
        $this->assertSame($sorted, $counts);
        // Double entry: expense +2, void +2 (contras), expense +2, reversal +2
        $this->assertSame([2, 2, 2, 2], [$counts[1] - $counts[0], $counts[2] - $counts[1], $counts[3] - $counts[2], $counts[4] - $counts[3]]);

        $entry = LedgerEntry::first();
        $audit = AuditLog::first();
        foreach ([fn () => $entry->update(['debit' => '1.00']), fn () => $entry->delete(),
            fn () => $audit->update(['event' => 'x']), fn () => $audit->delete()] as $i => $call) {
            try {
                $call();
                $this->fail("call {$i} did not throw");
            } catch (ImmutableRecordException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($entry->getOriginal('debit'), LedgerEntry::find($entry->id)->debit);
        $this->assertNotNull(AuditLog::find($audit->id));
    }

    public function test_ac32_database_triggers_refuse_raw_update_and_delete(): void
    {
        $this->voidE();
        foreach (['ledger_entries', 'audit_logs'] as $table) {
            $before = DB::table($table)->orderBy('id')->get()->toArray();
            $column = $table === 'ledger_entries' ? 'debit' : 'event';
            foreach ([fn () => DB::table($table)->update([$column => '9']), fn () => DB::table($table)->where('id', $before[0]->id)->delete(), fn () => DB::table($table)->delete()] as $i => $call) {
                try {
                    $call();
                    $this->fail("{$table} raw write {$i} did not throw");
                } catch (QueryException $e) {
                    $this->assertStringContainsString("{$table} rows are immutable", $e->getMessage());
                }
            }
            $this->assertEquals($before, DB::table($table)->orderBy('id')->get()->toArray(), "{$table} unchanged");
        }
        // Inserts still work (append-only) and transactions with entries cannot be deleted (FK restrict).
        $this->expectException(QueryException::class);
        DB::table('transactions')->whereIn('id', DB::table('ledger_entries')->select('transaction_id'))->delete();
    }

    public function test_ac3_income_void_that_would_overdraw_is_refused(): void
    {
        $i = $this->income('2026-11-10', '3000.00');
        $this->expense('2026-11-11', '102000.00');
        $this->assertSame('1000.00', $this->balance($this->kdyCash));
        $before = $this->snapshot();
        $this->accKdy()->postJson("/api/v1/transactions/{$i['id']}/void", ['reason' => 'Duplicate'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Insufficient balance'));
        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(AuditLog::where('event', 'transaction.voided')->exists());
    }

    public function test_ac4_ac5_void_twice_and_void_of_a_transfer(): void
    {
        [$e] = $this->voidE();
        $count = LedgerEntry::count();
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/void", ['reason' => 'Again'])
            ->assertStatus(422)->assertJsonPath('message', 'Only posted entries can be voided.');
        $this->assertSame($count, LedgerEntry::count());

        $t = $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '100.00', 'txn_date' => '2026-11-12', 'note' => 'Support'])->assertCreated()->json();
        $this->accKdy()->postJson("/api/v1/transactions/{$t['id']}/void", ['reason' => 'Wrong'])
            ->assertStatus(422)->assertJsonPath('message', 'Only income and expense entries can be voided. Use reverse for transfers.');
    }

    public function test_ac6_reports_equal_a_dataset_where_the_voided_entry_was_never_entered(): void
    {
        $run = function () {
            $out = [];
            foreach (['2026-11', '2026-12'] as $m) {
                $q = ['from' => "{$m}-01", 'to' => date('Y-m-t', strtotime("{$m}-01"))];
                foreach (['income-expense', 'branch-comparison', 'transfers'] as $name) {
                    $out["{$name} {$m}"] = $this->sa()->getJson("/api/v1/reports/{$name}?".http_build_query($q))->assertOk()->json();
                }
                $out["cash-book {$m}"] = $this->sa()->getJson('/api/v1/reports/cash-book?'.http_build_query($q + ['branches' => [$this->kdy->id]]))->assertOk()->json();
            }

            return $out;
        };
        $this->income('2026-11-05', '700.00');
        $without = $run();
        [$e] = $this->voidE();
        $with = $run();
        $this->assertSame($without, $with);

        $kdyBook = collect($with['cash-book 2026-11']['accounts'])->firstWhere('account.id', $this->kdyCash->id);
        $this->assertNull(collect($kdyBook['days'])->firstWhere('date', '2026-11-10'));
        $this->assertStringNotContainsString($e['ref_no'], json_encode($with));
    }

    public function test_ac7_statement_hides_voided_lines_unless_asked(): void
    {
        [$e] = $this->voidE();
        $url = "/api/v1/accounts/{$this->kdyCash->id}/statement?from=2026-11-01&to=2026-11-30";

        $st = $this->accKdy()->getJson($url)->assertOk()->json();
        $this->assertSame('100000.00', $st['opening_balance']);
        $this->assertSame('100000.00', $st['closing_balance']);
        $this->assertSame([], $st['entries']);

        $st = $this->accKdy()->getJson($url.'&include_voided=1')->assertOk()->json();
        $this->assertSame('100000.00', $st['opening_balance']);
        $this->assertSame('100000.00', $st['closing_balance']);
        $this->assertCount(2, $st['entries']);
        [$out, $in] = $st['entries'];
        $this->assertSame(['5000.00', '0.00', 'void', false, '95000.00'], [$out['out'], $out['in'], $out['status'], $out['contra'], $out['balance']]);
        $this->assertSame(['0.00', '5000.00', 'void', true, '100000.00'], [$in['out'], $in['in'], $in['status'], $in['contra'], $in['balance']]);
        $this->assertSame("Void of {$e['ref_no']}: Duplicate", $in['description']);
    }

    public function test_ac8_ac35_legacy_voids_are_identified_not_rebuilt(): void
    {
        // Two voids made the old way (entries deleted, balance recalculated): status void and no ledger entries.
        $legacy = [];
        foreach (['LEG-1' => true, 'LEG-2' => false] as $ref => $audited) {
            $id = DB::table('transactions')->insertGetId(['ref_no' => $ref, 'branch_id' => $this->kdy->id, 'type' => 'expense', 'txn_date' => '2026-10-20',
                'amount' => '250.00', 'account_id' => $this->kdyCash->id, 'status' => 'void', 'created_by' => $this->acc->id,
                'description' => "x\n[Void] Duplicate", 'created_at' => now(), 'updated_at' => now()]);
            if ($audited) {
                AuditLog::create(['user_id' => $this->acc->id, 'branch_id' => $this->kdy->id, 'event' => 'transaction.voided', 'auditable_type' => Transaction::class,
                    'auditable_id' => $id, 'old_values' => ['status' => 'posted'], 'new_values' => ['status' => 'void', 'reason' => 'Duplicate']]);
            }
            $legacy[] = $id;
        }
        [$e] = $this->voidE(); // a new-style void is not legacy
        $before = $this->snapshot();

        $row = LegacyVoids::recordIdentified(); // what the migration runs
        $this->assertSame($before, $this->snapshot(), 'no entries created, balances unchanged');
        $this->assertSame('ledger.legacy_voids_identified', $row->event);
        $this->assertNull($row->branch_id);
        $this->assertSame(['count' => 2, 'transaction_ids' => $legacy], $row->new_values);
        $this->assertSame(1, AuditLog::where('event', 'ledger.legacy_voids_identified')->count());

        $this->artisan('ledger:verify')
            ->expectsOutputToContain('Legacy voids (voided before contra entries; ledger lines were not kept): 2')
            ->expectsOutputToContain('1 legacy void(s) have no transaction.voided audit row.')
            ->expectsOutputToContain('All account balances match the ledger.')
            ->assertExitCode(0);
        // The table lists ref, date, amount, account, voided-by, voided-at and the flag.
        Artisan::call('ledger:verify');
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/LEG-1 .*2026-10-20 .*250\.00 .*KDY Cash .*'.preg_quote($this->acc->name, '/').' .*\d{4}-\d{2}-\d{2} \d{2}:\d{2} .*ok/', $out);
        $this->assertMatchesRegularExpression('/LEG-2 .*2026-10-20 .*250\.00 .*KDY Cash .*NO transaction.voided AUDIT ROW/', $out);
        $this->assertStringNotContainsString($e['ref_no'], $out);

        // A cache that drifted from the ledger is reported with a non-zero exit code.
        DB::table('accounts')->where('id', $this->galCash->id)->update(['current_balance' => '1.00']);
        $this->artisan('ledger:verify')->expectsOutputToContain('GAL Cash: current_balance 1.00 != ledger 50000.00')->assertExitCode(1);
    }

    public function test_ac9_edit_category_must_be_an_active_category_of_the_same_type_and_branch(): void
    {
        $i = $this->income('2026-11-10', '3000.00');
        $galOnly = Category::create(['type' => 'income', 'name' => 'GAL sales', 'branch_id' => $this->gal->id]);
        $kdyOnly = Category::create(['type' => 'income', 'name' => 'KDY sales', 'branch_id' => $this->kdy->id]);
        $original = $this->txn($i['id'])->category_id;

        foreach ([$this->category('expense')->id, $this->inactiveCategory('income')->id, $galOnly->id] as $bad) {
            $this->accKdy()->putJson("/api/v1/transactions/{$i['id']}", ['category_id' => $bad])->assertStatus(422)->assertJsonValidationErrors('category_id');
            $this->assertSame($original, $this->txn($i['id'])->category_id);
        }
        $this->accKdy()->putJson("/api/v1/transactions/{$i['id']}", ['category_id' => $kdyOnly->id])->assertOk();
        $this->assertSame($kdyOnly->id, $this->txn($i['id'])->category_id);
    }

    public function test_legacy_void_note_on_transaction_list(): void
    {
        $id = DB::table('transactions')->insertGetId(['ref_no' => 'LEG-9', 'branch_id' => $this->kdy->id, 'type' => 'expense', 'txn_date' => '2026-10-20',
            'amount' => '250.00', 'account_id' => $this->kdyCash->id, 'status' => 'void', 'created_by' => $this->acc->id, 'created_at' => now(), 'updated_at' => now()]);
        LegacyVoids::recordIdentified();
        [$e] = $this->voidE();
        $rows = collect($this->accKdy()->getJson('/api/v1/transactions?status=void')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('Voided before 2026-11-18; ledger lines were not kept.', $rows[$id]['legacy_void_note']);
        $this->assertArrayNotHasKey('legacy_void_note', $rows[$e['id']]);
        $this->assertSame(4, $rows[$e['id']]['entries_count']); // double entry: 2 lines + 2 contras
    }
}
