<?php

namespace Tests\Feature\Categories;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Categories spec ACs 1-17 (create, uniqueness, edit, type, move), 43 and review AC 50. */
class CreateAndEditTest extends CategoriesTestCase
{
    public function test_ac1_super_admin_adds_a_top_level_category(): void
    {
        $max = Category::whereNull('parent_id')->where('type', 'expense')->max('ledger_code');
        $r = $this->create($this->admin, 'all', ['name' => 'Insurance', 'type' => 'expense'])->assertCreated()
            ->assertJsonPath('ledger_code', '5'.sprintf('%02d', (int) substr($max, 1, 2) + 1).'00')
            ->assertJsonPath('branch', null)->assertJsonPath('usage_count', 0)->assertJsonPath('can_edit', true);
        $c = Category::findOrFail($r->json('id'));
        $this->assertNull($c->parent_id);
        $this->assertSame(Branch::count(), Account::withoutGlobalScopes()->where('category_id', $c->id)->count());
        $log = AuditLog::where('event', 'category.created')->sole();
        $this->assertNull($log->branch_id);
        // MySQL JSON columns do not keep key order.
        $this->assertSame(Arr::sortRecursive(['name' => 'Insurance', 'type' => 'expense', 'parent_id' => null, 'branch_id' => null, 'ledger_code' => $c->ledger_code]), Arr::sortRecursive($log->new_values));
    }

    public function test_ac2_ac4_branch_users_cannot_add_top_level_and_cashier_nothing(): void
    {
        $count = Category::count();
        $this->create($this->kba, $this->kdy, ['name' => 'Insurance', 'type' => 'expense'])->assertForbidden();
        $this->create($this->csh, $this->kdy, ['name' => 'Insurance', 'type' => 'expense'])->assertForbidden();
        $this->create($this->csh, $this->kdy, ['name' => 'Tuk-tuk hire', 'parent_id' => $this->cat('Transport')->id])->assertForbidden();
        $this->assertSame($count, Category::count());
        $this->assertSame(0, AuditLog::where('event', 'like', 'category.%')->count());
    }

    public function test_ac3_branch_admin_adds_a_branch_only_sub(): void
    {
        $transport = $this->cat('Transport');
        $c = $this->tukTuk();
        $this->assertSame([$this->kdy->id, 'expense'], [$c->branch_id, $c->type]);
        $this->assertSame(substr($transport->ledger_code, 0, 3), substr($c->ledger_code, 0, 3));
        $this->assertSame(['KDY' => 'active'], $this->statuses($c));
        $log = AuditLog::where('event', 'category.created')->sole();
        $this->assertSame([$this->kdy->id, $this->kba->id, $c->ledger_code], [$log->branch_id, $log->user_id, $log->new_values['ledger_code']]);
    }

    public function test_ac5_ac6_names_are_unique_within_the_parent_trimmed_and_case_insensitive(): void
    {
        $utilities = $this->cat('Utilities');
        $this->create($this->admin, 'all', ['name' => 'water ', 'parent_id' => $utilities->id])->assertStatus(422)
            ->assertJsonPath('message', 'A category with this name already exists here.');
        $this->create($this->kba, $this->kdy, ['name' => 'WATER', 'parent_id' => $utilities->id])->assertStatus(422)
            ->assertJsonPath('message', 'A category with this name already exists here.');
        // Inactive rows are included (R3).
        $this->edit($this->admin, 'all', $this->cat('Internet'), ['is_active' => false])->assertOk();
        $this->create($this->kba, $this->kdy, ['name' => 'internet', 'parent_id' => $utilities->id])->assertStatus(422);
        // Top level: unique per type.
        $this->create($this->admin, 'all', ['name' => 'rent', 'type' => 'expense'])->assertStatus(422);
        $this->create($this->admin, 'all', ['name' => 'Rent', 'type' => 'income'])->assertCreated();
    }

