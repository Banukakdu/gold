<?php

namespace Tests\Feature\DoubleEntry;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Double-entry spec ACs 20-26: chart of accounts per branch (R1, S1, section 5). */
class ChartOfAccountsTest extends DoubleEntryTestCase
{
    private function chart($as, array $q = []): array
    {
        return $as->getJson('/api/v1/chart-of-accounts?'.http_build_query($q))->assertOk()->json();
    }

    public function test_ac20_new_categories_create_their_accounts(): void
    {
        $utilities = $this->cat('expense', 'Utilities');
        $this->sa()->postJson('/api/v1/categories', ['name' => 'Mobile data', 'parent_id' => $utilities->id])->assertCreated();
        $global = Category::where('name', 'Mobile data')->sole();
        $this->assertSame(substr($utilities->ledger_code, 0, 3), substr($global->ledger_code, 0, 3));
        $accounts = Account::withoutGlobalScopes()->where('category_id', $global->id)->get();
        $this->assertEqualsCanonicalizing(Branch::pluck('id')->all(), $accounts->pluck('branch_id')->all());
        foreach ($accounts as $a) {
            $this->assertSame(['Utilities > Mobile data', $global->ledger_code, 'expense', 'category'], [$a->name, $a->code, $a->class, $a->type]);
        }

        $this->accKdy()->postJson('/api/v1/categories', ['name' => 'Generator fuel', 'parent_id' => $utilities->id])->assertCreated();
        $own = Category::where('name', 'Generator fuel')->sole();
        $this->assertSame($this->kdy->id, $own->branch_id);
        $this->assertSame([$this->kdy->id], Account::withoutGlobalScopes()->where('category_id', $own->id)->pluck('branch_id')->all());
        $this->assertNotSame($global->ledger_code, $own->ledger_code);
    }

    /** QA B1: a category created through the API (no is_active in the request) gets active accounts. */
    public function test_new_category_accounts_are_active(): void
    {
        $this->sa()->postJson('/api/v1/categories', ['type' => 'income', 'name' => 'Training fees'])->assertCreated();
        $parent = Category::where('name', 'Training fees')->sole();
        $this->sa()->postJson('/api/v1/categories', ['name' => 'Remote', 'parent_id' => $parent->id])->assertCreated();
        $this->accKdy()->postJson('/api/v1/categories', ['name' => 'On site', 'parent_id' => $parent->id])->assertCreated();
        $ids = Category::whereIn('name', ['Training fees', 'Remote', 'On site'])->pluck('id')->all();
        $accounts = Account::withoutGlobalScopes()->whereIn('category_id', $ids)->get();
        $this->assertCount(2 * Branch::count() + 1, $accounts);
        $this->assertSame(['active'], $accounts->pluck('status')->unique()->values()->all());
        $income = collect($this->chart($this->accKdy())['groups'])->firstWhere('group', 'income')['accounts'];
        $this->assertSame(['active', 'active', 'active'], collect($income)->whereIn('category_id', $ids)->pluck('status')->values()->all());
    }

    /**
     * Review R3b: code assignment locks the single `chart_code_locks` row (no foreign key points at it), never a
     * `branches` row, which every posting share-locks through its foreign keys. The MySQL stress run is in the PR notes.
     */
    public function test_code_assignment_locks_the_chart_lock_row_not_branches(): void
    {
        $this->assertSame([1], DB::table('chart_code_locks')->pluck('id')->all());
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        $this->accKdy()->postJson('/api/v1/accounts', ['name' => 'Second bank', 'type' => 'bank'])->assertCreated();
        $this->sa()->postJson('/api/v1/categories', ['type' => 'expense', 'name' => 'Lock probe'])->assertCreated();
        $this->assertGreaterThanOrEqual(2, count(preg_grep('/from [`"]chart_code_locks[`"]/', $sql)));
        $this->assertSame([], preg_grep('/^select .* from [`"]branches[`"] where .*for update/', $sql));

        DB::table('chart_code_locks')->delete();
        $this->accKdy()->postJson('/api/v1/accounts', ['name' => 'Third bank', 'type' => 'bank'])->assertCreated();
        $this->assertSame([1], DB::table('chart_code_locks')->pluck('id')->all(), 'a missing lock row is recreated');
    }

