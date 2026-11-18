<?php

namespace Tests\Feature\Categories;

use App\Models\AuditLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/** Fix round: reviewer S1 and N2, QA Q1 and Q3. */
class FixRoundTest extends CategoriesTestCase
{
    /** S1: a promote that lands between the access check and the write makes the row Super Admin only. */
    public function test_s1_access_is_rechecked_under_the_chart_lock(): void
    {
        $c = $this->tukTuk();
        $done = false;
        // Simulate the race: the promote commits right after this request's first access check, while it waits for
        // the chart lock (the promote holds the same lock, so the stored row is global once the lock is granted).
        DB::listen(function (QueryExecuted $q) use (&$done, $c) {
            if (! $done && str_contains($q->sql, 'chart_code_locks')) {
                $done = true;
                DB::table('categories')->where('id', $c->id)->update(['branch_id' => null]);
            }
        });
        $this->edit($this->kba, $this->kdy, $c, ['name' => 'Disguised'])->assertForbidden()
            ->assertJsonPath('message', 'Only Super Admin can change global categories.');
        $this->assertTrue($done);
        $this->assertSame('Tuk-tuk hire', $c->fresh()->name);
        $this->assertSame(0, AuditLog::where('event', 'category.updated')->count());
    }

    /** N2: another branch's row is 404 even when the request would also be a 422. */
    public function test_n2_access_before_the_fixed_field_checks(): void
    {
        $gal = $this->tukTuk($this->gal);
        $this->edit($this->kba, $this->kdy, $gal, ['type' => 'income'])->assertNotFound();
        $this->edit($this->kba, $this->kdy, $gal, ['parent_id' => 1])->assertNotFound();
        $this->edit($this->kba, $this->kdy, $this->cat('Fuel'), ['type' => 'expense'])->assertForbidden();
    }

    /** Q1: "Make global" is offered only for an effectively active branch-only sub. */
    public function test_q1_can_promote_only_for_effectively_active_subs(): void
    {
        $c = $this->tukTuk();
        $row = fn () => $this->manage($this->admin, 'all')['Transport > Tuk-tuk hire [KDY]'];
        $this->assertTrue($row()['can_promote']);
        $this->edit($this->kba, $this->kdy, $c, ['is_active' => false])->assertOk()->assertJsonPath('can_promote', false);
        $this->assertFalse($row()['can_promote']);
        $this->edit($this->kba, $this->kdy, $c, ['is_active' => true])->assertOk();
        $this->edit($this->admin, 'all', $this->cat('Transport'), ['is_active' => false])->assertOk();
        $this->assertFalse($row()['can_promote']);
        $this->assertFalse($this->edit($this->admin, 'all', $c, ['name' => 'Tuk-tuk'])->assertOk()->json('can_promote'));
    }

    /** Q3: the first entry date per node (parent = earliest of its own and its subs') for the node-specific rename warning. */
    public function test_q3_first_entry_date_and_meta_end_dates(): void
    {
        $this->spend($this->cat('Electricity'), '2026-11-05');
        $this->spend($this->cat('Water'), '2026-10-12');
        $this->lock('2026-10', null)->assertCreated();
        $r = $this->sa()->getJson('/api/v1/categories?manage=1&type=expense')->assertOk()->json();
        $t = $this->manage($this->admin, 'all');
        $this->assertSame(['2026-11-05', '2026-10-12', '2026-10-12', null],
            [$t['Utilities > Electricity']['first_entry_date'], $t['Utilities > Water']['first_entry_date'], $t['Utilities']['first_entry_date'],
                $t['Utilities > Internet']['first_entry_date']]);
        $this->assertSame(['period' => '2026-10', 'label' => 'October 2026', 'end' => '2026-10-31'], $r['meta']['locked_through']);
        $this->assertNull($r['meta']['closed_year']);
    }
}
