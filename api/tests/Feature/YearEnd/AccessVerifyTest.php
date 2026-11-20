<?php

namespace Tests\Feature\YearEnd;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\YearEndClose;
use Illuminate\Support\Facades\DB;

/** Settings spec ACs 35, 40, 43 and review ACs 48, 52, 54. */
class AccessVerifyTest extends YearEndTestCase
{
    public function test_ac35_only_super_admin_reaches_fiscal_years_and_acc_reads_the_closing_entry(): void
    {
        $this->closeY();
        $routes = [['get', '/api/v1/fiscal-years'], ['get', '/api/v1/fiscal-years/2026/preview'],
            ['post', '/api/v1/fiscal-years/2026/close', ['confirm' => '2026-27']], ['post', '/api/v1/fiscal-years/2026/reopen', ['reason' => 'Late supplier bill for March']]];
        $acc = null;
        foreach (['Branch Admin', 'Accountant', 'Cashier'] as $role) {
            $u = $this->userIn($this->kdy, $role);
            $acc = $role === 'Accountant' ? $u : $acc;
            foreach ($routes as $r) {
                $this->as($u, $this->kdy)->json(strtoupper($r[0]), $r[1], $r[2] ?? [])->assertForbidden();
            }
        }
        $this->assertSame(1, YearEndClose::active()->count());

        $txn = $this->closeTxns()->firstWhere('branch_id', $this->kdy->id);
        $show = $this->as($acc, $this->kdy)->getJson("/api/v1/transactions/{$txn->id}")->assertOk()
            ->assertJsonPath('type', 'year_end_close')->assertJsonPath('balanced', true)->json();
        $main = collect($show['entries'])->where('branch_id', $this->main->id);
        $this->assertSame(['Branch current account – KDY', 'Retained earnings'], $main->pluck('account.name')->values()->all());
        $this->as($acc, $this->kdy)->postJson("/api/v1/transactions/{$txn->id}/void", ['reason' => 'Try to void it'])->assertStatus(422);
    }

    public function test_ac40_ac52_ledger_verify_v7_and_v8(): void
    {
        $this->closeY();
        $this->artisan('ledger:verify')->expectsOutputToContain('V7 year-end close')->expectsOutputToContain('V8 closing lines')->assertExitCode(0);

        // AC 40: a line inserted in the closed year after closed_at, locks bypassed.
        $this->travel(5)->minutes();
        $t = $this->rawTxn($this->kdy, '2027-02-10');
        $retail = Account::withoutGlobalScopes()->where('branch_id', $this->kdy->id)->where('name', 'Sales > Retail')->sole();
        $this->rawLine($t, $this->acc($this->kdy, 'Cash'), '2027-02-10', '100.00', '0.00');
        $line = $this->rawLine($t, $retail, '2027-02-10', '0.00', '100.00');
        $this->artisan('ledger:verify')->expectsOutputToContain("line #{$line}")->expectsOutputToContain('created after the close')->assertExitCode(1);
    }

    public function test_ac52_v7_names_a_branch_whose_summary_differs(): void
    {
        $this->closeY();
        $close = YearEndClose::sole();
        $summary = $close->summary;
        $summary['by_branch']['KDY']['net'] = '24200.00';
        DB::table('year_end_closes')->where('id', $close->id)->update(['summary' => json_encode($summary)]);
        $this->artisan('ledger:verify')->expectsOutputToContain('FY start 2026: result moved differs from summary | KDY')->assertExitCode(1);
    }

    public function test_ac48_backfill_never_reaches_a_closed_year(): void
    {
        $this->closeY();
        $kdy = $this->kdy;
        $cash = $this->acc($kdy, 'Cash');
        $cat = Category::where('name', 'Service income')->first();
        $id = DB::table('transactions')->insertGetId(['ref_no' => 'BAD-YE', 'branch_id' => $kdy->id, 'type' => 'income', 'txn_date' => '2027-02-10', 'amount' => '100.00',
            'account_id' => $cash->id, 'category_id' => $cat->id, 'status' => 'posted', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_entries')->insert(['transaction_id' => $id, 'branch_id' => $kdy->id, 'entry_date' => '2027-02-10', 'account_id' => $cash->id,
            'debit' => '100.00', 'credit' => '0', 'origin' => 'posting', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounts')->where('id', $cash->id)->update(['current_balance' => DB::raw('current_balance + 100')]);
        $before = LedgerEntry::count();
        $this->artisan('ledger:backfill-double-entry', ['--force' => true])
            ->expectsOutputToContain('Back-fill of BAD-YE refused: FY 2026-27 is closed.')->assertExitCode(1);
        $this->assertSame($before, LedgerEntry::count());
    }

    public function test_ac43_audit_groups_and_branch_visibility(): void
    {
        $this->sa()->putJson('/api/v1/settings', ['company_name' => 'Gold Traders'])->assertOk();
        $this->closeY();
        $this->reopen()->assertOk();
        $events = fn ($group, $u = null, $b = null) => collect(($u ? $this->as($u, $b) : $this->sa())
            ->getJson('/api/v1/audit-logs?from=2027-04-01&to=2027-04-30&event_group='.$group)->assertOk()->json('data'))->pluck('event')->unique()->sort()->values()->all();
        $this->assertSame(['settings.updated'], $events('settings'));
        $this->assertSame(['transaction.year_end_close', 'transaction.year_end_reopened', 'year.closed', 'year.reopened'], $events('year_end'));

        $acc = $this->userIn($this->kdy, 'Accountant');
        $rows = collect($this->as($acc, $this->kdy)->getJson('/api/v1/audit-logs?from=2027-04-01&to=2027-04-30&event_group=year_end')->assertOk()->json('data'));
        $this->assertSame(['transaction.year_end_close', 'transaction.year_end_reopened'], $rows->pluck('event')->sort()->values()->all());
        $kdyTxn = $this->closeTxns()->firstWhere('branch_id', $this->kdy->id)->id;
        $this->assertSame([$kdyTxn], $rows->pluck('auditable_id')->unique()->values()->all());
        $reopened = AuditLog::where('event', 'transaction.year_end_reopened')->where('auditable_id', $kdyTxn)->sole();
        $this->assertSame(['status' => 'posted'], $reopened->old_values);
    }

    public function test_ac54_the_transactions_list_shows_the_closing_entries(): void
    {
        $this->closeY();
        $list = collect($this->sa()->getJson('/api/v1/transactions?type=year_end_close')->assertOk()->json('data'));
        $this->assertCount(3, $list);
        $this->assertSame(['year_end_close'], $list->pluck('type')->unique()->values()->all());
        // The dashboard's recent list leaves them out.
        $recent = collect($this->sa()->getJson('/api/v1/dashboard?from=2027-03-01&to=2027-03-31')->assertOk()->json('recent'));
        $this->assertNotContains('year_end_close', $recent->pluck('type')->all());
        $this->assertSame(3, Transaction::withoutGlobalScopes()->where('type', 'year_end_close')->count());
    }
}
