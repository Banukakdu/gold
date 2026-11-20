<?php

namespace Tests\Feature\Categories;

/** Categories spec ACs 25-29 (visibility, branch isolation, usage count) and the S1 manage shape. */
class VisibilityTest extends CategoriesTestCase
{
    public function test_ac25_a_branch_only_sub_is_visible_in_its_branch_only(): void
    {
        $this->tukTuk();
        $subs = fn ($u, $b) => array_column(collect($this->as($u, $b)->getJson('/api/v1/categories?type=expense')->assertOk()->json())
            ->firstWhere('name', 'Transport')['children'], 'name');
        $this->assertNotContains('Tuk-tuk hire', $subs($this->ba, $this->gal));
        $this->assertContains('Tuk-tuk hire', $subs($this->csh, $this->kdy));
    }

    public function test_ac26_super_admin_all_mode_sees_every_branch_only_sub_and_inactive_nodes(): void
    {
        $kdy = $this->tukTuk();
        $gal = $this->tukTuk($this->gal);
        $this->edit($this->admin, 'all', $this->cat('Internet'), ['is_active' => false])->assertOk();
        $this->spend($kdy, '2026-11-05');
        $t = $this->manage($this->admin, 'all');
        $k = $t['Transport > Tuk-tuk hire [KDY]'];
        $this->assertSame([['id' => $this->kdy->id, 'code' => 'KDY'], $kdy->ledger_code, 1, true, true, true],
            [$k['branch'], $k['ledger_code'], $k['usage_count'], $k['can_edit'], $k['can_promote'], $k['is_active']]);
        $this->assertSame(['id' => $this->gal->id, 'code' => 'GAL'], $t['Transport > Tuk-tuk hire [GAL]']['branch']);
        $this->assertFalse($t['Utilities > Internet']['is_active']);
        $this->assertFalse($t['Utilities > Internet']['can_promote']);
        $this->assertNotContains('income', array_column($t, 'type'));
        $this->assertSame(['fiscal_year', 'locked_through', 'closed_year'],
            array_keys($this->sa()->getJson('/api/v1/categories?manage=1')->assertOk()->json('meta')));
    }

    public function test_ac27_ac28_branch_admin_sees_global_and_own_rows_only_and_cashier_is_refused(): void
    {
        $kdy = $this->tukTuk();
        $gal = $this->tukTuk($this->gal);
        $el = $this->cat('Electricity');
        $this->spend($el, '2026-11-05');
        $this->spend($el, '2026-11-05', '100.00', $this->gal);
        $t = $this->manage($this->kba, $this->kdy);
        $this->assertArrayHasKey('Transport > Tuk-tuk hire [KDY]', $t);
        $this->assertArrayNotHasKey('Transport > Tuk-tuk hire [GAL]', $t);
        $this->assertSame(1, $t['Utilities > Electricity']['usage_count']);
        $this->assertSame([false, false], [$t['Utilities > Electricity']['can_edit'], $t['Utilities']['can_edit']]);
        $this->assertSame([true, false], [$t['Transport > Tuk-tuk hire [KDY]']['can_edit'], $t['Transport > Tuk-tuk hire [KDY]']['can_promote']]);
        $this->assertSame('100.00', $t['Utilities > Electricity']['year_balance']);
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/categories?manage=1')->assertForbidden();
        $this->assertNotNull($gal);
    }

    public function test_ac29_usage_count_by_mode_and_parent_total(): void
    {
        $el = $this->cat('Electricity');
        foreach (range(1, 3) as $i) {
            $this->spend($el, "2026-11-0{$i}");
        }
        $g = $this->spend($el, '2026-11-04', '100.00', $this->gal);
        $this->spend($el, '2026-11-05', '100.00', $this->gal);
        $this->as($this->ba, $this->gal)->postJson("/api/v1/transactions/{$g['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->spend($this->cat('Water'), '2026-11-06');

        $this->assertSame(3, $this->manage($this->kba, $this->kdy)['Utilities > Electricity']['usage_count']);
        $all = $this->manage($this->admin, 'all');
        $this->assertSame([5, 6], [$all['Utilities > Electricity']['usage_count'], $all['Utilities']['usage_count']]);
        $this->assertSame(4, $this->manage($this->kba, $this->kdy)['Utilities']['usage_count']);
        // Year balance excludes the voided entry (it nets to zero).
        $this->assertSame('400.00', $all['Utilities > Electricity']['year_balance']);
    }

    public function test_children_of_an_inactive_parent_are_returned_with_their_effective_status(): void
    {
        $this->edit($this->admin, 'all', $this->cat('Utilities'), ['is_active' => false])->assertOk();
        $t = $this->manage($this->kba, $this->kdy);
        $this->assertSame([true, false], [$t['Utilities > Water']['is_active'], $t['Utilities > Water']['effective_active']]);
        $this->assertFalse($t['Utilities']['is_active']);
    }
}
