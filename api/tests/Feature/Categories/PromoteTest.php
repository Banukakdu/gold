<?php

namespace Tests\Feature\Categories;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;

/** Categories spec ACs 30-35 (promote, AG-071) and review ACs 45 (promoted row), 47 and 49. */
class PromoteTest extends CategoriesTestCase
{
    private function tb(array $q): array
    {
        $r = $this->sa()->getJson('/api/v1/reports/trial-balance?'.http_build_query($q))->assertOk()->json();
        unset($r['generated_at']);

        return $r;
    }

    public function test_ac30_ac31_ac45_promote_keeps_the_code_adds_zero_accounts_and_changes_no_figure(): void
    {
        $c = $this->tukTuk();
        foreach (['2026-10-20', '2026-11-02', '2026-11-03', '2026-11-04'] as $d) {
            $this->spend($c, $d, '250.00');
        }
        $origin = Account::withoutGlobalScopes()->where('category_id', $c->id)->sole();
        $lines = LedgerEntry::where('account_id', $origin->id)->orderBy('id')->get()->toArray();
        $q = ['from' => '2026-10-01', 'to' => '2026-11-30'];
        $tbBefore = [$this->tb($q + ['view' => 'consolidated']), $this->tb($q)];
        $ieBefore = [$this->sa($this->kdy)->getJson('/api/v1/reports/income-expense?from=2026-10-01&to=2026-10-31')->json(),
            $this->sa()->getJson('/api/v1/reports/income-expense?from=2026-10-01&to=2026-11-30')->json()];
        $entries = LedgerEntry::count();

        $this->promote($c)->assertOk()->assertJsonPath('branch', null)->assertJsonPath('ledger_code', $c->ledger_code)
            ->assertJsonPath('can_promote', false);
        $c->refresh();
        $this->assertNull($c->branch_id);
        $accounts = Account::withoutGlobalScopes()->where('category_id', $c->id)->orderBy('branch_id')->get();
        $this->assertSame(Branch::count(), $accounts->count());
        foreach ($accounts as $a) {
            $this->assertSame(['Transport > Tuk-tuk hire', $c->ledger_code, 'active'], [$a->name, $a->code, $a->status]);
            if ($a->id !== $origin->id) {
                $this->assertSame('0.00', (string) $a->current_balance);
            }
        }
        $this->assertSame($lines, LedgerEntry::where('account_id', $origin->id)->orderBy('id')->get()->toArray());
        $this->assertSame((string) $origin->current_balance, (string) $origin->fresh()->current_balance);
        $this->assertSame($entries, LedgerEntry::count(), 'promote posts nothing');

        // Finding 1: one category.promoted row, written with the origin branch.
        $log = AuditLog::where('event', 'category.promoted')->sole();
        $created = $accounts->where('id', '!=', $origin->id)->pluck('id')->values()->all();
        $this->assertSame($this->kdy->id, $log->branch_id);
        $this->assertSame(['old_branch_id' => $this->kdy->id, 'new_branch_id' => null, 'ledger_code' => $c->ledger_code,
            'name' => 'Tuk-tuk hire', 'created_account_ids' => $created], $log->new_values);
        $this->assertSame(['branch_id' => $this->kdy->id], $log->old_values);
        $seen = $this->as($this->kba, $this->kdy)->getJson('/api/v1/audit-logs?from=2026-11-18&to=2026-11-18&event_group=categories')->assertOk()->json('data');
        $this->assertContains('category.promoted', array_column($seen, 'event'));
        $this->assertContains('Category made global', array_column($seen, 'event_label'));

        // Visible in GAL's entry form now.
        $gal = collect($this->as($this->ba, $this->gal)->getJson('/api/v1/categories?type=expense')->json())->firstWhere('name', 'Transport');
        $this->assertContains('Tuk-tuk hire', array_column($gal['children'], 'name'));

        // AC 31 / finding 5: one consolidated line, the same figures, KDY and All-branch I&E unchanged, ledger:verify passes.
        $this->assertSame($tbBefore, [$this->tb($q + ['view' => 'consolidated']), $this->tb($q)]);
        $rows = collect($tbBefore[0]['rows'])->where('key', 'cat:'.$c->ledger_code);
        $this->assertCount(1, $rows);
        $this->assertSame($ieBefore, [$this->sa($this->kdy)->getJson('/api/v1/reports/income-expense?from=2026-10-01&to=2026-10-31')->json(),
            $this->sa()->getJson('/api/v1/reports/income-expense?from=2026-10-01&to=2026-11-30')->json()]);
        $this->artisan('ledger:verify')->assertExitCode(0);
        // The other branches can now post to it.
        $this->spend($c->fresh(), '2026-11-10', '10.00', $this->gal);
    }

