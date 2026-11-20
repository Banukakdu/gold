<?php

namespace Tests\Feature\Categories;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Services\ChartOfAccounts;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Categories spec ACs 18-24 and review ACs 45 (updated rows), 46 and 48 a-c. */
class DeactivateTest extends CategoriesTestCase
{
    private function sumOn(Category $c): int
    {
        return (int) LedgerEntry::whereIn('account_id', Account::withoutGlobalScopes()->where('category_id', $c->id)->pluck('id'))->get()
            ->sum(fn ($l) => Money::toCents($l->debit) - Money::toCents($l->credit));
    }

    public function test_ac18_ac19_ac20_deactivate_a_used_sub(): void
    {
        $el = $this->cat('Electricity');
        foreach (range(1, 5) as $d) {
            $this->spend($el, "2026-11-0{$d}", '10.00');
        }
        $counts = [Category::count(), Transaction::withoutGlobalScopes()->count(), LedgerEntry::count()];
        $this->edit($this->admin, 'all', $el, ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $log = AuditLog::where('event', 'category.updated')->sole();
        $this->assertSame([['is_active' => true], ['is_active' => false]], [$log->old_values, $log->new_values]);
        $this->assertSame(['MAIN' => 'inactive', 'KDY' => 'inactive', 'GAL' => 'inactive'], $this->statuses($el));
        $this->assertSame($counts, [Category::count(), Transaction::withoutGlobalScopes()->count(), LedgerEntry::count()]);

        // AC 19: not in the cashier's entry form, and refused on posting.
        $tree = $this->as($this->csh, $this->kdy)->getJson('/api/v1/categories?type=expense')->assertOk()->json();
        $this->assertNotContains('Electricity', array_column(collect($tree)->firstWhere('name', 'Utilities')['children'], 'name'));
        $this->as($this->csh, $this->kdy)->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $el->id,
            'txn_date' => '2026-11-10', 'amount' => '1.00'])->assertStatus(422)->assertJsonPath('message', 'Category must be an active expense category.');

