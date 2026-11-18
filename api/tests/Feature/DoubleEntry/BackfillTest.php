<?php

namespace Tests\Feature\DoubleEntry;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DoubleEntryBackfill;
use App\Services\LedgerService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReportExampleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\LegacyLedgerService;
use Tests\TestCase;

/**
 * Double-entry spec ACs 27, 29-40, 49, 54 and E-5..E-8 (R6 back-fill). The E-dataset is posted with the pre-feature
 * LedgerService on the pre-feature schema (both double-entry migrations rolled back), then `php artisan migrate` runs.
 *
 * No RefreshDatabase: these tests change the schema, and MySQL commits DDL. Each test starts from migrate:fresh and,
 * on MySQL, leaves an empty migrated database for the transaction-based tests that follow.
 */
class BackfillTest extends TestCase
{
    use WorkedExample;

    private object $schema;

    private object $backfill;

    private User $admin;

    private Branch $main;

    private Account $mainAcc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->schema = require database_path('migrations/2026_11_22_000000_double_entry_schema.php');
        $this->backfill = require database_path('migrations/2026_11_22_000001_double_entry_backfill.php');
        $this->toPreFeature();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    /** A pre-feature database: the base seed (MAIN, admin, categories), then both double-entry migrations rolled back. */
    private function toPreFeature(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatabaseSeeder::class);
        $this->artisan('migrate:rollback', ['--step' => 2, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'origin'));
        $this->admin = User::where('is_super_admin', true)->firstOrFail();
        $this->main = Branch::main();
        $this->mainAcc = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();
    }

    private function asAdmin(string|int $branch = 'all')
    {
        return $this->actingAs($this->admin)->withHeader('X-Branch-Id', (string) $branch);
    }

    /** Post the E-dataset the old way: single-sided income/expense/capital, two-line transfers. */
    private function legacyExample(?callable $more = null): void
    {
        Model::withoutEvents(function () use ($more) {
            (new ReportExampleSeeder)->post(new LegacyLedgerService);
            if ($more) {
                Auth::setUser($this->admin);
                $more(new LegacyLedgerService);
                Auth::forgetUser();
            }
        });
        if (! $more) {
            $this->assertSame(29, LedgerEntry::count(), 'E-5: 29 lines before the back-fill');
        }
    }

    /** `php artisan migrate`: the schema migration, then chart and back-fill. */
    private function migrate(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('ledger_entries', 'origin'));
    }

    private function t(int|string $key): Transaction
    {
        return Transaction::withoutGlobalScopes()->findOrFail(ReportExampleSeeder::$txn[$key]->id);
    }

    private function moneyBalances(): array
    {
        return DB::table('accounts')->whereIn('type', Account::MONEY_TYPES)->orderBy('id')->pluck('current_balance', 'id')->map(fn ($v) => (string) $v)->all();
    }

    private function lineRows(): array
    {
        return DB::table('ledger_entries')->orderBy('id')->get(['id', 'transaction_id', 'contra_of_id', 'account_id', 'branch_id', 'entry_date', 'debit', 'credit', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Responses of the four reports, the dashboard and the branch list (reports spec E-1..E-4 periods). */
    private function reportResponses(): array
    {
        $out = [];
        $kdy = Branch::where('code', 'KDY')->first();
        $gal = Branch::where('code', 'GAL')->first();
        $periods = [['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30'], ['2026-09-01', '2026-11-30'], ['2026-10-14', '2026-10-16']];
        foreach ($periods as [$from, $to]) {
            $q = ['from' => $from, 'to' => $to];
            foreach (['income-expense', 'branch-comparison', 'transfers'] as $name) {
                $out["{$name} {$from}"] = $this->asAdmin()->getJson("/api/v1/reports/{$name}?".http_build_query($q))->assertOk()->json();
                $out["{$name} {$from} KDY"] = $this->asAdmin($kdy->id)->getJson("/api/v1/reports/{$name}?".http_build_query($q))->assertOk()->json();
            }
            if ($from !== '2026-09-01' || $to === '2026-09-30') {
                foreach ([$kdy, $gal, $this->main] as $b) {
                    $out["cash-book {$from} {$b->code}"] = $this->asAdmin()->getJson('/api/v1/reports/cash-book?'.http_build_query($q + ['branches' => [$b->id]]))->assertOk()->json();
                }
            }
            $out["dashboard {$from}"] = $this->asAdmin()->getJson('/api/v1/dashboard?'.http_build_query($q))->assertOk()->json();
            $out["dashboard {$from} KDY"] = $this->asAdmin($kdy->id)->getJson('/api/v1/dashboard?'.http_build_query($q))->assertOk()->json();
        }
        $out['branches'] = $this->asAdmin()->getJson('/api/v1/branches')->assertOk()->json();
        $out['accounts'] = $this->asAdmin()->getJson('/api/v1/accounts')->assertOk()->json();
        foreach ($out['accounts'] as $i => $a) {
            // The account rows gain their new columns (code, class, ...); compare the columns they had before.
            $out['accounts'][$i] = array_intersect_key($a, array_flip(['id', 'branch_id', 'name', 'type', 'current_balance', 'status', 'is_main_account', 'branch']));
        }

        return $out;
    }

    public function test_e5_ac30_ac31_ac49_backfill_of_the_e_dataset_and_trial_balance_e6_e7(): void
    {
        $this->legacyExample();
        $this->assertSame(20, Transaction::withoutGlobalScopes()->count());
        $balances = $this->moneyBalances();
        $lines = $this->lineRows();

        $this->migrate();

        // E-5 / AC 30
        $this->assertSame(62, LedgerEntry::count());
        $added = LedgerEntry::with('account')->where('origin', 'backfill')->get();
        $this->assertCount(33, $added);
        $this->assertSame(19, $added->pluck('transaction_id')->unique()->count());
        $this->assertSame(0, $added->filter(fn ($l) => $l->account->isMoney())->count(), 'no back-fill line on a money account');
        $this->assertSame($balances, $this->moneyBalances());
        // Current balances (GAL Cash 137,200.00 at 10-31 less the November reversal of (13)).
        $this->assertSame([620000_00, 254300_00, 40000_00, 133200_00], array_map(fn ($v) => (int) round($v * 100), array_values($this->moneyBalances())));
        $this->assertSame($lines, array_slice($this->lineRows(), 0, 29), 'existing lines unchanged');
        $this->assertSame(0, LedgerEntry::where('transaction_id', $this->t(8)->id)->where('origin', 'backfill')->count(), 'internal transfer gets nothing');
        $perTxn = $added->groupBy('transaction_id')->map->count();
        foreach (['cap' => 1, 'opn_kdy' => 2, 'opn_gal' => 2, 6 => 2, 9 => 2, 7 => 4, 11 => 4, 'rev11' => 4, 1 => 1, 'sep_rent' => 1, 10 => 1, 'rev10' => 1,
            13 => 1, 'rev13' => 1, 12 => 2] as $k => $n) {
            $this->assertSame($n, $perTxn[$this->t($k)->id], "lines added to ({$k})");
        }
        $audit = AuditLog::where('event', 'ledger.double_entry_backfilled')->sole();
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->branch_id);
        $this->assertSame([19, 33, true, 0], [$audit->new_values['transactions'], $audit->new_values['lines'], $audit->new_values['money_balances_unchanged'], $audit->new_values['skipped_legacy_voids']]);
        $this->assertSame(1, AuditLog::where('event', 'ledger.chart_created')->where('new_values->source', 'migration')->count());

        // AC 31: voided (12) gets Dr Rent and a contra Cr Rent on 10-20.
        $v = LedgerEntry::with('account')->where('transaction_id', $this->t(12)->id)->where('origin', 'backfill')->orderBy('id')->get();
        $this->assertSame(['Rent', 'Rent'], $v->pluck('account.name')->all());
        $this->assertSame(['15000.00', '0.00'], [$v[0]->debit, $v[0]->credit]);
        $this->assertSame(['0.00', '15000.00', $v[0]->id], [$v[1]->debit, $v[1]->credit, $v[1]->contra_of_id]);
        $this->assertSame(['2026-10-20', '2026-10-20'], $v->map(fn ($l) => $l->entry_date->format('Y-m-d'))->all());

        // E-6 / E-7 / E-8: the trial balance of the back-filled history.
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $this->assertE6($this->asAdmin()->getJson('/api/v1/reports/trial-balance?'.http_build_query($q))->assertOk()->json());
        $this->assertE7($this->asAdmin()->getJson('/api/v1/reports/trial-balance?'.http_build_query($q + ['view' => 'consolidated']))->assertOk()->json());
        $this->assertE8($this->asAdmin()->getJson('/api/v1/reports/trial-balance?from=2026-11-01&to=2026-11-30')->assertOk()->json());

        // AC 49
        $this->artisan('ledger:verify')->expectsOutputToContain('V6 back-fill complete')->assertExitCode(0);
    }

    public function test_ac27_reports_and_dashboard_identical_before_and_after(): void
    {
        $this->legacyExample();
        $before = $this->reportResponses();
        $this->migrate();
        $after = $this->reportResponses();
        foreach ($before as $key => $response) {
            $this->assertSame($response, $after[$key], $key);
        }
    }

    public function test_ac28_new_postings_after_the_migration_keep_the_money_reports(): void
    {
        $this->legacyExample();
        $this->migrate();
        $kdy = Branch::where('code', 'KDY')->first();
        $gal = Branch::where('code', 'GAL')->first();
        $q = '?from=2026-11-01&to=2026-11-30';
        $mainBefore = collect($this->asAdmin()->getJson("/api/v1/reports/branch-comparison{$q}")->assertOk()->json('rows'))->firstWhere('branch.code', 'MAIN');

        $kdyCash = Account::withoutGlobalScopes()->where('branch_id', $kdy->id)->where('name', 'Cash')->first();
        $galCash = Account::withoutGlobalScopes()->where('branch_id', $gal->id)->where('name', 'Cash')->first();
        $this->asAdmin($kdy->id)->postJson('/api/v1/incomes', ['account_id' => $kdyCash->id, 'category_id' => Category::where('name', 'Retail')->first()->id,
            'txn_date' => '2026-11-10', 'amount' => '1000.00'])->assertCreated();
        $this->asAdmin()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $kdyCash->id,
            'amount' => '5000.00', 'txn_date' => '2026-11-10', 'kind' => 'top_up'])->assertCreated();
        $mainAfterTopUp = collect($this->asAdmin()->getJson("/api/v1/reports/branch-comparison{$q}")->assertOk()->json('rows'))->firstWhere('branch.code', 'MAIN');
        $t = $this->asAdmin($kdy->id)->postJson('/api/v1/transfers', ['from_account_id' => $kdyCash->id, 'to_account_id' => $galCash->id,
            'amount' => '2000.00', 'txn_date' => '2026-11-11', 'note' => 'x'])->assertCreated()->json();
        $this->asAdmin($kdy->id)->postJson("/api/v1/transactions/{$t['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-12'])->assertCreated();

        $cmp = collect($this->asAdmin()->getJson("/api/v1/reports/branch-comparison{$q}")->assertOk()->json('rows'))->keyBy('branch.code');
        $this->assertSame($mainAfterTopUp, $cmp['MAIN'], "MAIN's row is unchanged by the branch transfer");
        $this->assertNotSame($mainBefore, $mainAfterTopUp);
        $book = $this->asAdmin()->getJson("/api/v1/reports/cash-book{$q}&branches[]={$this->main->id}")->assertOk()->json('accounts');
        $this->assertSame(['Main Account'], array_column(array_column($book, 'account'), 'name'));
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_ac29_inter_branch_movement_equals_the_transfers_report(): void
    {
        $this->legacyExample();
        $this->migrate();
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $tb = collect($this->asAdmin()->getJson('/api/v1/reports/trial-balance?'.http_build_query($q))->json('sections'))->keyBy('branch.code');
        $net = $this->asAdmin()->getJson('/api/v1/reports/transfers?'.http_build_query($q))->json('matrix.net');
        $c = fn ($v) => (int) round($v * 100);
        $ids = Branch::pluck('id', 'code');
        foreach (['KDY' => 7000000, 'GAL' => 1000000] as $code => $expected) {
            $ho = collect($tb[$code]['rows'])->firstWhere('account.type', 'head_office');
            $this->assertSame($expected, $c($ho['period_cr']) - $c($ho['period_dr']));
            $this->assertSame($expected, $c($net[$ids[$code]]));
        }
        $bcas = collect($tb['MAIN']['rows'])->where('account.type', 'branch_current');
        $mainDr = $bcas->sum(fn ($r) => $c($r['period_dr']) - $c($r['period_cr']));
        $this->assertSame(8000000, $mainDr);
        $this->assertSame(-$mainDr, $c($net[$ids['MAIN']]));
    }

    public function test_ac32_capital_into_a_non_main_branch_gets_three_lines(): void
    {
        $this->legacyExample(function (LegacyLedgerService $ledger) {
            $kdy = Branch::where('code', 'KDY')->first();
            $a = Account::withoutGlobalScopes()->create(['branch_id' => $kdy->id, 'name' => 'KDY Main', 'type' => 'bank', 'is_main_account' => true]);
            $ledger->capital($a->id, '50000.00', '2026-11-03', 'Owner capital into KDY');
        });
        $cap = Transaction::withoutGlobalScopes()->where('type', 'capital')->latest('id')->first();
        $this->migrate();
        $added = LedgerEntry::with(['account', 'branch'])->where('transaction_id', $cap->id)->where('origin', 'backfill')->orderBy('id')->get();
        $this->assertSame(['MAIN Dr Branch current account – KDY', "MAIN Cr Owner's capital", 'KDY Cr Head office current account'],
            $added->map(fn ($l) => $l->branch->code.' '.($l->debit !== '0.00' ? 'Dr' : 'Cr').' '.$l->account->name)->all());
        $this->assertSame(1, Account::withoutGlobalScopes()->where('type', 'owner_capital')->count());
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_ac33_locked_months_are_backfilled_and_listed(): void
    {
        $this->legacyExample();
        DB::table('period_locks')->insert(['branch_id' => null, 'period' => '2026-09', 'locked_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->migrate();
        $this->assertSame(1, LedgerEntry::where('transaction_id', $this->t('sep_rent')->id)->where('origin', 'backfill')->count());
        $this->assertSame(1, LedgerEntry::where('transaction_id', $this->t('cap')->id)->where('origin', 'backfill')->count());
        $audit = AuditLog::where('event', 'ledger.double_entry_backfilled')->sole();
        $this->assertEqualsCanonicalizing([
            ['branch' => 'GAL', 'period' => '2026-09', 'lines' => 1],
            ['branch' => 'KDY', 'period' => '2026-09', 'lines' => 2],
            ['branch' => 'MAIN', 'period' => '2026-09', 'lines' => 3],
        ], $audit->new_values['locked_periods']);
    }

    public function test_ac34_rerun_adds_nothing_and_a_stopped_run_completes_like_a_clean_one(): void
    {
        // A clean run, for comparison.
        $this->legacyExample();
        $this->migrate();
        $clean = [$this->describeLines(), $this->allBalances()];

        // The same history again, and a run that stops half-way (the 15th back-fill line fails).
        $this->toPreFeature();
        $this->legacyExample();
        $this->schema->up();
        // Commit every 3 entries, so the stop leaves committed work behind (the default batch is 100).
        $backfill = app(DoubleEntryBackfill::class);
        $backfill->batch = 3;
        $this->app->instance(DoubleEntryBackfill::class, $backfill);
        $inserts = 0;
        $armed = true;
        DB::listen(function ($q) use (&$inserts, &$armed) {
            if ($armed && preg_match('/^insert into [`"]ledger_entries[`"]/', $q->sql) && ++$inserts === 15) {
                throw new RuntimeException('Simulated stop');
            }
        });
        try {
            $this->backfill->up();
            $this->fail('the run did not stop');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated stop', $e->getMessage());
        }
        $armed = false;
        $partial = LedgerEntry::where('origin', 'backfill')->count();
        $this->assertGreaterThan(0, $partial);
        $this->assertLessThan(33, $partial);

        // Review R5: refused while the app is up, unless --force; runs in maintenance mode.
        $this->artisan('ledger:backfill-double-entry')->expectsOutputToContain('The application is up')->assertExitCode(1);
        $this->app->maintenanceMode()->activate([]);
        try {
            $this->artisan('ledger:backfill-double-entry')->expectsOutputToContain('Back-fill took')->assertExitCode(0);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
        $this->assertSame($clean, [$this->describeLines(), $this->allBalances()]);

        // A re-run adds nothing and writes its own audit row.
        $before = LedgerEntry::count();
        $this->artisan('ledger:backfill-double-entry', ['--force' => true])->expectsOutputToContain('lines added: 0')->assertExitCode(0);
        $this->assertSame($before, LedgerEntry::count());
        // Accountant sign-off A: every run leaves a row, the stopped one too, and together they account for every line.
        $runs = AuditLog::whereIn('event', ['ledger.double_entry_backfill_stopped', 'ledger.double_entry_backfilled'])->orderBy('id')->get();
        $this->assertSame(['ledger.double_entry_backfill_stopped', 'ledger.double_entry_backfilled', 'ledger.double_entry_backfilled'], $runs->pluck('event')->all());
        $this->assertSame([$partial, 33 - $partial, 0], $runs->map(fn ($a) => $a->new_values['lines'])->all());
        $this->assertSame(33, $runs->sum(fn ($a) => $a->new_values['lines']));
        $this->assertSame('Simulated stop', $runs[0]->new_values['error']);
        $this->assertNull($runs[0]->user_id);
        $this->artisan('ledger:verify')->assertExitCode(0);

        // README recovery (sign-off C): `migrate` afterwards records the back-fill migration and adds nothing.
        $this->assertFalse(DB::table('migrations')->where('migration', '2026_11_22_000001_double_entry_backfill')->exists());
        $lines = LedgerEntry::count();
        $this->migrate();
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_11_22_000001_double_entry_backfill')->exists());
        $this->assertSame($lines, LedgerEntry::count());
    }

    /** Back-filled lines by meaning (not by id): transaction ref, branch, account, sides, date, contra. */
    private function describeLines(): array
    {
        return LedgerEntry::with(['account', 'branch', 'transaction'])->orderBy('id')->get()
            ->map(fn ($l) => implode('|', [$l->transaction->ref_no, $l->branch->code, $l->account->code, $l->account->name, $l->debit, $l->credit,
                $l->entry_date->format('Y-m-d'), $l->origin, $l->contra_of_id ? 'contra' : '']))->sort()->values()->all();
    }

    private function allBalances(): array
    {
        return Account::withoutGlobalScopes()->with('branch')->get()->mapWithKeys(fn ($a) => [$a->branch->code.' '.$a->code.' '.$a->name => (string) $a->current_balance])
            ->sortKeys()->all();
    }

    public function test_ac35_an_unbalanced_backfilled_entry_stops_the_run(): void
    {
        $this->legacyExample();
        $this->migrate();
        $kdy = Branch::where('code', 'KDY')->first();
        $cash = Account::withoutGlobalScopes()->where('branch_id', $kdy->id)->where('name', 'Cash')->first();
        $cat = Category::where('name', 'Retail')->first();
        $catAcc = Account::withoutGlobalScopes()->where('branch_id', $kdy->id)->where('category_id', $cat->id)->first();
        $id = DB::table('transactions')->insertGetId(['ref_no' => 'BAD-1', 'branch_id' => $kdy->id, 'type' => 'income', 'txn_date' => '2026-11-05', 'amount' => '100.00',
            'account_id' => $cash->id, 'category_id' => $cat->id, 'status' => 'posted', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $row = ['transaction_id' => $id, 'branch_id' => $kdy->id, 'entry_date' => '2026-11-05', 'created_at' => now(), 'updated_at' => now()];
        DB::table('ledger_entries')->insert($row + ['account_id' => $cash->id, 'debit' => '100.00', 'credit' => '0', 'origin' => 'posting']);
        DB::table('ledger_entries')->insert($row + ['account_id' => $catAcc->id, 'debit' => '0', 'credit' => '60.00', 'origin' => 'backfill']);
        DB::table('accounts')->where('id', $cash->id)->update(['current_balance' => DB::raw('current_balance + 100')]);
        DB::table('accounts')->where('id', $catAcc->id)->update(['current_balance' => DB::raw('current_balance - 60')]);

        $this->artisan('ledger:backfill-double-entry', ['--force' => true])->expectsOutputToContain('BAD-1 has back-filled lines but does not balance')->assertExitCode(1);
        $this->assertSame(1, LedgerEntry::where('transaction_id', $id)->where('origin', 'backfill')->count());
        $this->assertSame(1, AuditLog::where('event', 'ledger.double_entry_backfilled')->count());
        $stopped = AuditLog::where('event', 'ledger.double_entry_backfill_stopped')->sole();
        $this->assertStringContainsString('BAD-1 has back-filled lines but does not balance', $stopped->new_values['error']);
        $this->assertSame(0, $stopped->new_values['lines'], 'the re-run had nothing else to add before BAD-1');
    }

    public function test_ac36_inactive_category_and_inactive_branch_are_backfilled(): void
    {
        $this->legacyExample(function (LegacyLedgerService $ledger) {
            $old = Category::create(['type' => 'income', 'name' => 'Old fees']);
            $kdy = Branch::where('code', 'KDY')->first();
            $cash = Account::withoutGlobalScopes()->where('branch_id', $kdy->id)->where('name', 'Cash')->first();
            $t = $ledger->incomeOrExpense('income', ['account_id' => $cash->id, 'category_id' => $old->id, 'txn_date' => '2026-11-04', 'amount' => '700.00']);
            $ledger->void($t, 'Wrong');
            $old->update(['is_active' => false]);
            $mtr = Branch::create(['code' => 'MTR', 'name' => 'Matara', 'status' => 'active']);
            $mtrCash = Account::withoutGlobalScopes()->create(['branch_id' => $mtr->id, 'name' => 'Cash', 'type' => 'cash']);
            $ledger->transfer($this->mainAcc->id, $mtrCash->id, '900.00', '2026-11-04', 'Opening', Transaction::OPENING_ALLOCATION);
            $mtr->update(['status' => 'inactive']);
        });
        $this->migrate();
        $void = Transaction::withoutGlobalScopes()->where('status', 'void')->where('amount', '700.00')->sole();
        $lines = LedgerEntry::with('account')->where('transaction_id', $void->id)->where('origin', 'backfill')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(['inactive'], $lines->pluck('account.status')->unique()->values()->all());
        $mtr = Branch::where('code', 'MTR')->first();
        $this->assertSame('-900.00', (string) Account::withoutGlobalScopes()->where('branch_id', $mtr->id)->where('type', 'head_office')->sole()->current_balance);
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_ac37_preflight_refuses_and_names_the_offender(): void
    {
        $this->legacyExample();
        $kdy = Branch::where('code', 'KDY')->first();
        $gal = Branch::where('code', 'GAL')->first();
        $cases = [
            'account balance' => fn () => DB::table('accounts')->where('branch_id', $kdy->id)->where('name', 'Bank')->update(['current_balance' => '1.00']),
            'no category' => fn () => DB::table('transactions')->where('id', $this->t(1)->id)->update(['category_id' => null]),
            "another branch's category" => function () use ($gal) {
                $own = Model::withoutEvents(fn () => Category::create(['type' => 'income', 'name' => 'GAL only', 'parent_id' => Category::where('name', 'Sales')->first()->id, 'branch_id' => $gal->id]));
                DB::table('transactions')->where('id', $this->t(2)->id)->update(['category_id' => $own->id]);
            },
            // Review R1: the chart code limits are checked before the DDL too.
            'branch id over 999' => fn () => DB::table('branches')->insert(['id' => 1000, 'code' => 'BIG', 'name' => 'Big', 'is_main' => false,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now()]),
            'too many categories' => fn () => DB::table('categories')->insert(array_map(fn ($i) => ['type' => 'income', 'name' => "Extra {$i}",
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()], range(1, 100))),
            'too many sub-categories' => fn () => DB::table('categories')->insert(array_map(fn ($i) => ['type' => 'expense', 'name' => "Rent {$i}",
                'parent_id' => Category::where('name', 'Rent')->value('id'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], range(1, 100))),
        ];
        $names = ['account balance' => 'KDY Bank', 'no category' => $this->t(1)->ref_no, "another branch's category" => $this->t(2)->ref_no.' (GAL only)',
            'branch id over 999' => 'BIG (id 1000)', 'too many categories' => 'top-level income categories; category codes allow 99 per type',
            'too many sub-categories' => 'sub-categories; category codes allow 99 under one parent'];
        foreach ($cases as $label => $break) {
            DB::beginTransaction();
            $break();
            try {
                $this->schema->up();
                $this->fail("{$label}: the migration did not stop");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Migration stopped before any change', $e->getMessage());
                $this->assertStringContainsString($names[$label], $e->getMessage(), $label);
            }
            $this->assertFalse(Schema::hasColumn('ledger_entries', 'origin'), "{$label}: nothing changed");
            $this->assertFalse(Schema::hasColumn('accounts', 'code'));
            $this->assertSame(29, LedgerEntry::count());
            DB::rollBack();
        }
    }

    public function test_ac38_legacy_voids_get_no_lines(): void
    {
        $this->legacyExample();
        $kdy = Branch::where('code', 'KDY')->first();
        $id = DB::table('transactions')->insertGetId(['ref_no' => 'LEG-1', 'branch_id' => $kdy->id, 'type' => 'expense', 'txn_date' => '2026-10-20', 'amount' => '250.00',
            'account_id' => Account::withoutGlobalScopes()->where('branch_id', $kdy->id)->where('name', 'Cash')->value('id'), 'category_id' => Category::where('name', 'Rent')->value('id'),
            'status' => 'void', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->migrate();
        $this->assertSame(0, LedgerEntry::where('transaction_id', $id)->count());
        $this->assertSame(1, AuditLog::where('event', 'ledger.double_entry_backfilled')->sole()->new_values['skipped_legacy_voids']);
        $this->artisan('ledger:verify')->expectsOutputToContain('Legacy voids (voided before contra entries; ledger lines were not kept): 1')->assertExitCode(0);
    }

    public function test_ac39_dry_run_writes_nothing_before_and_after_the_migration(): void
    {
        $this->legacyExample();
        $state = fn () => [LedgerEntry::count(), DB::table('accounts')->count(), AuditLog::count()];
        $before = $state();
        $this->artisan('ledger:backfill-double-entry --dry-run')
            ->expectsOutputToContain('DRY RUN (nothing written). Transactions to complete: 19; lines to add: 33')
            ->expectsOutputToContain("Owner's capital (MAIN)")
            ->expectsOutputToContain('Estimated time for the back-fill: about')
            ->assertExitCode(0);
        $this->assertSame($before, $state());

        $this->migrate();
        $after = $state();
        $this->artisan('ledger:backfill-double-entry --dry-run')->expectsOutputToContain('lines to add: 0')->assertExitCode(0);
        $this->assertSame($after, $state());
    }

    /**
     * Review R1: MySQL commits each DDL statement, so a schema migration that stops part-way leaves columns, keys or the
     * trigger behind. Each case stops right after one step (the statement has run); `php artisan migrate` then completes.
     */
    public function test_schema_migration_resumes_after_stopping_part_way(): void
    {
        $stopAfter = null;
        DB::listen(function ($q) use (&$stopAfter) {
            if ($stopAfter !== null && preg_match($stopAfter, strtolower($q->sql))) {
                $stopAfter = null;
                throw new RuntimeException('Simulated DDL failure');
            }
        });
        $steps = [
            'accounts.class' => '/^alter table [`"]?accounts[`"]? add (column )?[`"]?class[`"]?/',
            'accounts.category_id' => '/^alter table [`"]?accounts[`"]? add (column )?[`"]?category_id[`"]?/',
            'accounts unique key' => '/accounts_branch_id_category_id_unique/',
            'categories.ledger_code' => '/^alter table [`"]?categories[`"]? add (column )?[`"]?ledger_code[`"]?/',
            'ledger_entries.origin' => '/^alter table [`"]?ledger_entries[`"]? add (column )?[`"]?origin[`"]?/',
            'trigger' => '/^create trigger ledger_entries_line_check/',
        ];
        foreach ($steps as $label => $pattern) {
            $this->toPreFeature();
            $this->legacyExample();
            $stopAfter = $pattern;
            try {
                $this->schema->up();
                $this->fail("{$label}: the simulated failure did not happen");
            } catch (RuntimeException $e) {
                $this->assertSame('Simulated DDL failure', $e->getMessage(), $label);
            }
            $this->assertTrue(Schema::hasColumn('accounts', 'class'), "{$label}: partial schema left behind");

            $this->migrate();
            $this->assertSame(33, LedgerEntry::where('origin', 'backfill')->count(), $label);
            $this->artisan('ledger:verify')->expectsOutputToContain('ledger:verify passed.')->assertExitCode(0);
            $this->assertTrue(Schema::hasIndex('accounts', ['branch_id', 'counterparty_branch_id'], 'unique'), $label);
        }
    }

    public function test_ac54_detail_of_a_backfilled_void(): void
    {
        $this->legacyExample();
        $this->migrate();
        $gal = Branch::where('code', 'GAL')->first();
        $d = $this->asAdmin($gal->id)->getJson('/api/v1/transactions/'.$this->t(12)->id)->assertOk()->json();
        $this->assertCount(4, $d['entries']);
        $this->assertTrue($d['balanced']);
        $this->assertSame('2026-11-18', $d['backfilled_at']);
        $e = $d['entries'];
        $this->assertSame([null, $e[0]['id'], null, $e[2]['id']], array_column($e, 'contra_of_id'), 'money line, its contra, back-filled Rent, its contra');
        $this->assertSame(['posting', 'posting', 'backfill', 'backfill'], array_column($e, 'origin'));
        $this->assertSame(['Cash', 'Cash', 'Rent', 'Rent'], array_column(array_column($e, 'account'), 'name'));
        $this->assertSame(['debit' => '30000.00', 'credit' => '30000.00'], $d['totals']);
    }

    public function test_ac40_locked_month_still_refuses_real_postings_and_no_public_bypass(): void
    {
        $this->legacyExample();
        DB::table('period_locks')->insert(['branch_id' => null, 'period' => '2026-09', 'locked_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->migrate();
        $kdy = Branch::where('code', 'KDY')->first();
        $cash = Account::withoutGlobalScopes()->where('branch_id', $kdy->id)->where('name', 'Cash')->first();
        $rent = $this->t('sep_rent');
        $count = LedgerEntry::count();
        $msg = 'The period 2026-09 is locked.';
        $this->asAdmin($kdy->id)->postJson('/api/v1/incomes', ['account_id' => $cash->id, 'category_id' => Category::where('name', 'Retail')->value('id'),
            'txn_date' => '2026-09-25', 'amount' => '10.00'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->asAdmin($kdy->id)->putJson("/api/v1/transactions/{$rent->id}", ['category_id' => Category::where('name', 'Water')->value('id')])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->asAdmin($kdy->id)->postJson("/api/v1/transactions/{$rent->id}/void", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->asAdmin($kdy->id)->postJson("/api/v1/transactions/{$rent->id}/reverse", ['reason' => 'x', 'txn_date' => '2026-09-28'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->assertSame($count, LedgerEntry::count());

        // No public LedgerService method takes a lock bypass; the back-fill entry point takes only the transaction.
        foreach ((new ReflectionClass(LedgerService::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            foreach ($m->getParameters() as $p) {
                $this->assertDoesNotMatchRegularExpression('/ignore|bypass|skip|force|lock/i', $p->getName(), "{$m->getName()}(\${$p->getName()})");
            }
        }
        $this->assertSame(['txn'], array_map(fn ($p) => $p->getName(), (new ReflectionMethod(LedgerService::class, 'backfill'))->getParameters()));
    }
}
