<?php

namespace Tests\Feature\YearEnd;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\YearEndClose;
use App\Notifications\YearEndNotification;
use App\Services\LedgerService;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/** Settings spec ACs 23-25, 28-30, 36-39 and review ACs 44, 46, 47, 49 (close part), 51. */
class CloseTest extends YearEndTestCase
{
    /** Section 7: the 15 closing lines. */
    public const LINES = [
        'KDY Dr Sales > Retail 50000.00', 'KDY Dr Service income 12500.00', 'KDY Cr Rent 30000.00', 'KDY Cr Utilities > Electricity 8200.00',
        'KDY Cr Head office current account 24300.00', 'MAIN Dr Branch current account – KDY 24300.00', 'MAIN Cr Retained earnings 24300.00',
        'GAL Dr Sales > Retail 25000.00', 'GAL Cr Rent 30000.00', 'GAL Cr Utilities > Water 1800.00', 'GAL Dr Head office current account 6800.00',
        'MAIN Dr Retained earnings 6800.00', 'MAIN Cr Branch current account – GAL 6800.00',
        'MAIN Cr Rent 10000.00', 'MAIN Dr Retained earnings 10000.00',
    ];

    public function test_ac23_a_branch_not_locked_blocks_the_close(): void
    {
        $this->lock('2027-03', $this->kdy);
        $this->lock('2027-03', $this->main);
        $row = $this->years()[2026];
        $this->assertSame('open', $row['status']);
        $message = 'Lock March 2027 for: GAL (or lock all branches) before closing FY 2026-27.';
        $this->assertContains($message, array_column($row['checks'], 'message'));
        $before = LedgerEntry::count();
        $this->close()->assertStatus(422)->assertJsonPath('message', $message);
        $this->assertSame($before, LedgerEntry::count());
        $this->assertSame(0, YearEndClose::count());
    }

    public function test_ac24_ac46_ready_and_the_preview_of_section_7(): void
    {
        $this->lock('2027-03');
        $row = $this->years()[2026];
        $this->assertSame(['ready', true, '7500.00', 'FY 2026-27', '2026-04-01', '2027-03-31'],
            [$row['status'], $row['can_close'], $row['result'], $row['label'], $row['start'], $row['end']]);
        $p = $this->sa()->getJson('/api/v1/fiscal-years/2026/preview')->assertOk()->json();
        $by = collect($p['branches'])->keyBy('branch.code');
        $this->assertSame(['MAIN', 'GAL', 'KDY'], $by->keys()->all());
        $this->assertSame(['24300.00', '-6800.00', '-10000.00'], [$by['KDY']['net'], $by['GAL']['net'], $by['MAIN']['net']]);
        $this->assertSame(['62500.00', '38200.00'], [$by['KDY']['income'], $by['KDY']['expense']]);
        $this->assertSame(['7500.00', '7500.00', '7500.00'], [$p['total_net'], $p['retained_earnings_movement'], $p['total_ie_net']]);
        $this->assertSame([7, 6, 2], [count($by['KDY']['lines']), count($by['GAL']['lines']), count($by['MAIN']['lines'])]);
        $this->assertSame(['MAIN', '32000', 'Retained earnings', '0.00', '24300.00'], array_values(collect($by['KDY']['lines'])->last()));
        $this->assertSame([], $p['checks']);

        // Finding 2: the I&E column equals the I&E report for the year, per branch, difference 0.00.
        foreach (['KDY' => '24300.00', 'GAL' => '-6800.00', 'MAIN' => '-10000.00'] as $code => $net) {
            $ie = $this->sa()->getJson('/api/v1/reports/income-expense?'.http_build_query(['from' => '2026-04-01', 'to' => '2027-03-31', 'branches' => [Branch::where('code', $code)->value('id')]]))->json('net');
            $this->assertSame([$net, $net, '0.00'], [$ie, $by[$code]['ie_net'], $by[$code]['difference']]);
        }
    }