    public function test_ac7_ac8_branch_only_names_per_branch_and_a_global_sub_clashes_with_them(): void
    {
        $kdy = $this->tukTuk();
        $gal = $this->tukTuk($this->gal);
        $this->assertNotSame($kdy->ledger_code, $gal->ledger_code);
        $this->assertSame(['GAL' => 'active'], $this->statuses($gal));
        $count = Category::count();
        $this->create($this->admin, 'all', ['name' => 'Tuk-tuk hire', 'parent_id' => $this->cat('Transport')->id])->assertStatus(422)
            ->assertJsonPath('message', 'A sub-category with this name already exists in GAL, KDY. Rename it first.');
        // A KDY user cannot add a second one in KDY.
        $this->create($this->acc, $this->kdy, ['name' => 'tuk-tuk HIRE', 'parent_id' => $this->cat('Transport')->id])->assertStatus(422)
            ->assertJsonPath('message', 'A category with this name already exists here.');
        $this->assertSame($count, Category::count());
    }

    public function test_ac9_ac10_parent_must_be_an_active_top_level_node(): void
    {
        $utilities = $this->cat('Utilities');
        $this->edit($this->admin, 'all', $utilities, ['is_active' => false])->assertOk();
        $this->create($this->kba, $this->kdy, ['name' => 'Gas', 'parent_id' => $utilities->id])->assertStatus(422)
            ->assertJsonPath('message', 'This category is inactive. Reactivate it before adding sub-categories.');
        $this->create($this->admin, 'all', ['name' => 'Gas', 'parent_id' => $this->cat('Fuel')->id])->assertStatus(422)
            ->assertJsonPath('message', 'Only two levels are allowed (Category > Sub-category).');
    }

    public function test_ac11_rename_follows_everywhere_and_is_audited(): void
    {
        $el = $this->cat('Electricity');
        $this->spend($el, '2026-10-15', '700.00');
        $this->edit($this->admin, 'all', $el, ['name' => 'Power'])->assertOk()->assertJsonPath('name', 'Power');
        $this->assertSame(['Utilities > Power'], Account::withoutGlobalScopes()->where('category_id', $el->id)->pluck('name')->unique()->values()->all());
        $this->assertSame(Branch::count(), Account::withoutGlobalScopes()->where('category_id', $el->id)->count());
        $log = AuditLog::where('event', 'category.updated')->sole();
        $this->assertSame([['name' => 'Electricity'], ['name' => 'Power'], null], [$log->old_values, $log->new_values, $log->branch_id]);
        $ie = $this->sa()->getJson('/api/v1/reports/income-expense?from=2026-10-01&to=2026-10-31')->assertOk()->json('expense.categories');
        $this->assertSame('Power', collect(collect($ie)->firstWhere('name', 'Utilities')['subs'])->firstWhere('id', $el->id)['name']);
    }

    public function test_ac12_rename_runs_the_duplicate_check(): void
    {
        $el = $this->cat('Electricity');
        $this->edit($this->admin, 'all', $el, ['name' => 'WATER'])->assertStatus(422)->assertJsonPath('message', 'A category with this name already exists here.');
        $this->assertSame('Electricity', $el->fresh()->name);
        // A case-only change of its own name is fine.
        $this->edit($this->admin, 'all', $el, ['name' => 'ELECTRICITY'])->assertOk();
        // A global sub renamed onto a branch-only sibling's name lists the branch.
        $this->tukTuk();
        $this->edit($this->admin, 'all', $this->cat('Fuel'), ['name' => 'Tuk-tuk hire'])->assertStatus(422)
            ->assertJsonPath('message', 'A sub-category with this name already exists in KDY. Rename it first.');
        // Top level, per type.
        $this->edit($this->admin, 'all', $this->cat('Utilities'), ['name' => 'rent'])->assertStatus(422);
    }