    public function test_ac32_ac49_a_name_clash_in_another_branch_refuses_even_when_inactive(): void
    {
        $c = $this->tukTuk();
        $gal = $this->tukTuk($this->gal);
        $this->edit($this->ba, $this->gal, $gal, ['is_active' => false])->assertOk();
        $snapshot = [Account::withoutGlobalScopes()->count(), AuditLog::count()];
        $this->promote($c)->assertStatus(422)->assertJsonPath('message', 'A sub-category with this name already exists in GAL. Rename it first.');
        $this->assertSame($this->kdy->id, $c->fresh()->branch_id);
        $this->assertSame($snapshot, [Account::withoutGlobalScopes()->count(), AuditLog::count()]);
        // After GAL renames its own, the promote goes through.
        $this->edit($this->ba, $this->gal, $gal, ['name' => 'Tuk-tuk hire (old)'])->assertOk();
        $this->promote($c)->assertOk();
    }

    public function test_ac33_ac47_only_an_effectively_active_branch_only_sub(): void
    {
        $c = $this->tukTuk();
        $this->edit($this->kba, $this->kdy, $c, ['is_active' => false])->assertOk();
        $this->promote($c)->assertStatus(422)->assertJsonPath('message', 'Reactivate it before making it global.');
        $this->edit($this->kba, $this->kdy, $c, ['is_active' => true])->assertOk();
        $this->edit($this->admin, 'all', $this->cat('Transport'), ['is_active' => false])->assertOk();
        $this->promote($c)->assertStatus(422)->assertJsonPath('message', 'Reactivate it and its category before making it global.');
        $this->assertSame($this->kdy->id, $c->fresh()->branch_id);
        foreach ([$this->cat('Fuel'), $this->cat('Transport')] as $global) {
            $this->promote($global)->assertStatus(422)->assertJsonPath('message', 'Only a branch-only sub-category can be made global.');
        }
        $this->assertSame(0, AuditLog::where('event', 'category.promoted')->count());
    }

    public function test_ac34_only_super_admin_promotes(): void
    {
        $c = $this->tukTuk();
        $this->promote($c, $this->kba, $this->kdy)->assertForbidden();
        $this->promote($c, $this->acc, $this->kdy)->assertForbidden();
        $this->assertSame($this->kdy->id, $c->fresh()->branch_id);
    }

    public function test_ac35_promote_in_a_locked_month_posts_nothing(): void
    {
        $c = $this->tukTuk();
        $this->spend($c, '2026-10-20', '250.00');
        $this->lock('2026-11', null)->assertCreated();
        $entries = LedgerEntry::count();
        $this->promote($c)->assertOk();
        $this->assertSame($entries, LedgerEntry::count());
        $this->assertNull(Category::find($c->id)->branch_id);
    }

    public function test_promote_creates_inactive_branch_accounts_and_takes_the_effective_status(): void
    {
        $this->sa()->putJson('/api/v1/branches/'.$this->gal->id, ['status' => 'inactive'])->assertOk();
        $c = $this->tukTuk();
        $this->promote($c)->assertOk();
        $this->assertSame(['MAIN' => 'active', 'KDY' => 'active', 'GAL' => 'active'], $this->statuses($c));
    }
}