        // AC 20: reports and the list still show it.
        $ie = $this->sa()->getJson('/api/v1/reports/income-expense?from=2026-11-01&to=2026-11-30')->assertOk()->json('expense.categories');
        $sub = collect(collect($ie)->firstWhere('name', 'Utilities')['subs'])->firstWhere('id', $el->id);
        $this->assertSame(['Electricity', '50.00', false], [$sub['name'], $sub['amount'], $sub['is_active']]);
        $row = $this->sa($this->kdy)->getJson('/api/v1/transactions?category_id='.$el->id.'&category_only=1')->assertOk()->json('data.0');
        $this->assertSame(['Electricity', 'Utilities'], [$row['category']['name'], $row['category']['parent']['name']]);
    }

    public function test_ac21_ac22_deactivating_a_parent_hides_its_subs_and_reactivating_brings_back_exactly_the_active_ones(): void
    {
        [$ut, $el, $wa, $in] = [$this->cat('Utilities'), $this->cat('Electricity'), $this->cat('Water'), $this->cat('Internet')];
        $this->edit($this->admin, 'all', $in, ['is_active' => false])->assertOk();
        $e = $this->spend($this->cat('Telephone'), '2026-11-05', '40.00');
        $this->edit($this->admin, 'all', $this->cat('Telephone'), ['is_active' => false])->assertOk();
        $flags = fn () => Category::whereIn('id', [$el->id, $wa->id, $in->id])->orderBy('id')->pluck('is_active')->all();
        $this->assertSame([true, true, false], $flags());

        $this->edit($this->admin, 'all', $ut, ['is_active' => false])->assertOk();
        $log = AuditLog::where('event', 'category.updated')->where('auditable_id', $ut->id)->sole();
        $this->assertSame(['is_active' => false, 'affected_sub_ids' => [$el->id, $wa->id]], $log->new_values);
        $this->assertNotContains('Utilities', array_column($this->as($this->csh, $this->kdy)->getJson('/api/v1/categories?type=expense')->json(), 'name'));
        foreach ([$ut, $el, $wa, $in] as $c) {
            $this->assertSame(['MAIN' => 'inactive', 'KDY' => 'inactive', 'GAL' => 'inactive'], $this->statuses($c), $c->name);
        }
        $this->assertSame([true, true, false], $flags());
        // Posting or reclassifying TO Water (parent inactive) is refused (R6).
        $this->as($this->acc, $this->kdy)->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $wa->id,
            'txn_date' => '2026-11-10', 'amount' => '1.00'])->assertStatus(422)->assertJsonPath('message', 'Category must be an active expense category.');
        $other = $this->expense('2026-11-06', '15.00');
        $this->accKdy()->putJson("/api/v1/transactions/{$other['id']}", ['category_id' => $wa->id])->assertStatus(422)
            ->assertJsonPath('message', 'Choose an active expense category of this branch.');
        try {
            app(LedgerService::class)->reclassify(Transaction::withoutGlobalScopes()->find($other['id']), $wa->id);
            $this->fail('reclassify to a sub of an inactive parent must be refused');
        } catch (LedgerException $ex) {
            $this->assertSame('Choose an active expense category of this branch.', $ex->getMessage());
        }

        // AC 22: reactivate the parent.
        $this->edit($this->admin, 'all', $ut, ['is_active' => true])->assertOk();
        $children = array_column(collect($this->as($this->csh, $this->kdy)->getJson('/api/v1/categories?type=expense')->json())
            ->firstWhere('name', 'Utilities')['children'], 'name');
        $this->assertSame(['Electricity', 'Water'], $children);
        $this->assertSame(['MAIN' => 'active', 'KDY' => 'active', 'GAL' => 'active'], $this->statuses($el));
        $this->assertSame(['MAIN' => 'inactive', 'KDY' => 'inactive', 'GAL' => 'inactive'], $this->statuses($in));
        $this->assertSame(['is_active' => true, 'affected_sub_ids' => [$el->id, $wa->id]],
            AuditLog::where('event', 'category.updated')->where('auditable_id', $ut->id)->orderByDesc('id')->first()->new_values);
        $this->assertSame('posted', Transaction::withoutGlobalScopes()->find($e['id'])->status);
    }

    public function test_ac23_an_entry_in_an_inactive_category_can_be_edited_but_not_moved_to_another_inactive_one(): void
    {
        $el = $this->cat('Electricity');
        $e = $this->spend($el, '2026-11-05', '40.00');
        $this->edit($this->admin, 'all', $el, ['is_active' => false])->assertOk();
        $this->edit($this->admin, 'all', $this->cat('Water'), ['is_active' => false])->assertOk();
        $this->accKdy()->putJson("/api/v1/transactions/{$e['id']}", ['description' => 'Meter 7'])->assertOk();
        $this->accKdy()->putJson("/api/v1/transactions/{$e['id']}", ['category_id' => $this->cat('Water')->id])->assertStatus(422)
            ->assertJsonPath('message', 'Choose an active expense category of this branch.');
        $this->assertSame($el->id, (int) Transaction::withoutGlobalScopes()->find($e['id'])->category_id);
    }

    public function test_ac24_there_is_no_delete(): void
    {
        $el = $this->cat('Electricity');
        $this->sa()->deleteJson("/api/v1/categories/{$el->id}")->assertStatus(405);
        $this->assertNotNull($el->fresh());
    }

    /** Review finding 4 (AC 48 a-c): void, reverse and the reclassify SOURCE still post to an inactive category's account. */
    public function test_ac48_void_reverse_and_reclassify_from_still_post_to_inactive_accounts(): void
    {
        [$ut, $el] = [$this->cat('Utilities'), $this->cat('Electricity')];
        $a = $this->spend($el, '2026-11-02', '300.00');
        $b = $this->spend($el, '2026-11-03', '200.00');
        $c = $this->spend($el, '2026-11-04', '50.00');
        $this->edit($this->admin, 'all', $el, ['is_active' => false])->assertOk();
        $this->edit($this->admin, 'all', $ut, ['is_active' => false])->assertOk();
        $acc = Account::withoutGlobalScopes()->where('category_id', $el->id)->where('branch_id', $this->kdy->id)->sole();
        $this->assertSame('inactive', $acc->status);

        $this->accKdy()->postJson("/api/v1/transactions/{$a['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->accKdy()->postJson("/api/v1/transactions/{$b['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-12'])->assertCreated();
        $this->accKdy()->putJson("/api/v1/transactions/{$c['id']}", ['category_id' => $this->cat('Fuel')->id])->assertOk();

        $this->assertSame(0, $this->sumOn($el), 'the inactive account nets to 0.00');
        $this->assertGreaterThan(0, LedgerEntry::where('account_id', $acc->id)->where('credit', '>', 0)->count());
        $ie = collect($this->sa($this->kdy)->getJson('/api/v1/reports/income-expense?from=2026-11-01&to=2026-11-30')->json('expense.categories'))
            ->firstWhere('name', 'Utilities');
        $this->assertSame('0.00', $ie['amount'] ?? '0.00');
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    /** Review finding 2 (AC 46): every account status set from a category uses its effective status. */
    public function test_ac46_new_accounts_take_the_effective_status_and_closed_stays_closed(): void
    {
        $ut = $this->cat('Utilities');
        $kdy = $this->create($this->kba, $this->kdy, ['name' => 'Generator', 'parent_id' => $ut->id])->assertCreated()->json('id');
        $kdy = Category::find($kdy);
        $this->edit($this->admin, 'all', $ut, ['is_active' => false])->assertOk();
        $this->assertSame(['KDY' => 'inactive'], $this->statuses($kdy));

        $mtr = $this->makeBranch('MTR');
        $status = fn (string $name) => Account::withoutGlobalScopes()->where('branch_id', $mtr->id)
            ->where('category_id', $this->cat($name, 'Utilities')->id)->value('status');
        $this->assertSame(['inactive', 'inactive', 'inactive'], [$status('Electricity'), $status('Water'),
            Account::withoutGlobalScopes()->where('branch_id', $mtr->id)->where('category_id', $ut->id)->value('status')]);
        $this->assertSame('active', Account::withoutGlobalScopes()->where('branch_id', $mtr->id)->where('category_id', $this->cat('Fuel')->id)->value('status'));

        // The posting safety net (a missing account created on demand) also uses the effective status.
        $water = $this->cat('Water');
        DB::table('accounts')->where('branch_id', $this->gal->id)->where('category_id', $water->id)->update(['category_id' => null, 'type' => 'cash', 'class' => 'asset', 'code' => '11999']);
        $this->assertSame('inactive', app(ChartOfAccounts::class)->categoryAccount($water->fresh(), $this->gal->id)->status);

        // A closed account stays closed on any sync.
        $el = $this->cat('Electricity');
        DB::table('accounts')->where('branch_id', $this->kdy->id)->where('category_id', $el->id)->update(['status' => 'closed']);
        $this->edit($this->admin, 'all', $ut, ['is_active' => true])->assertOk();
        $this->edit($this->admin, 'all', $ut, ['name' => 'Utility bills'])->assertOk();
        $this->assertSame(['MAIN' => 'active', 'KDY' => 'closed', 'GAL' => 'active', 'MTR' => 'active'], $this->statuses($el));
        $this->assertSame(['KDY' => 'active'], $this->statuses($kdy));
    }
}