    public function test_ac13_ac14_type_parent_and_branch_cannot_change(): void
    {
        $el = $this->cat('Electricity');
        $before = $el->only(['name', 'type', 'parent_id', 'branch_id', 'is_active']);
        $this->edit($this->admin, 'all', $el, ['type' => 'expense', 'name' => 'X'])->assertStatus(422)
            ->assertJsonPath('message', 'The type of a category cannot be changed.');
        $move = 'A sub-category cannot be moved to another category. Create it under the new category and change the category on each entry.';
        $this->edit($this->admin, 'all', $el, ['parent_id' => $this->cat('Rent')->id])->assertStatus(422)->assertJsonPath('message', $move);
        $this->edit($this->admin, 'all', $el, ['branch_id' => $this->kdy->id])->assertStatus(422)->assertJsonPath('message', $move);
        $this->edit($this->admin, 'all', $el, ['branch_id' => null])->assertStatus(422)->assertJsonPath('message', $move);
        $this->assertSame($before, $el->fresh()->only(array_keys($before)));
        $this->assertSame(0, AuditLog::where('event', 'category.updated')->count());
    }

    public function test_ac15_ac16_ac17_access_to_edit(): void
    {
        $gal = $this->tukTuk($this->gal);
        $kdy = $this->tukTuk();
        foreach ([$this->cat('Utilities'), $this->cat('Electricity')] as $global) {
            $this->edit($this->kba, $this->kdy, $global, ['name' => 'X'])->assertForbidden()
                ->assertJsonPath('message', 'Only Super Admin can change global categories.');
        }
        $this->edit($this->kba, $this->kdy, $gal, ['name' => 'X'])->assertNotFound();
        $this->edit($this->csh, $this->kdy, $kdy, ['name' => 'X'])->assertForbidden();
        $this->edit($this->acc, $this->kdy, $kdy, ['name' => 'Three-wheeler hire'])->assertOk()->assertJsonPath('name', 'Three-wheeler hire');
        $log = AuditLog::where('event', 'category.updated')->sole();
        $this->assertSame([$this->kdy->id, ['name' => 'Tuk-tuk hire'], ['name' => 'Three-wheeler hire']], [$log->branch_id, $log->old_values, $log->new_values]);
        // Super Admin may edit any row, in any mode.
        $this->edit($this->admin, $this->kdy, $gal, ['name' => 'Tuk-tuk (GAL)'])->assertOk();
    }

    public function test_ac43_no_more_codes_under_a_parent(): void
    {
        $p = Category::create(['type' => 'expense', 'name' => 'Full']);
        for ($i = 1; $i <= 99; $i++) {
            Category::create(['type' => 'expense', 'name' => "S{$i}", 'parent_id' => $p->id]);
        }
        $count = [Category::count(), Account::withoutGlobalScopes()->count(), AuditLog::count()];
        $this->create($this->kba, $this->kdy, ['name' => 'One more', 'parent_id' => $p->id])->assertStatus(422)
            ->assertJsonPath('message', 'No more category codes are available under Full. Ask the accountant.');
        $this->assertSame($count, [Category::count(), Account::withoutGlobalScopes()->count(), AuditLog::count()]);
    }

    /** Review finding 6 (AC 50): the chart lock is taken before the name check, in the write's transaction. */
    public function test_ac50_name_checks_run_under_the_chart_lock(): void
    {
        $kdy = $this->tukTuk();
        $calls = [
            fn () => $this->create($this->kba, $this->kdy, ['name' => 'Ferry', 'parent_id' => $this->cat('Transport')->id])->assertCreated(),
            fn () => $this->edit($this->kba, $this->kdy, $kdy, ['name' => 'Three-wheeler'])->assertOk(),
            fn () => $this->promote($kdy)->assertOk(),
        ];
        foreach ($calls as $call) {
            $log = [];
            DB::listen(function ($q) use (&$log) {
                $log[] = [$q->sql, DB::transactionLevel()];
            });
            $call();
            $lock = collect($log)->search(fn ($e) => str_contains($e[0], 'chart_code_locks') && $e[1] > 0);
            $check = collect($log)->search(fn ($e) => str_contains(strtolower($e[0]), 'lower(trim(name))') && $e[1] > 0);
            $this->assertIsInt($lock);
            $this->assertIsInt($check);
            $this->assertLessThan($check, $lock);
            DB::flushQueryLog();
            DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
        }
    }
}
