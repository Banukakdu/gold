<?php

namespace Tests\Feature\Categories;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Controls\ControlsTestCase;

/**
 * docs/specs/categories.md section 7 fixtures on the controls setup: MAIN, KDY, GAL; seeded categories; SA, KBA (Branch
 * Admin KDY), KACC (Accountant KDY, $this->acc), KCASH (Cashier KDY, $this->csh), GBA (Branch Admin GAL, $this->ba).
 * "Today" is 2026-11-18.
 */
abstract class CategoriesTestCase extends ControlsTestCase
{
    protected User $kba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kba = $this->userIn($this->kdy, 'Branch Admin');
    }

    protected function cat(string $name, ?string $parent = null): Category
    {
        return Category::where('name', $name)
            ->when($parent, fn ($q) => $q->where('parent_id', Category::whereNull('parent_id')->where('name', $parent)->value('id')))
            ->firstOrFail();
    }

    /** POST a category as a user in a branch. */
    protected function create(User $u, Branch|string $branch, array $data): TestResponse
    {
        return $this->as($u, $branch)->postJson('/api/v1/categories', $data);
    }

    protected function edit(User $u, Branch|string $branch, Category $c, array $data): TestResponse
    {
        return $this->as($u, $branch)->putJson("/api/v1/categories/{$c->id}", $data);
    }

    protected function promote(Category $c, ?User $u = null, Branch|string $branch = 'all'): TestResponse
    {
        return $this->as($u ?? $this->admin, $branch)->postJson("/api/v1/categories/{$c->id}/promote");
    }

    /** KDY's branch-only "Tuk-tuk hire" under Transport, created by KBA. */
    protected function tukTuk(?Branch $b = null, ?User $by = null): Category
    {
        $b ??= $this->kdy;
        $id = $this->create($by ?? ($b->is($this->gal) ? $this->ba : $this->kba), $b, ['name' => 'Tuk-tuk hire', 'parent_id' => $this->cat('Transport')->id])
            ->assertCreated()->json('id');

        return Category::findOrFail($id);
    }

    /** An I&E response without the "<code> only" labels: a promote removes the tag, never a figure (review finding 8). */
    public static function withoutBranchTags(array $r): array
    {
        foreach (['income', 'expense'] as $t) {
            foreach ($r[$t]['categories'] ?? [] as $i => $c) {
                foreach ($c['subs'] as $j => $s) {
                    unset($r[$t]['categories'][$i]['subs'][$j]['branch_only']);
                }
            }
        }

        return $r;
    }

    /** @return array<string, string> account status by branch code */
    protected function statuses(Category $c): array
    {
        $codes = Branch::pluck('code', 'id');

        return Account::withoutGlobalScopes()->where('category_id', $c->id)->orderBy('branch_id')->get()
            ->mapWithKeys(fn (Account $a) => [$codes[$a->branch_id] => $a->status])->all();
    }

    /** POST an expense in KDY (as SA) to a category. */
    protected function spend(Category $c, string $date = '2026-11-10', string $amount = '100.00', ?Branch $b = null): array
    {
        $b ??= $this->kdy;
        $acc = $b->is($this->gal) ? $this->galCash : $this->kdyCash;

        return $this->sa($b)->postJson('/api/v1/expenses', ['account_id' => $acc->id, 'category_id' => $c->id, 'txn_date' => $date, 'amount' => $amount])
            ->assertCreated()->json();
    }

    /** The S1 tree (manage=1) as a flat list of nodes keyed "Parent" / "Parent > Sub" (with the branch code for branch-only subs). */
    protected function manage(User $u, Branch|string $branch, string $type = 'expense'): array
    {
        $out = [];
        foreach ($this->as($u, $branch)->getJson("/api/v1/categories?manage=1&type={$type}")->assertOk()->json('data') as $p) {
            $out[$p['name']] = $p;
            foreach ($p['children'] as $c) {
                $out[$p['name'].' > '.$c['name'].($c['branch'] ? " [{$c['branch']['code']}]" : '')] = $c;
            }
        }

        return $out;
    }
}
