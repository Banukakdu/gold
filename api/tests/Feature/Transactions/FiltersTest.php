<?php

namespace Tests\Feature\Transactions;

use App\Models\Account;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

/** docs/specs/transaction-search.md ACs 1, 3-13 and 40 (R1-R9, R15). */
class FiltersTest extends TransactionSearchTestCase
{
    public function test_ac1_default_list_is_the_kdy_scope_by_date_desc(): void
    {
        $r = $this->kdyList();
        $this->assertSame($this->ids('E7', 'E6', 'E4', 'E3', 'E2', 'E1', 'E5', 'E9'), $this->rowIds($r));
        $this->assertSame(8, $r->json('total'));
        $this->assertNotContains($this->e['E8']->id, $this->rowIds($r));
    }

    public function test_ac3_date_range_is_inclusive_and_strict(): void
    {
        $this->assertSame($this->ids('E4', 'E3', 'E2'), $this->rowIds($this->kdyList(['from' => '2026-10-10', 'to' => '2026-10-15'])));
        $this->kdyList(['to' => '2026-10-01', 'from' => '2026-10-02'])->assertStatus(422)->assertJsonPath('errors.to.0', 'To must be on or after From.');
        $this->kdyList(['from' => '10/01/2026'])->assertStatus(422)->assertJsonValidationErrors('from');
        // A To alone is valid (no From to compare with).
        $this->assertSame($this->ids('E5', 'E9'), $this->rowIds($this->kdyList(['to' => '2026-10-01'])));
    }

    public function test_ac4_type_list(): void
    {
        $this->assertSame($this->ids('E4', 'E3', 'E2'), $this->rowIds($this->kdyList(['type' => 'expense'])));
        $this->assertSame($this->ids('E7', 'E1'), $this->rowIds($this->kdyList(['type' => 'income,reversal'])));
        $this->kdyList(['type' => 'foo'])->assertStatus(422)->assertJsonPath('errors.type.0', 'Unknown type: foo.');
    }

    public function test_ac5_status_list(): void
    {
        $this->assertSame($this->ids('E4'), $this->rowIds($this->kdyList(['status' => 'void'])));
        $this->assertSame($this->ids('E6'), $this->rowIds($this->kdyList(['status' => 'reversed'])));
        $this->assertSame($this->ids('E7', 'E4', 'E3', 'E2', 'E1', 'E5', 'E9'), $this->rowIds($this->kdyList(['status' => 'posted,void'])));
        $this->kdyList(['status' => 'pending'])->assertStatus(422)->assertJsonPath('errors.status.0', 'Unknown status: pending.');
    }

    public function test_ac6_account_matches_money_account_on_any_side(): void
    {
        $this->assertSame($this->ids('E7', 'E6', 'E4', 'E3', 'E2', 'E1', 'E5'), $this->rowIds($this->kdyList(['account_id' => $this->kdyCash->id])));
    }

    public function test_ac7_category_and_sub_category(): void
    {
        $this->assertSame($this->ids('E3', 'E2'), $this->rowIds($this->kdyList(['category_id' => $this->utilities->id])));
        $this->assertSame($this->ids('E3'), $this->rowIds($this->kdyList(['category_id' => $this->utilities->id, 'category_only' => 1])));
        $this->assertSame($this->ids('E2'), $this->rowIds($this->kdyList(['category_id' => $this->electricity->id])));

        $this->utilities->update(['is_active' => false]);
        $this->electricity->update(['is_active' => false]);
        $this->assertSame($this->ids('E3', 'E2'), $this->rowIds($this->kdyList(['category_id' => $this->utilities->id])));
        $this->assertSame($this->ids('E2'), $this->rowIds($this->kdyList(['category_id' => $this->electricity->id])));
    }