    /** Review R4: a branch or category inserted without model events gets its chart from ledger:chart-sync. */
    public function test_chart_sync_creates_missing_accounts(): void
    {
        $this->artisan('ledger:chart-sync')->expectsOutputToContain('Nothing was added')->assertExitCode(0);
        $raw = Model::withoutEvents(fn () => Branch::create(['code' => 'RAW', 'name' => 'Raw', 'status' => 'active']));
        $cat = Model::withoutEvents(fn () => Category::create(['type' => 'expense', 'name' => 'Raw cost']));
        $this->assertSame(0, Account::withoutGlobalScopes()->where('branch_id', $raw->id)->count());

        $this->artisan('ledger:chart-sync')->expectsOutputToContain('Chart accounts added')->assertExitCode(0);
        $this->assertSame($this->main->id, $this->ho($raw)->counterparty_branch_id);
        $this->assertSame('Branch current account – RAW', $this->bca($raw)->name);
        $this->assertSame(Category::whereNull('branch_id')->count(), Account::withoutGlobalScopes()->where('branch_id', $raw->id)->where('type', 'category')->count());
        $this->assertNotNull($cat->fresh()->ledger_code);
        $this->assertSame(Branch::count(), Account::withoutGlobalScopes()->where('category_id', $cat->id)->count());
        $this->assertSame('sync', AuditLog::where('event', 'ledger.chart_created')->latest('id')->first()->new_values['source']);

        $count = Account::withoutGlobalScopes()->count();
        $this->artisan('ledger:chart-sync')->expectsOutputToContain('Nothing was added')->assertExitCode(0);
        $this->assertSame($count, Account::withoutGlobalScopes()->count());
    }

