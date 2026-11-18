<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;

class LedgerFlowTest extends AccountingTestCase
{
    public function test_capital_opening_allocation_transfer_income_expense_and_reversal(): void
    {
        $kdy = $this->makeBranch('KDY');
        $gle = $this->makeBranch('GLE');
        $today = now()->toDateString();

        // Capital into Main, then opening balance to Kandy
        $this->asAdmin()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '1000000', 'txn_date' => $today])->assertCreated();
        $alloc = $this->asAdmin()->postJson('/api/v1/allocations', [
            'from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->cashOf($kdy)->id,
            'amount' => '250000.50', 'txn_date' => $today, 'kind' => 'opening',
        ])->assertCreated()->json();
        $this->assertSame('opening_allocation', $alloc['type']);
        $this->assertStringStartsWith('MAIN-OPN-', $alloc['ref_no']);
        $this->assertSame('749999.50', $this->balance($this->mainAcc));
        $this->assertSame('250000.50', $this->balance($this->cashOf($kdy)));

        // Kandy accountant transfers to Galle
        $acct = $this->userIn($kdy, 'Accountant');
        $trf = $this->actingAs($acct)->withHeader('X-Branch-Id', (string) $kdy->id)->postJson('/api/v1/transfers', [
            'from_account_id' => $this->cashOf($kdy)->id, 'to_account_id' => $this->cashOf($gle)->id,
            'amount' => '50000', 'txn_date' => $today, 'note' => 'Support for Galle',
        ])->assertCreated()->json();
        $this->assertSame('branch_transfer', $trf['type']);
        $this->assertSame('200000.50', $this->balance($this->cashOf($kdy)));
        $this->assertSame('50000.00', $this->balance($this->cashOf($gle)));

        // Income and expense in Kandy
        $h = ['X-Branch-Id' => (string) $kdy->id];
        $this->actingAs($acct)->withHeaders($h)->postJson('/api/v1/incomes', [
            'account_id' => $this->cashOf($kdy)->id, 'category_id' => $this->category('income')->id, 'txn_date' => $today, 'amount' => '1000',
        ])->assertCreated();
        $exp = $this->actingAs($acct)->withHeaders($h)->postJson('/api/v1/expenses', [
            'account_id' => $this->cashOf($kdy)->id, 'category_id' => $this->category('expense')->id, 'txn_date' => $today, 'amount' => '200.25', 'payee_payer' => 'CEB',
        ])->assertCreated()->json();
        $this->assertSame('200800.25', $this->balance($this->cashOf($kdy)));

        // Void the expense -> balance restored
        $this->actingAs($acct)->withHeaders($h)->postJson("/api/v1/transactions/{$exp['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->assertSame('201000.50', $this->balance($this->cashOf($kdy)));

        // Reverse the transfer
        $this->actingAs($acct)->withHeaders($h)->postJson("/api/v1/transactions/{$trf['id']}/reverse", ['reason' => 'Wrong branch'])->assertCreated();
        $this->assertSame('251000.50', $this->balance($this->cashOf($kdy)));
        $this->assertSame('0.00', $this->balance($this->cashOf($gle)));

        // Cached balances always equal the ledger
        foreach (Account::withoutGlobalScopes()->get() as $a) {
            $this->assertSame((float) $a->current_balance, (float) $a->ledgerBalance(), "Account {$a->id} cache drift");
        }
        $this->assertSame(0.0, (float) LedgerEntry::whereHas('transaction', fn ($q) => $q->withoutGlobalScopes()->where('type', '!=', 'capital')->whereIn('type', ['opening_allocation', 'branch_transfer', 'reversal']))->sum(\DB::raw('debit - credit')));
        $this->assertTrue(AuditLog::where('event', 'transaction.reversed')->exists());

        // Statement shows running balance
        $st = $this->actingAs($acct)->withHeaders($h)->getJson("/api/v1/accounts/{$this->cashOf($kdy)->id}/statement?from=$today&to=$today")->assertOk()->json();
        $this->assertSame('0.00', $st['opening_balance']);
        $this->assertSame('251000.50', $st['closing_balance']);

        // Dashboards and lists respond for consolidated and branch scope
        $all = $this->flushHeaders()->asAdmin()->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('1001000.00', $all['total_balance']);
        $this->assertSame('1000.00', $all['income']);
        $this->assertCount(3, $all['branch_balances']);
        $this->assertCount(12, $all['trend']);
        $br = $this->actingAs($acct)->withHeaders($h)->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('251000.50', $br['total_balance']);
        $this->actingAs($acct)->withHeaders($h)->getJson('/api/v1/transactions?type=income,expense&q=CEB')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($acct)->withHeaders($h)->getJson('/api/v1/categories?type=expense')->assertOk();
    }

    public function test_cannot_overdraw_or_post_into_locked_period(): void
    {
        $kdy = $this->makeBranch('KDY');
        $gle = $this->makeBranch('GLE');
        $this->asAdmin()->postJson('/api/v1/transfers', [
            'from_account_id' => $this->cashOf($kdy)->id, 'to_account_id' => $this->cashOf($gle)->id,
            'amount' => '10', 'txn_date' => now()->toDateString(), 'note' => 'x',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Insufficient balance in Cash (available 0.00).']);

        PeriodLock::create(['branch_id' => null, 'period' => now()->format('Y-m'), 'locked_by' => $this->admin->id]);
        $this->asAdmin()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '5', 'txn_date' => now()->toDateString()])
            ->assertStatus(422);
    }
}