    public function test_ac25_ac49_ac51_the_close_posts_section_7(): void
    {
        $money = Account::withoutGlobalScopes()->money()->orderBy('id')->pluck('current_balance', 'id')->all();
        $res = $this->closeY();
        $txns = $this->closeTxns();
        $this->assertCount(3, $txns);
        $this->assertSame(['2027-03-31'], $txns->map(fn ($t) => $t->txn_date->format('Y-m-d'))->unique()->values()->all());
        $this->assertEqualsCanonicalizing(self::LINES, $this->closingLines());
        $lines = LedgerEntry::with('account')->whereIn('transaction_id', $txns->pluck('id'))->get();
        $this->assertSame(['close'], $lines->pluck('origin')->unique()->values()->all());
        $this->assertFalse($lines->contains(fn ($l) => $l->account->isMoney()));
        $this->assertSame($money, Account::withoutGlobalScopes()->money()->orderBy('id')->pluck('current_balance', 'id')->all());
        foreach ($txns as $t) {
            $net = [];
            foreach ($t->entries as $e) {
                $net[$e->branch_id] = ($net[$e->branch_id] ?? 0) + (int) round($e->debit * 100) - (int) round($e->credit * 100);
            }
            $this->assertSame([], array_filter($net));
        }
        $this->assertSame(-750000, $this->ledger($this->retained()));
        $this->assertSame('-7500.00', $this->retained()->fresh()->current_balance);
        $kdy = $txns->firstWhere('branch_id', $this->kdy->id);
        $this->assertSame(['62500.00', 'Year-end close FY 2026-27'], [$kdy->amount, $kdy->description]);
        $this->assertMatchesRegularExpression('/^KDY-YEC-2027-\d{6}$/', $kdy->ref_no);

        $close = YearEndClose::sole();
        $audit = AuditLog::where('event', 'year.closed')->sole();
        $this->assertNull($audit->branch_id);
        $this->assertSame('7500.00', $audit->new_values['total_net']);
        $this->assertSame(['income' => '62500.00', 'expense' => '38200.00', 'net' => '24300.00', 'transaction_id' => $kdy->id, 'ref_no' => $kdy->ref_no, 'lines' => 7],
            $audit->new_values['by_branch']['KDY']);
        $this->assertFalse($audit->new_values['global_lock_created'], 'the March lock for all branches already existed');
        foreach ($txns as $t) {
            $this->assertSame($t->branch_id, AuditLog::where('event', 'transaction.year_end_close')->where('auditable_id', $t->id)->sole()->branch_id);
        }
        $res->assertJsonPath('close.total_net', '7500.00')->assertJsonCount(3, 'transactions');
        $this->assertSame($this->globalLock('2027-03')->id, $close->period_lock_id);

        // Finding 7: closed_at is not earlier than any closing line.
        $this->assertTrue($lines->every(fn ($l) => $l->created_at <= $close->closed_at));
        // Finding 5: every active Super Admin, the closer included.
        Notification::assertSentTo($this->admin, YearEndNotification::class, fn ($n) => $n->event === 'closed' && $n->year === 'FY 2026-27' && $n->totalResult === '7,500.00');
        $this->assertSame('closed', $this->years()[2026]['status']);
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_ac28_a_second_close_is_refused(): void
    {
        $this->closeY();
        $this->close()->assertStatus(422)->assertJsonPath('message', 'FY 2026-27 is already closed.');
        $this->assertSame(1, YearEndClose::active()->count());
        $this->assertSame(15, LedgerEntry::where('origin', 'close')->count());
    }

    public function test_ac29_confirmation_year_end_and_earlier_years(): void
    {
        $this->lock('2027-03');
        $this->close(2026, '2026')->assertStatus(422)->assertJsonPath('message', 'Type 2026-27 to confirm.');
        Carbon::setTestNow('2027-05-01 10:00:00');
        $this->close(2027)->assertStatus(422)->assertJsonPath('message', 'FY 2027-28 has not ended yet.');
    }

    public function test_ac29_finding_11_an_earlier_year_with_income_or_expense_blocks(): void
    {
        $this->expense($this->kdy, $this->acc($this->kdy, 'Cash'), 'Rent', '2026-03-15', '100.00');
        $this->lock('2027-03');
        $this->close()->assertStatus(422)->assertJsonPath('message', 'Close FY 2025-26 first.');
    }

    public function test_ac29_finding_11_an_earlier_year_with_only_capital_does_not_block(): void
    {
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainBank->id, 'amount' => '500.00', 'txn_date' => '2026-03-15'])->assertCreated();
        $this->lock('2027-03');
        $years = $this->years();
        $this->assertSame('no_result', $years[2025]['status']);
        $this->assertSame('ready', $years[2026]['status']);
        $this->close()->assertCreated();
    }

    public function test_ac30_an_unbalanced_entry_blocks(): void
    {
        $this->lock('2027-03');
        $t = $this->rawTxn($this->kdy, '2027-02-10');
        $this->rawLine($t, $this->acc($this->kdy, 'Cash'), '2027-02-10', '100.00', '0.00');
        $before = LedgerEntry::count();
        $this->close()->assertStatus(422)->assertJsonPath('message', '1 entries up to March 2027 are not balanced. Run ledger:verify.');
        $this->assertSame($before, LedgerEntry::count());
    }

    public function test_ac46_ledger_result_must_equal_the_ie_report(): void
    {
        $this->lock('2027-03');
        $t = $this->rawTxn($this->kdy, '2027-02-10', 'internal_transfer');
        $retail = Account::withoutGlobalScopes()->where('branch_id', $this->kdy->id)->where('name', 'Sales > Retail')->sole();
        $this->rawLine($t, $retail, '2027-02-10', '0.00', '100.00');
        $this->rawLine($t, $this->ho($this->kdy), '2027-02-10', '100.00', '0.00');
        $before = LedgerEntry::count();
        $this->close()->assertStatus(422)->assertJsonPath('message', 'The ledger result for KDY differs from the Income & Expense report by 100.00. Run ledger:verify.');
        $this->assertSame($before, LedgerEntry::count());
    }

    public function test_ac36_a_year_without_income_or_expense_still_closes(): void
    {
        $this->closeY();
        Carbon::setTestNow('2028-04-10 10:00:00');
        $this->lock('2028-03');
        $this->assertSame('no_result', $this->years()[2027]['status']);
        $this->close(2027)->assertCreated()->assertJsonCount(0, 'transactions')->assertJsonPath('close.total_net', '0.00');
        $this->assertSame('0.00', AuditLog::where('event', 'year.closed')->latest('id')->first()->new_values['total_net']);
    }

    public function test_ac37_branch_locks_only_the_close_adds_its_global_lock(): void
    {
        foreach ([$this->main, $this->kdy, $this->gal] as $b) {
            $this->lock('2027-03', $b);
        }
        $this->assertNull($this->globalLock('2027-03'));
        $this->close()->assertCreated();
        $lock = $this->globalLock('2027-03');
        $this->assertSame($this->admin->id, $lock->locked_by);
        $this->assertSame($lock->id, YearEndClose::sole()->period_lock_id);
        $this->assertSame('year_end_close', AuditLog::where('event', 'period.locked')->where('auditable_id', $lock->id)->sole()->new_values['source']);
        $this->assertTrue(AuditLog::where('event', 'year.closed')->sole()->new_values['global_lock_created']);

        $mtr = Branch::find($this->sa()->postJson('/api/v1/branches', ['code' => 'MTR', 'name' => 'Matara'])->assertCreated()->json('id'));
        $this->sa($mtr)->postJson('/api/v1/incomes', ['account_id' => $this->acc($mtr, 'Cash')->id, 'category_id' => Category::where('name', 'Service income')->value('id'),
            'txn_date' => '2027-03-20', 'amount' => '10.00'])->assertStatus(422)->assertJsonPath('message', 'FY 2026-27 is closed.');
    }

    public function test_ac38_an_inactive_branch_is_closed_like_any_other(): void
    {
        $this->sa()->putJson("/api/v1/branches/{$this->gal->id}", ['status' => 'inactive'])->assertOk();
        $this->closeY();
        $this->assertSame(6, $this->closeTxns()->firstWhere('branch_id', $this->gal->id)->entries()->count());
    }

    public function test_ac39_post_close_refuses_money_accounts_other_origins_and_takes_no_bypass(): void
    {
        $ledger = app(LedgerService::class);
        $txn = Transaction::withoutGlobalScopes()->create(['branch_id' => $this->main->id, 'type' => Transaction::YEAR_END_CLOSE, 'txn_date' => '2027-03-31',
            'amount' => '1.00', 'status' => 'posted', 'created_by' => $this->admin->id]);
        $fy = app(Settings::class)->fiscalYearStarting(2026);
        $line = new \ReflectionMethod($ledger, 'line');
        $post = new \ReflectionMethod($ledger, 'postClose');
        $before = LedgerEntry::count();
        $cases = [
            [$line->invoke($ledger, $this->mainBank, 100, 0, '2027-03-31', null, 'close'), $line->invoke($ledger, $this->retained(), 0, 100, '2027-03-31', null, 'close')],
            [$line->invoke($ledger, $this->bca($this->kdy), 100, 0, '2027-03-31', null, 'posting'), $line->invoke($ledger, $this->retained(), 0, 100, '2027-03-31', null, 'posting')],
            [$line->invoke($ledger, $this->bca($this->kdy), 100, 0, '2027-03-30', null, 'close'), $line->invoke($ledger, $this->retained(), 0, 100, '2027-03-30', null, 'close')],
        ];
        foreach ($cases as $lines) {
            try {
                $post->invoke($ledger, $txn, $lines, $fy);
                $this->fail('postClose accepted a forbidden line');
            } catch (LedgerException $e) {
                $this->assertStringContainsString('refused', $e->getMessage());
            }
        }
        $this->assertSame($before, LedgerEntry::count());
        $this->assertTrue($post->isPrivate());
        foreach ((new \ReflectionClass(LedgerService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            foreach ($m->getParameters() as $p) {
                $this->assertDoesNotMatchRegularExpression('/bypass|skip|force|unlock/i', $p->getName(), "{$m->getName()}(\${$p->getName()})");
            }
        }
    }

    public function test_ac44_finding_1a_a_later_global_lock_does_not_replace_the_close_lock(): void
    {
        $this->lock('2027-04');
        $this->close()->assertCreated();
        $march = $this->globalLock('2027-03');
        $this->assertNotNull($march);
        $this->assertSame($march->id, YearEndClose::sole()->period_lock_id);
        $april = $this->globalLock('2027-04');
        $this->sa()->postJson("/api/v1/period-locks/{$april->id}/unlock", ['reason' => 'Correct an April entry'])->assertOk()
            ->assertJsonPath('reopened.0.from', '2027-04')->assertJsonPath('reopened.0.to', '2027-04');
        $this->assertTrue(PeriodLock::isLocked($this->kdy->id, '2027-03-31'));
        $this->assertFalse(PeriodLock::isLocked($this->kdy->id, '2027-04-05'));
    }

    public function test_ac47_finding_3_retained_earnings_is_written_only_by_the_close(): void
    {
        $re = $this->retained();
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $re->id, 'amount' => '10.00', 'txn_date' => '2027-04-01'])->assertStatus(422);
        $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->mainBank->id, 'to_account_id' => $re->id, 'amount' => '10.00',
            'txn_date' => '2027-04-01', 'note' => 'x'])->assertStatus(422);
        foreach (['accounts', 'accounts/destinations'] as $path) {
            $this->assertNotContains($re->id, collect($this->sa($this->main)->getJson("/api/v1/{$path}")->assertOk()->json())->pluck('id')->all());
        }
        // The service itself refuses a non-close line on the account (the only writer of ledger_entries).
        $ledger = app(LedgerService::class);
        $entry = new \ReflectionMethod($ledger, 'entry');
        $line = new \ReflectionMethod($ledger, 'line');
        try {
            $entry->invoke($ledger, Transaction::withoutGlobalScopes()->first(), $line->invoke($ledger, $re, 100, 0, '2027-04-01', null, 'posting'));
            $this->fail('entry() wrote a posting line on Retained earnings');
        } catch (LedgerException $e) {
            $this->assertSame('Retained earnings can only be posted by the year-end close.', $e->getMessage());
        }
        // A line written around the service is named by ledger:verify V8.
        $t = $this->rawTxn($this->main, '2027-04-02', 'capital');
        $this->rawLine($t, $re, '2027-04-02', '0.00', '5.00');
        $this->rawLine($t, $this->mainBank, '2027-04-02', '5.00', '0.00');
        $this->artisan('ledger:verify')->expectsOutputToContain('Retained earnings line not from the close')->assertExitCode(1);
    }
}