    public function test_ac21_rename_and_deactivate_follow_the_category(): void
    {
        $sales = $this->cat('income', 'Sales');
        $this->sa()->postJson('/api/v1/categories', ['name' => 'Retail', 'parent_id' => $sales->id])->assertCreated();
        $retail = $this->cat('income', 'Retail');
        $this->sa()->putJson("/api/v1/categories/{$retail->id}", ['name' => 'Retail sales'])->assertOk();
        $this->assertSame(['Sales > Retail sales'], Account::withoutGlobalScopes()->where('category_id', $retail->id)->pluck('name')->unique()->values()->all());
        $this->assertSame(Branch::count(), AuditLog::where('event', 'account.updated')->where('new_values->name', 'Sales > Retail sales')->count());

        // A renamed parent renames its sub-categories' accounts too.
        $this->sa()->putJson("/api/v1/categories/{$sales->id}", ['name' => 'Sales revenue'])->assertOk();
        $this->assertSame('Sales revenue > Retail sales', $this->catAcc($retail, $this->kdy)->name);
        $this->assertSame('Sales revenue', $this->catAcc($sales, $this->kdy)->name);

        $this->sa()->putJson("/api/v1/categories/{$retail->id}", ['is_active' => false])->assertOk();
        $row = collect(collect($this->chart($this->accKdy())['groups'])->firstWhere('group', 'income')['accounts'])->firstWhere('category_id', $retail->id);
        $this->assertSame('inactive', $row['status']);
        $this->accKdy()->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id, 'category_id' => $retail->id, 'txn_date' => '2026-11-05', 'amount' => '1.00'])
            ->assertStatus(422);
        $this->sa()->putJson("/api/v1/categories/{$retail->id}", ['is_active' => true])->assertOk();
        $this->assertSame('active', $this->catAcc($retail, $this->kdy)->status);
    }

    public function test_ac22_branch_creation_creates_its_chart(): void
    {
        $before = Account::withoutGlobalScopes()->whereIn('branch_id', [$this->kdy->id, $this->gal->id])->count();
        $mtr = $this->makeBranch('MTR');
        $acc = Account::withoutGlobalScopes()->where('branch_id', $mtr->id)->get();
        // Review N7: the create response lists the branch's cash, bank and wallet accounts only.
        $this->assertSame([['Cash', 'cash']], array_map(fn ($a) => [$a['name'], $a['type']],
            $this->sa()->postJson('/api/v1/branches', ['code' => 'NUW', 'name' => 'Nuwara'])->assertCreated()->json('accounts')));
        $this->assertSame('11001', $acc->firstWhere('name', 'Cash')->code);
        $ho = $acc->firstWhere('type', 'head_office');
        $this->assertSame(['Head office current account', '15'.str_pad((string) $this->main->id, 3, '0', STR_PAD_LEFT), $this->main->id],
            [$ho->name, $ho->code, $ho->counterparty_branch_id]);
        $this->assertSame(Category::whereNull('branch_id')->count(), $acc->where('type', 'category')->count());
        $this->assertSame(0, $acc->where('type', 'owner_capital')->count());
        $bca = $this->bca($mtr);
        $this->assertSame(['Branch current account – MTR', '15'.str_pad((string) $mtr->id, 3, '0', STR_PAD_LEFT)], [$bca->name, $bca->code]);
        $this->assertSame($before, Account::withoutGlobalScopes()->whereIn('branch_id', [$this->kdy->id, $this->gal->id])->count());

        // R1: inter-branch codes are limited to branch id 999.
        DB::table('branches')->insert(['id' => 999, 'code' => 'B999', 'name' => 'Placeholder', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()]);
        $count = Branch::count();
        $this->sa()->postJson('/api/v1/branches', ['code' => 'NXT', 'name' => 'Next'])->assertStatus(422)
            ->assertJsonPath('message', 'No more branches can be created (inter-branch account codes are limited to branch id 999).');
        $this->assertSame($count, Branch::count());
        $this->assertNull(Branch::where('code', 'NXT')->first());
    }

    public function test_ac23_cashier_sees_structure_without_balances_and_no_chart_statements(): void
    {
        $chart = $this->chart($this->as($this->csh, $this->kdy));
        $this->assertSame('KDY', $chart['branch']['code']);
        $this->assertSame(['asset', 'liability', 'equity', 'interbranch', 'income', 'expense'], array_column($chart['groups'], 'group'));
        $this->assertSame('Inter-branch (eliminated on consolidation)', $chart['groups'][3]['label']);
        $this->assertSame([], $chart['groups'][1]['accounts']);
        foreach ($chart['groups'] as $g) {
            foreach ($g['accounts'] as $a) {
                $this->assertArrayNotHasKey('current_balance', $a);
                $this->assertArrayNotHasKey('balance', $a);
            }
        }
        $this->assertSame(['Head office current account'], array_column($chart['groups'][3]['accounts'], 'name'));
        $this->assertSame('system', $chart['groups'][3]['accounts'][0]['origin']);
        $this->assertSame('accounts', $chart['groups'][0]['accounts'][0]['origin']);
        $codes = array_column($chart['groups'][4]['accounts'], 'code');
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes, 'category accounts in code order: parent then its subs');

        $q = '?from=2026-10-01&to=2026-11-30';
        $this->as($this->csh, $this->kdy)->getJson("/api/v1/accounts/{$this->ho($this->kdy)->id}/statement{$q}")
            ->assertForbidden()->assertJsonPath('message', 'Missing permission: report.view');
        $this->as($this->csh, $this->kdy)->getJson("/api/v1/accounts/{$this->kdyCash->id}/statement{$q}")->assertOk();
        $st = $this->accKdy()->getJson("/api/v1/accounts/{$this->ho($this->kdy)->id}/statement{$q}")->assertOk()->json();
        $this->assertSame('-100000.00', $st['closing_balance']);
    }

    public function test_ac24_main_chart_has_the_branch_current_accounts_and_owner_capital(): void
    {
        $chart = $this->chart($this->sa(), ['branch_id' => $this->main->id]);
        $groups = collect($chart['groups'])->keyBy('group');
        $this->assertSame(['Branch current account – KDY', 'Branch current account – GAL'], array_column($groups['interbranch']['accounts'], 'name'));
        $this->assertSame(['KDY', 'GAL'], array_column(array_column($groups['interbranch']['accounts'], 'counterparty_branch'), 'code'));
        $this->assertSame(["Owner's capital", 'Retained earnings'], array_column($groups['equity']['accounts'], 'name'));
        foreach ([$this->kdy, $this->gal] as $b) {
            $this->assertSame([], collect($this->chart($this->sa(), ['branch_id' => $b->id])['groups'])->firstWhere('group', 'equity')['accounts']);
        }
    }

    public function test_ac25_branch_scope(): void
    {
        $this->accKdy()->getJson('/api/v1/chart-of-accounts?branch_id='.$this->gal->id)->assertForbidden();
        $this->accKdy()->getJson('/api/v1/chart-of-accounts?branch_id='.$this->kdy->id)->assertOk();
        $this->sa()->getJson('/api/v1/chart-of-accounts')->assertStatus(422);
        $this->as($this->ba, $this->gal)->getJson('/api/v1/chart-of-accounts')->assertOk()->assertJsonPath('branch.code', 'GAL');
    }

    public function test_ac26_money_accounts_only_in_lists_and_pickers(): void
    {
        $money = fn (array $rows) => array_values(array_unique(array_column($rows, 'type')));
        $this->assertEqualsCanonicalizing(['cash'], $money($this->accKdy()->getJson('/api/v1/accounts')->assertOk()->json()));
        $this->assertEqualsCanonicalizing(['cash', 'bank'], $money($this->sa()->getJson('/api/v1/accounts')->assertOk()->json()));
        $this->assertEqualsCanonicalizing(['cash', 'bank'], $money($this->accKdy()->getJson('/api/v1/accounts/destinations')->assertOk()->json()));
        $dash = $this->sa()->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['cash', 'bank'], $money($dash['accounts']));
        $this->assertSame('1000000.00', $dash['total_balance']);
        $this->assertEquals(['MAIN' => '850000.00', 'KDY' => '100000.00', 'GAL' => '50000.00'],
            collect($dash['branch_balances'])->mapWithKeys(fn ($b) => [$b['code'] => $b['balance']])->all());
        $branches = collect($this->sa()->getJson('/api/v1/branches')->assertOk()->json())->mapWithKeys(fn ($b) => [$b['code'] => $b['balance']]);
        $this->assertEquals(850000, $branches['MAIN']);

        $this->sa()->putJson('/api/v1/accounts/'.$this->catAcc($this->category('expense'), $this->kdy)->id, ['name' => 'X'])->assertStatus(422)
            ->assertJsonPath('message', 'This account belongs to the chart of accounts and changes with its category or branch.');
        $this->sa()->putJson('/api/v1/accounts/'.$this->ho($this->kdy)->id, ['status' => 'closed'])->assertStatus(422);
    }
}
