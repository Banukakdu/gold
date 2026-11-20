<?php

namespace Tests\Feature\DoubleEntry;

use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Support\Money;

/** Double-entry spec ACs 13-15 (reclassification, R3 / finding 4) and the void of a reclassified entry. */
class ReclassifyTest extends DoubleEntryTestCase
{
    private function rentExpense(): array
    {
        return $this->accKdy()->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $this->cat('expense', 'Rent')->id,
            'txn_date' => '2026-11-05', 'amount' => '30000.00', 'payee_payer' => 'Landlord'])->assertCreated()->json();
    }

    private function change(array $e, string $name)
    {
        return $this->accKdy()->putJson("/api/v1/transactions/{$e['id']}", ['category_id' => $this->cat('expense', $name)->id]);
    }

    public function test_ac13_category_change_posts_two_reclass_lines_on_the_original_date(): void
    {
        $e = $this->rentExpense();
        $this->change($e, 'Electricity')->assertOk()->assertJsonPath('category_id', $this->cat('expense', 'Electricity')->id);

        $reclass = $this->lines($e['id'])->where('origin', 'reclass')->values();
        $this->assertSame(['KDY Dr Utilities > Electricity 30000.00', 'KDY Cr Rent 30000.00'],
            array_values(array_slice($this->sides($e['id']), 2)));
        $this->assertCount(2, $reclass);
        $this->assertSame(['2026-11-05'], $reclass->map(fn ($l) => $l->entry_date->format('Y-m-d'))->unique()->values()->all());

        $audit = AuditLog::where('event', 'transaction.reclassified')->where('auditable_id', $e['id'])->sole();
        $this->assertSame($this->cat('expense', 'Rent')->id, $audit->new_values['old_category_id']);
        $this->assertSame($this->cat('expense', 'Electricity')->id, $audit->new_values['new_category_id']);
        $this->assertSame('30000.00', $audit->new_values['amount']);
        $this->assertSame($reclass->pluck('id')->all(), $audit->new_values['line_ids']);
        $this->assertSame(0, AuditLog::where('event', 'transaction.updated')->where('auditable_id', $e['id'])->count());

        $ie = $this->sa($this->kdy)->getJson('/api/v1/reports/income-expense?from=2026-11-01&to=2026-11-30')->assertOk()->json('expense.categories');
        $util = collect($ie)->firstWhere('name', 'Utilities');
        $this->assertSame('30000.00', collect($util['subs'])->firstWhere('name', 'Electricity')['amount']);
        $this->assertNull(collect($ie)->firstWhere('name', 'Rent'));
    }

    public function test_ac14_second_change_moves_from_the_current_category_and_void_nets_every_account(): void
    {
        $e = $this->rentExpense();
        $this->change($e, 'Electricity')->assertOk();
        $this->change($e, 'Water')->assertOk();
        $this->assertSame(['KDY Dr Utilities > Water 30000.00', 'KDY Cr Utilities > Electricity 30000.00'],
            array_values(array_slice($this->sides($e['id']), 4)));

        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $lines = $this->lines($e['id']);
        $this->assertSame(6, $lines->whereNotNull('contra_of_id')->count());
        $this->assertCount(12, $lines);
        $this->assertSame(4, $lines->pluck('account_id')->unique()->count(), 'Rent, Electricity, Water and Cash');
        foreach ($lines->groupBy('account_id') as $per) {
            $this->assertSame(0, $per->sum(fn ($l) => Money::toCents($l->debit) - Money::toCents($l->credit)));
        }
        $this->assertBalancedPerBranch($e['id']);
    }

    public function test_ac15_no_lines_for_other_edits_and_refused_on_reversed_or_void_entries(): void
    {
        $e = $this->rentExpense();
        $count = LedgerEntry::count();
        $this->accKdy()->putJson("/api/v1/transactions/{$e['id']}", ['description' => 'March rent'])->assertOk()->assertJsonPath('description', 'March rent');
        $this->change($e, 'Rent')->assertOk();
        $this->assertSame($count, LedgerEntry::count());
        $this->assertSame(0, AuditLog::where('event', 'transaction.reclassified')->count());

        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/reverse", ['reason' => 'Wrong'])->assertCreated();
        $this->change($e, 'Water')->assertStatus(422)->assertJsonPath('message', 'This entry cannot be edited.');
        $v = $this->rentExpense();
        $this->accKdy()->postJson("/api/v1/transactions/{$v['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->change($v, 'Water')->assertStatus(422)->assertJsonPath('message', 'This entry cannot be edited.');
        $this->assertSame(0, AuditLog::where('event', 'transaction.reclassified')->count());
    }

    public function test_reversal_of_a_reclassified_entry_mirrors_the_reclass_lines(): void
    {
        $e = $this->rentExpense();
        $this->change($e, 'Electricity')->assertOk();
        $r = $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/reverse", ['reason' => 'Wrong'])->assertCreated()->json();
        $this->assertCount(4, $this->lines($r['id']));
        $this->assertSame('0.00', $this->bal($this->catAcc($this->cat('expense', 'Electricity'), $this->kdy)));
        $this->assertSame('0.00', $this->bal($this->catAcc($this->cat('expense', 'Rent'), $this->kdy)));
        $this->artisan('ledger:verify')->assertExitCode(0);
    }
}
