<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\Role;

/** AC 20, 21 and S2: ageing buckets, order, All mode and the 50-row limit of the widget. */
class WidgetTest extends TransferReceiptTestCase
{
    public function test_ac20_buckets_and_oldest_first(): void
    {
        $ids = [];
        foreach (['2026-11-10' => '400.00', '2026-11-15' => '300.00', '2026-11-17' => '200.00', '2026-11-20' => '100.00'] as $date => $amount) {
            $ids[$date] = $this->t1($amount, $date)->id;
        }
        $w = $this->widget($this->galCsh, $this->gal);
        $this->assertSame(array_values($ids), array_column($w['incoming']['data'], 'id'), 'oldest first');
        $this->assertSame([10, 5, 3, 0], array_column($w['incoming']['data'], 'age_days'));
        $this->assertSame([
            ['key' => '0_1', 'count' => 1, 'amount' => '100.00'],
            ['key' => '2_3', 'count' => 1, 'amount' => '200.00'],
            ['key' => '4_7', 'count' => 1, 'amount' => '300.00'],
            ['key' => '8_plus', 'count' => 1, 'amount' => '400.00'],
        ], $w['summary']['buckets']);
        $this->assertSame([4, '1000.00'], [$w['summary']['count'], $w['summary']['amount']]);
        $this->assertSame(4, $w['incoming']['total']);
    }

    public function test_ac21_all_mode_lists_every_branch_and_no_outgoing(): void
    {
        $t1 = $this->t1();
        $this->dispute($t1->id)->assertOk();
        $back = $this->as($this->galAcc, $this->gal)->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '50.00', 'txn_date' => '2026-11-19', 'note' => 'Change'])->assertCreated()->json('id');
        $top = $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '75.00', 'txn_date' => '2026-11-20', 'kind' => 'top_up'])->assertCreated()->json('id');

        $w = $this->widget($this->admin, 'all');
        $this->assertSame([$t1->id, $back, $top], array_column($w['incoming']['data'], 'id'));
        $this->assertSame(['GAL', 'KDY', 'KDY'], array_column(array_column($w['incoming']['data'], 'to'), 'branch_code'));
        $this->assertSame(['data' => [], 'total' => 0], $w['outgoing_disputed']);
        $this->assertSame([true, true, false], array_column($w['incoming']['data'], 'can_acknowledge'), 'not the own top-up');

        // Branch mode KDY: the one sent back to KDY and the top-up; T1's dispute as outgoing.
        $kdy = $this->widget($this->acc, $this->kdy);
        $this->assertSame([$back, $top], array_column($kdy['incoming']['data'], 'id'));
        $this->assertSame([$t1->id], array_column($kdy['outgoing_disputed']['data'], 'id'));
    }

    public function test_at_most_50_rows_with_the_total(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->t1('1.00');
        }
        $w = $this->widget($this->galCsh, $this->gal);
        $this->assertCount(50, $w['incoming']['data']);
        $this->assertSame(51, $w['incoming']['total']);
        $this->assertSame([51, '51.00'], [$w['summary']['count'], $w['summary']['amount']]);
    }

    public function test_needs_transaction_view(): void
    {
        $this->as($this->galCsh, $this->gal)->getJson('/api/v1/transfer-receipts')->assertOk();
        $role = Role::where('branch_id', $this->gal->id)->where('name', 'Cashier')->sole();
        $role->revokePermissionTo('transaction.view');
        $this->as($this->galCsh, $this->gal)->getJson('/api/v1/transfer-receipts')->assertForbidden();
    }
}
