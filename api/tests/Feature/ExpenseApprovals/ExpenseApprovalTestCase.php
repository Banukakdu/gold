<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\DoubleEntry\DoubleEntryTestCase;

/**
 * docs/specs/expense-approvals.md section 8 (AG-064), on the controls setup (MAIN, KDY, GAL) plus a 100,000.00 top-up
 * of KDY Cash, so KDY Cash 200,000.00 and GAL Cash 50,000.00. "Today" 2026-11-20; KDY limit 50,000.00, GAL and MAIN
 * empty. Users: KDY Cashier ($csh, the requester), KDY Accountant ($acc, no expense.approve), KDY Branch Admin ($kdyBa),
 * KDY Branch Admin 2 ($kdyBa2), GAL Branch Admin ($ba), MAIN Branch Admin ($mainBa), Super Admin S1 ($admin).
 * E1 = KDY Cash, Utilities > Electricity, 75,000.00 dated 2026-11-18 by the KDY Cashier.
 */
abstract class ExpenseApprovalTestCase extends DoubleEntryTestCase
{
    protected User $kdyBa;

    protected User $kdyBa2;

    protected User $mainBa;

    protected Category $electricity;

    protected Category $water;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '100000.00', 'txn_date' => '2026-11-01', 'kind' => 'top_up'])->assertCreated();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->kdyBa = $this->userIn($this->kdy, 'Branch Admin');
        $this->kdyBa2 = $this->userIn($this->kdy, 'Branch Admin');
        $this->mainBa = $this->userIn($this->main, 'Branch Admin');
        $this->electricity = $this->cat('expense', 'Electricity');
        $this->water = $this->cat('expense', 'Water');
        $this->limit($this->kdy, '50000.00');
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
    }

    protected function limit(Branch $b, ?string $value): TestResponse
    {
        return $this->sa()->putJson("/api/v1/branches/{$b->id}", ['expense_approval_threshold' => $value])->assertOk();
    }

    /** An expense on $acc (default KDY Cash) by $by (default the KDY Cashier) in the account's branch. */
    protected function record(string $amount = '75000.00', string $date = '2026-11-18', ?User $by = null, ?Category $cat = null, ?Account $acc = null,
        Branch|string|null $branch = null): TestResponse
    {
        $acc ??= $this->kdyCash;

        return $this->as($by ?? $this->csh, $branch ?? Branch::find($acc->branch_id))->postJson('/api/v1/expenses', [
            'account_id' => $acc->id, 'category_id' => ($cat ?? $this->electricity)->id, 'txn_date' => $date, 'amount' => $amount,
            'payee_payer' => 'Ceylon Electricity Board', 'reference' => 'BILL-7781', 'description' => 'November bill', 'payment_method' => 'cash']);
    }

    protected function e1(string $amount = '75000.00', string $date = '2026-11-18', ?User $by = null): Transaction
    {
        return $this->txn($this->record($amount, $date, $by)->assertCreated()->assertJsonPath('status', 'pending')->json('id'));
    }

    protected function approve(int $id, ?User $by = null, Branch|string|null $branch = null, array $body = ['comment' => 'Bill checked, cash available']): TestResponse
    {
        return $this->as($by ?? $this->kdyBa, $branch ?? $this->kdy)->postJson("/api/v1/expense-approvals/{$id}/approve", $body);
    }

    protected function reject(int $id, ?string $comment = 'Bill not attached, please add it', ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->kdyBa, $branch ?? $this->kdy)->postJson("/api/v1/expense-approvals/{$id}/reject",
            array_filter(['comment' => $comment], fn ($v) => $v !== null));
    }

    protected function cancel(int $id, ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->csh, $branch ?? $this->kdy)->postJson("/api/v1/expense-approvals/{$id}/cancel", []);
    }

    protected function widget(User $by, Branch|string $branch): array
    {
        return $this->as($by, $branch)->getJson('/api/v1/expense-approvals')->assertOk()->json();
    }

    /** Give a role of $branch (by name) the key, as the Roles screen would (AC 13: an Accountant given expense.approve). */
    protected function grant(Branch $b, string $role, string $permission = 'expense.approve'): void
    {
        $this->branchRole($b, $role)->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Everything a refused decision must leave unchanged: ledger, balances, approval columns and the audit log. */
    protected function state(): array
    {
        return [
            'entries' => LedgerEntry::count(),
            'balances' => Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all(),
            'transactions' => DB::table('transactions')->orderBy('id')
                ->get(['id', 'status', 'txn_date', 'approval_status', 'approval_note', 'decided_by', 'decided_at', 'approved_by'])->map(fn ($r) => (array) $r)->all(),
            'audit' => AuditLog::count(),
        ];
    }

    protected function audits(Transaction $t, string $event)
    {
        return AuditLog::where('event', $event)->where('auditable_type', Transaction::class)->where('auditable_id', $t->id)->orderBy('id')->get();
    }

    protected function assertVerifies(): void
    {
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    /** JSON values with their keys sorted (MySQL does not keep JSON key order). */
    protected static function sorted(?array $v): ?array
    {
        if ($v !== null) {
            ksort($v);
        }

        return $v;
    }
}
