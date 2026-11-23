<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;

/**
 * Review finding 10 (follow-up, AC 42): the responses behind the allocation / top-up / fund-return, transfer, void and
 * reverse forms carry `last_reconciled_date` for the bank accounts the user may see: a Super Admin every branch, anyone
 * else only the active branch's accounts. Another branch's reconciliation is never revealed.
 */
class LateEntryWarningTest extends BankReconciliationTestCase
{
    private function galStatement(): void
    {
        $this->allocate($this->galBank, '200000.00', '2026-10-01');
        $top = Transaction::withoutGlobalScopes()->where('to_account_id', $this->galBank->id)->sole();
        $this->tick([[$top, '2026-10-15']], $this->gacc, [], $this->galBank)->assertOk();
        $this->complete('2026-10-15', '200000.00', $this->gacc, $this->galBank)->assertCreated();
    }

    public function test_ac42_destinations_carry_the_date_only_for_accounts_the_user_may_see(): void
    {
        $this->statementOne();
        $this->galStatement();

        $mine = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/accounts/destinations')->assertOk()->json())->keyBy('id');
        $this->assertSame('2026-10-31', $mine[$this->kdyBank->id]['last_reconciled_date']);
        $this->assertArrayNotHasKey('last_reconciled_date', $mine[$this->galBank->id]);
        $this->assertArrayNotHasKey('last_reconciled_date', $mine[$this->kdyCash->id]);
        $this->assertArrayNotHasKey('current_balance', $mine[$this->galBank->id]);

        $all = collect($this->sa()->getJson('/api/v1/accounts/destinations')->assertOk()->json())->keyBy('id');
        $this->assertSame('2026-10-31', $all[$this->kdyBank->id]['last_reconciled_date']);
        $this->assertSame('2026-10-15', $all[$this->galBank->id]['last_reconciled_date']);
    }

    public function test_ac42_super_admin_account_list_for_the_allocation_form_has_every_branch(): void
    {
        $this->statementOne();
        $this->galStatement();
        $list = collect($this->sa()->getJson('/api/v1/accounts')->assertOk()->json())->keyBy('id');
        $this->assertSame('2026-10-31', $list[$this->kdyBank->id]['last_reconciled_date']);
        $this->assertSame('2026-10-15', $list[$this->galBank->id]['last_reconciled_date']);
        // Not reconciled yet: present, null.
        $this->assertSame('bank', $this->mainAcc->type);
        $this->assertNull($list[$this->mainAcc->id]['last_reconciled_date']);
    }

    public function test_ac42_transaction_list_rows_carry_the_date_for_the_void_and_reverse_dialogs(): void
    {
        $this->statementOne();
        $this->galStatement();
        $trf = $this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyBank->id,
            'to_account_id' => $this->galBank->id, 'amount' => '100.00', 'txn_date' => '2026-11-05', 'note' => 'Stock money'])->assertCreated()->json('id');

        $rows = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/transactions?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertArrayNotHasKey('type', $rows[$this->e1->id]['account']);
        $this->assertArrayNotHasKey('type', $rows[$trf]['to_account']);
        $this->assertSame('2026-10-31', $rows[$this->e1->id]['account']['last_reconciled_date']);
        $this->assertSame('2026-10-31', $rows[$trf]['from_account']['last_reconciled_date']);
        // GAL's side of the transfer: GAL's reconciliation is not shown to a KDY user.
        $this->assertArrayNotHasKey('last_reconciled_date', $rows[$trf]['to_account']);

        $sa = collect($this->sa()->getJson('/api/v1/transactions?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('2026-10-31', $sa[$trf]['from_account']['last_reconciled_date']);
        $this->assertSame('2026-10-15', $sa[$trf]['to_account']['last_reconciled_date']);

        // GAL's accountant sees the incoming row with GAL's date only.
        $gal = collect($this->as($this->gacc, $this->gal)->getJson('/api/v1/transactions?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('2026-10-15', $gal[$trf]['to_account']['last_reconciled_date']);
        $this->assertArrayNotHasKey('last_reconciled_date', $gal[$trf]['from_account']);
    }

    /** Never blocking: a posting dated on or before the statement still posts (Q5). */
    public function test_ac42_a_late_posting_is_not_refused(): void
    {
        $this->statementOne();
        $this->allocate($this->kdyBank, '1000.00', '2026-10-20');
        $this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyBank->id,
            'to_account_id' => $this->kdyCash->id, 'amount' => '100.00', 'txn_date' => '2026-10-31', 'note' => 'Petty cash'])->assertCreated();
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->e2->id}/void", ['reason' => 'Recorded twice'])->assertOk();
    }

    /** Review SHOULD 1: without `account.view` there is no date, not even for the branch's own bank account. */
    public function test_ac42_no_account_view_no_date_even_for_the_own_branch(): void
    {
        $this->statementOne();
        $role = Role::create(['name' => 'Transfer clerk', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions(['transaction.view', 'transfer.create']);
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($role);

        $dest = collect($this->as($u, $this->kdy)->getJson('/api/v1/accounts/destinations')->assertOk()->json())->keyBy('id');
        $this->assertArrayNotHasKey('last_reconciled_date', $dest[$this->kdyBank->id]);
        $rows = collect($this->as($u, $this->kdy)->getJson('/api/v1/transactions?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertArrayNotHasKey('last_reconciled_date', $rows[$this->e1->id]['account']);
        $this->assertArrayNotHasKey('last_reconciled_date', $rows[$this->top->id]['to_account']);
        // The Accounts list itself stays closed to them.
        $this->as($u, $this->kdy)->getJson('/api/v1/accounts')->assertForbidden();
    }

    /** A Super Admin with one branch selected still gets every branch's date on the destinations list. */
    public function test_ac42_super_admin_in_one_branch_gets_every_date_on_destinations(): void
    {
        $this->statementOne();
        $this->galStatement();
        $dest = collect($this->sa($this->kdy)->getJson('/api/v1/accounts/destinations')->assertOk()->json())->keyBy('id');
        $this->assertSame('2026-10-31', $dest[$this->kdyBank->id]['last_reconciled_date']);
        $this->assertSame('2026-10-15', $dest[$this->galBank->id]['last_reconciled_date']);
    }
}
