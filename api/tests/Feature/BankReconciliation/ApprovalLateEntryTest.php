<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;

/**
 * Review finding 10 (follow-up, sign-off row 10): the approve dialogs of transfer requests (AG-051) and expense requests
 * (AG-064) post on today's Colombo date (R6). The widgets and the transaction detail behind them carry
 * `last_reconciled_date` on the bank accounts involved (both sides of a transfer), with the same access rule as
 * GET /accounts: a Super Admin every branch; anyone else only the active branch's accounts with `account.view`.
 * KDY Bank is reconciled to 2026-10-31 (S1); MAIN's bank account has never been reconciled.
 */
class ApprovalLateEntryTest extends BankReconciliationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->statementOne();
    }

    private function transferRequest(): Transaction
    {
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => '100000.00'])->assertOk();
        $id = $this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyBank->id,
            'to_account_id' => $this->mainAcc->id, 'amount' => '150000.00', 'txn_date' => '2026-11-18', 'note' => 'Return to Main'])
            ->assertCreated()->assertJsonPath('approval_status', 'pending')->json('id');

        return Transaction::withoutGlobalScopes()->findOrFail($id);
    }

    private function expenseRequest(): Transaction
    {
        $this->sa()->putJson("/api/v1/branches/{$this->kdy->id}", ['expense_approval_threshold' => '50000.00'])->assertOk();
        $id = $this->as($this->csh, $this->kdy)->postJson('/api/v1/expenses', ['account_id' => $this->kdyBank->id,
            'category_id' => $this->category('expense')->id, 'txn_date' => '2026-11-18', 'amount' => '75000.00', 'payee_payer' => 'Supplier',
            'payment_method' => 'cheque', 'reference' => '001240'])->assertCreated()->assertJsonPath('approval_status', 'pending')->json('id');

        return Transaction::withoutGlobalScopes()->findOrFail($id);
    }

    private function row(array $widget, Transaction $t): array
    {
        return collect($widget['to_approve']['data'])->firstWhere('id', $t->id);
    }

    public function test_transfer_widget_and_detail_carry_the_dates_by_access(): void
    {
        $t = $this->transferRequest();

        // Super Admin: both sides, every branch (MAIN never reconciled: present, null).
        $sa = $this->row($this->sa()->getJson('/api/v1/transfer-approvals')->assertOk()->json(), $t);
        $this->assertSame('2026-10-31', $sa['from']['last_reconciled_date']);
        $this->assertArrayHasKey('last_reconciled_date', $sa['to']);
        $this->assertNull($sa['to']['last_reconciled_date']);
        $detail = $this->sa()->getJson("/api/v1/transactions/{$t->id}")->assertOk();
        $detail->assertJsonPath('from_account.last_reconciled_date', '2026-10-31');
        $this->assertArrayHasKey('last_reconciled_date', $detail->json('to_account'));

        // MAIN's Branch Admin (the approver, active branch MAIN): only MAIN's side, KDY's reconciliation is not revealed.
        $mainBa = $this->userIn($this->main, 'Branch Admin');
        $row = $this->row($this->as($mainBa, $this->main)->getJson('/api/v1/transfer-approvals')->assertOk()->json(), $t);
        $this->assertArrayNotHasKey('last_reconciled_date', $row['from']);
        $this->assertArrayHasKey('last_reconciled_date', $row['to']);
        $detail = $this->as($mainBa, $this->main)->getJson("/api/v1/transactions/{$t->id}")->assertOk();
        $this->assertArrayNotHasKey('last_reconciled_date', $detail->json('from_account'));
        $this->assertArrayHasKey('last_reconciled_date', $detail->json('to_account'));
        $this->assertArrayNotHasKey('type', $row['from']);

        // The KDY requester's own row and detail: KDY's side only.
        $mine = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/transfer-approvals')->assertOk()->json('my_requests.data'))->firstWhere('id', $t->id);
        $this->assertSame('2026-10-31', $mine['from']['last_reconciled_date']);
        $this->assertArrayNotHasKey('last_reconciled_date', $mine['to']);
    }

    public function test_expense_widget_and_detail_carry_the_date_by_access(): void
    {
        $t = $this->expenseRequest();

        $row = $this->row($this->as($this->ba, $this->kdy)->getJson('/api/v1/expense-approvals')->assertOk()->json(), $t);
        $this->assertTrue($row['can_approve']);
        $this->assertSame('2026-10-31', $row['account']['last_reconciled_date']);
        $this->as($this->ba, $this->kdy)->getJson("/api/v1/transactions/{$t->id}")->assertOk()
            ->assertJsonPath('can_approve', true)->assertJsonPath('account.last_reconciled_date', '2026-10-31');

        $sa = $this->row($this->sa()->getJson('/api/v1/expense-approvals')->assertOk()->json(), $t);
        $this->assertSame('2026-10-31', $sa['account']['last_reconciled_date']);
    }

    /** An approver without `account.view` gets no date, not even for the branch's own bank account. */
    public function test_expense_approver_without_account_view_gets_no_date(): void
    {
        $t = $this->expenseRequest();
        $role = Role::create(['name' => 'Expense approver', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions(['transaction.view', 'expense.approve']);
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($role);

        $row = $this->row($this->as($u, $this->kdy)->getJson('/api/v1/expense-approvals')->assertOk()->json(), $t);
        $this->assertTrue($row['can_approve']);
        $this->assertArrayNotHasKey('last_reconciled_date', $row['account']);
        $detail = $this->as($u, $this->kdy)->getJson("/api/v1/transactions/{$t->id}")->assertOk();
        $this->assertArrayNotHasKey('last_reconciled_date', $detail->json('account'));
    }

    /** A posted (not pending) transaction's detail is unchanged: no date. */
    public function test_posted_detail_has_no_date(): void
    {
        $detail = $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$this->e1->id}")->assertOk();
        $this->assertArrayNotHasKey('last_reconciled_date', $detail->json('account'));
    }

    /** Never blocking: with KDY Bank reconciled to today, approving still posts on today's date, as a late entry. */
    public function test_approval_on_a_reconciled_date_still_posts(): void
    {
        $this->complete('2026-11-20', '479500.00')->assertCreated();
        $t = $this->expenseRequest();
        $this->as($this->ba, $this->kdy)->getJson("/api/v1/transactions/{$t->id}")->assertJsonPath('account.last_reconciled_date', '2026-11-20');

        $this->as($this->ba, $this->kdy)->postJson("/api/v1/expense-approvals/{$t->id}/approve", ['comment' => 'Cheque issued, bill on file'])
            ->assertOk()->assertJsonPath('status', 'posted');
        $this->assertSame('2026-11-20', Transaction::withoutGlobalScopes()->find($t->id)->txn_date->format('Y-m-d'));
    }
}
