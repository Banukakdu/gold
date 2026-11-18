<?php

namespace Tests\Feature\Controls;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AccountingTestCase;

/**
 * Setup of docs/specs/controls.md section 7: branches MAIN, KDY, GAL; KDY Cash 100,000.00 on 2026-11-01
 * (opening allocation dated 2026-10-01); users SA, ACC (Accountant KDY), VW (Viewer KDY), CSH (Cashier KDY),
 * BA (Branch Admin GAL). "Today" is fixed at 2026-11-18.
 */
abstract class ControlsTestCase extends AccountingTestCase
{
    protected Branch $kdy;

    protected Branch $gal;

    protected Account $kdyCash;

    protected Account $galCash;

    protected User $acc;

    protected User $vw;

    protected User $csh;

    protected User $ba;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');
        Notification::fake();
        $this->kdy = $this->makeBranch('KDY');
        $this->gal = $this->makeBranch('GAL');
        $this->kdyCash = $this->cashOf($this->kdy);
        $this->galCash = $this->cashOf($this->gal);

        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '1000000.00', 'txn_date' => '2026-09-01'])->assertCreated();
        foreach ([[$this->kdyCash, '100000.00'], [$this->galCash, '50000.00']] as [$to, $amount]) {
            $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $to->id,
                'amount' => $amount, 'txn_date' => '2026-10-01', 'kind' => 'opening'])->assertCreated();
        }

        $this->acc = $this->userIn($this->kdy, 'Accountant');
        $this->vw = $this->userIn($this->kdy, 'Viewer');
        $this->csh = $this->userIn($this->kdy, 'Cashier');
        $this->ba = $this->userIn($this->gal, 'Branch Admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Act as a user in a branch (Branch or 'all'). */
    protected function as(User $u, Branch|string $branch)
    {
        return $this->flushHeaders()->actingAs($u)->withHeader('X-Branch-Id', $branch instanceof Branch ? (string) $branch->id : $branch);
    }

    protected function sa(Branch|string $branch = 'all')
    {
        return $this->as($this->admin, $branch);
    }

    protected function accKdy()
    {
        return $this->as($this->acc, $this->kdy);
    }

    protected function expense(string $date, string $amount, ?User $by = null, ?Account $acc = null): array
    {
        $acc ??= $this->kdyCash;
        $branch = Branch::find($acc->branch_id);

        return $this->as($by ?? $this->acc, $branch)->postJson('/api/v1/expenses', ['account_id' => $acc->id,
            'category_id' => $this->category('expense')->id, 'txn_date' => $date, 'amount' => $amount, 'payee_payer' => 'Supplier'])->assertCreated()->json();
    }

    protected function income(string $date, string $amount, ?User $by = null): array
    {
        return $this->as($by ?? $this->acc, $this->kdy)->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id,
            'category_id' => $this->category('income')->id, 'txn_date' => $date, 'amount' => $amount])->assertCreated()->json();
    }

    protected function lock(string $period, ?Branch $branch): TestResponse
    {
        return $this->sa()->postJson('/api/v1/period-locks', ['period' => $period, 'branch_id' => $branch?->id]);
    }

    protected function unlock(PeriodLock|int $lock, string $reason = 'Late supplier bill'): TestResponse
    {
        return $this->sa()->postJson('/api/v1/period-locks/'.($lock instanceof PeriodLock ? $lock->id : $lock).'/unlock', ['reason' => $reason]);
    }

    protected function activeLock(string $period, ?Branch $branch): PeriodLock
    {
        return PeriodLock::active()->where('period', $period)->where('branch_id', $branch?->id)->firstOrFail();
    }

    /** Everything that a refused posting must leave unchanged. */
    protected function snapshot(): array
    {
        return [
            'entries' => LedgerEntry::count(),
            'transactions' => DB::table('transactions')->orderBy('id')->get(['id', 'status', 'category_id', 'payee_payer'])->map(fn ($r) => (array) $r)->all(),
            'balances' => Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all(),
        ];
    }

    protected function txn(int $id): Transaction
    {
        return Transaction::withoutGlobalScopes()->findOrFail($id);
    }

    protected function inactiveCategory(string $type): Category
    {
        return Category::create(['type' => $type, 'name' => 'Old '.$type, 'is_active' => false]);
    }
}
