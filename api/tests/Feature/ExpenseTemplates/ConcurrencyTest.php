<?php

namespace Tests\Feature\ExpenseTemplates;

use App\Exceptions\DuplicateTemplateUseException;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTemplate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\BranchContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AC 19 / review finding 1 on MySQL with two real connections. Connection B opens its transaction and makes a plain
 * read first, so its InnoDB REPEATABLE READ snapshot is older than the use that connection A then commits (before the
 * fix, LedgerService::incomeOrExpense started with exactly such a read). B's use must still see A's expense and
 * answer 409: the template's uses are read with a locking read, which InnoDB answers from the latest committed data.
 *
 * MySQL only (SQLite has one writer and no snapshots of this kind). No RefreshDatabase: a second connection cannot see
 * the test transaction. It starts from migrate:fresh --seed and leaves an empty migrated database, as MigrationTest.
 */
class ConcurrencyTest extends TestCase
{
    private const PROBE = 'mysql_probe';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Two-connection snapshot test: MySQL only.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatabaseSeeder::class);
        config(['database.connections.'.self::PROBE => config('database.connections.'.DB::getDefaultConnection())]);
        Carbon::setTestNow('2026-11-20 10:00:00');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::purge(self::PROBE);
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    public function test_ac19_a_use_with_an_older_snapshot_still_sees_the_committed_use(): void
    {
        $admin = User::where('is_super_admin', true)->firstOrFail();
        $acc = Account::withoutGlobalScopes()->whereIn('type', Account::MONEY_TYPES)->where('status', 'active')->orderBy('id')->firstOrFail();
        $cat = Category::where('type', 'expense')->whereNotNull('parent_id')->where('is_active', true)->whereNull('branch_id')->orderBy('id')->firstOrFail();
        Setting::updateOrCreate(['key' => 'expense_negative_balance'], ['value' => 'warn']);
        $tpl = RecurringTemplate::withoutGlobalScopes()->create(['branch_id' => $acc->branch_id, 'type' => 'expense', 'name' => 'Rent',
            'account_id' => $acc->id, 'category_id' => $cat->id, 'amount_cents' => 1500000, 'payment_method' => 'cash', 'frequency' => 'monthly',
            'version' => 1, 'created_by' => $admin->id]);
        $this->actingAs($admin);
        app(BranchContext::class)->set((int) $acc->branch_id);
        $data = ['template_id' => $tpl->id, 'account_id' => $acc->id, 'category_id' => $cat->id, 'txn_date' => '2026-11-20', 'amount' => '15000.00',
            'payment_method' => 'cash'];
        $default = DB::getDefaultConnection();

        // B: transaction open, snapshot taken by a plain read before A's use exists.
        $b = DB::connection(self::PROBE);
        $b->beginTransaction();
        $this->assertSame(0, $b->table('transactions')->where('expense_template_id', $tpl->id)->count());

        // A: the first use, committed.
        $first = app(LedgerService::class)->incomeOrExpense(Transaction::EXPENSE, $data);
        $this->assertSame('posted', $first->status);

        // B's own snapshot still does not see it ...
        $this->assertSame(0, $b->table('transactions')->where('expense_template_id', $tpl->id)->count());
        // ... but B's use does, through the locking read: 409, nothing written.
        DB::setDefaultConnection(self::PROBE);
        try {
            app(LedgerService::class)->incomeOrExpense(Transaction::EXPENSE, $data);
            $this->fail('The second use in the same month was not refused.');
        } catch (DuplicateTemplateUseException $e) {
            $this->assertStringContainsString($first->ref_no, $e->getMessage());
        } finally {
            DB::setDefaultConnection($default);
            $b->rollBack();
        }
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('expense_template_id', $tpl->id)->count());
    }
}