    public function test_ac8_created_by(): void
    {
        $this->assertSame($this->ids('E4', 'E3', 'E2'), $this->rowIds($this->kdyList(['created_by' => $this->kamal->id])));
        $r = $this->kdyList(['created_by' => $this->saman->id]);
        $this->assertSame([], $this->rowIds($r));
        $this->kdyList(['created_by' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('created_by');
    }

    public function test_ac10_amount_range_in_cents(): void
    {
        $this->assertEqualsCanonicalizing($this->ids('E2', 'E4', 'E6', 'E7'), $this->rowIds($this->kdyList(['min' => '500', 'max' => '4200'])));
        $this->assertSame($this->ids('E5'), $this->rowIds($this->kdyList(['min' => '80000'])));
        $this->assertSame([], array_intersect($this->ids('E9'), $this->rowIds($this->kdyList(['max' => '9999999']))));
        foreach (['4200.001', '-5', '1e3', '1,000', '0', 'abc'] as $bad) {
            $this->kdyList(['min' => $bad])->assertStatus(422)->assertJsonValidationErrors('min');
        }
        $this->kdyList(['min' => '5000', 'max' => '100'])->assertStatus(422)->assertJsonPath('errors.max.0', 'Amount to must be at least Amount from.');
        $this->kdyList(['max' => '0'])->assertStatus(422)->assertJsonValidationErrors('max');
        $this->assertSame($this->ids('E3'), $this->rowIds($this->kdyList(['min' => '50.00', 'max' => '50'])));
    }

    public function test_ac11_text_search_escapes_wildcards(): void
    {
        $this->assertSame($this->ids('E2', 'E1'), $this->rowIds($this->kdyList(['q' => 'CEB'])));
        $this->assertSame($this->ids('E3'), $this->rowIds($this->kdyList(['q' => '50%'])));
        $this->assertSame($this->ids('E4'), $this->rowIds($this->kdyList(['q' => 'Rent_Oct'])));
        $this->assertSame([], $this->rowIds($this->kdyList(['q' => 'Rent_Oc_'])));
        $this->assertSame($this->ids('E4'), $this->rowIds($this->kdyList(['q' => 'duplicate'])));
        $this->assertSame($this->ids('E1'), $this->rowIds($this->kdyList(['q' => $this->e['E1']->ref_no])));
        $this->assertCount(8, $this->rowIds($this->kdyList(['q' => '   '])));
        $this->kdyList(['q' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_ac12_filters_are_anded(): void
    {
        $this->assertSame($this->ids('E2'), $this->rowIds($this->kdyList(['type' => 'expense', 'min' => '100', 'q' => 'CEB'])));
    }

    public function test_ac13_files_filter_keeps_income_and_expense_only(): void
    {
        foreach (['none', 'with', 'removed'] as $v) {
            $ids = $this->rowIds($this->kdyList(['files' => $v]));
            $this->assertSame([], array_intersect($ids, $this->ids('E5', 'E6', 'E7', 'E9')), $v);
        }
        $this->assertSame($this->ids('E4', 'E3', 'E2', 'E1'), $this->rowIds($this->kdyList(['files' => 'none'])));
    }

    public function test_ac40_search_and_export_change_no_data(): void
    {
        $balances = Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all();
        $lines = LedgerEntry::count();
        $this->kdyList(['q' => 'CEB', 'min' => '1'])->assertOk();
        $this->exportAs($this->nimal, $this->kdy->id)->assertOk();
        $this->assertSame($balances, Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all());
        $this->assertSame($lines, LedgerEntry::count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'report.exported')->count());
    }

    /** Reviewer M2: a list param sent as an array is a 422, never a 500, on the list, creators and export. */
    public function test_m2_array_params_are_rejected_with_422(): void
    {
        foreach (['type', 'status'] as $key) {
            $this->kdyList([$key => ['income']])->assertStatus(422)->assertJsonValidationErrors($key);
            $this->exportAs($this->nimal, $this->kdy->id, [$key => ['void']])->assertStatus(422)->assertJsonValidationErrors($key);
            $this->actingAs($this->nimal)->withHeader('X-Branch-Id', (string) $this->kdy->id)
                ->getJson('/api/v1/transactions/creators?'.http_build_query([$key => ['x']]))->assertOk();
        }
        foreach (['min', 'q', 'from', 'account_id'] as $key) {
            $this->kdyList([$key => ['1']])->assertStatus(422)->assertJsonValidationErrors($key);
        }
    }

    /** AC 25 / QA F5: the Income & Expense drill-down link works in branch mode (no branches key, category_name ignored). */
    public function test_ac25_reports_drill_down_in_branch_mode(): void
    {
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'income,reversal', 'category_id' => $this->walkIn->id,
            'category_only' => 1, 'category_name' => 'Retail › Walk-in'];
        $this->assertSame($this->ids('E1'), $this->rowIds($this->kdyList($q)));
    }
}
