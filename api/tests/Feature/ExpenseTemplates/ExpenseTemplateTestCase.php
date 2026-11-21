<?php

namespace Tests\Feature\ExpenseTemplates;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\RecurringTemplate;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ExpenseApprovals\ExpenseApprovalTestCase;

/**
 * docs/specs/recurring-expenses.md section 8 (AG-066), on the AG-064 setup: MAIN, KDY, GAL; KDY limit 50,000.00, GAL
 * none; today 2026-11-20; KDY Cash 200,000.00, GAL Cash 50,000.00, `expense_negative_balance = block`. Added here: KDY
 * Bank 400,000.00 (section 7), KDY Old Cash (inactive), the KDY-only sub-category Rent > Store room, the GAL-only
 * Rent > Godown and a GAL Accountant. Users: KDY Accountant ($acc), KDY Cashier ($csh), KDY Viewer ($vw), KDY Branch
 * Admin ($kdyBa), GAL Accountant ($galAcc), Super Admin S1 ($admin).
 */
abstract class ExpenseTemplateTestCase extends ExpenseApprovalTestCase
{
    protected Account $kdyBank;

    protected Account $kdyOldCash;

    protected Category $rentParent;

    protected Category $officeRent;

    protected Category $storeRoom;

    protected Category $godown;

    protected User $galAcc;

    protected function setUp(): void
    {
        parent::setUp();
        $id = $this->accKdy()->postJson('/api/v1/accounts', ['name' => 'Bank', 'type' => 'bank'])->assertCreated()->json('id');
        $this->kdyBank = Account::withoutGlobalScopes()->findOrFail($id);
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyBank->id,
            'amount' => '400000.00', 'txn_date' => '2026-11-01', 'kind' => 'top_up'])->assertCreated();
        $this->kdyOldCash = Account::withoutGlobalScopes()->create(['branch_id' => $this->kdy->id, 'name' => 'Old Cash', 'type' => 'cash', 'status' => 'inactive']);
        $this->rentParent = Category::where('type', 'expense')->where('name', 'Rent')->whereNull('parent_id')->firstOrFail();
        $this->officeRent = $this->cat('expense', 'Office rent');
        $this->storeRoom = Category::create(['parent_id' => $this->rentParent->id, 'branch_id' => $this->kdy->id, 'type' => 'expense', 'name' => 'Store room']);
        $this->godown = Category::create(['parent_id' => $this->rentParent->id, 'branch_id' => $this->gal->id, 'type' => 'expense', 'name' => 'Godown']);
        $this->galAcc = $this->userIn($this->gal, 'Accountant');
        $this->assertSame('400000.00', $this->bal($this->kdyBank));
    }

    protected function at(string $date): void
    {
        Carbon::setTestNow("{$date} 10:00:00");
    }

    /** T-RENT's fields (section 7), with $over. */
    protected function rentBody(array $over = []): array
    {
        return array_merge(['name' => 'Rent - Kandy office', 'account_id' => $this->kdyBank->id, 'category_id' => $this->officeRent->id,
            'amount' => '150000.00', 'payee_payer' => 'S. Fernando (landlord)', 'payment_method' => 'bank_transfer',
            'description' => 'Monthly office rent', 'frequency' => 'monthly'], $over);
    }

    /** T-ELEC: KDY Cash, Utilities > Electricity, no amount, monthly. */
    protected function elecBody(array $over = []): array
    {
        return array_merge(['name' => 'Electricity - Kandy', 'account_id' => $this->kdyCash->id, 'category_id' => $this->electricity->id,
            'amount' => null, 'payee_payer' => 'Ceylon Electricity Board', 'payment_method' => 'cash', 'description' => 'Monthly bill',
            'frequency' => 'monthly'], $over);
    }

    protected function createTpl(array $body, ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->postJson('/api/v1/expense-templates', $body);
    }

    protected function tRent(array $over = []): RecurringTemplate
    {
        return RecurringTemplate::withoutGlobalScopes()->findOrFail($this->createTpl($this->rentBody($over))->assertCreated()->json('id'));
    }

    protected function tElec(array $over = []): RecurringTemplate
    {
        return RecurringTemplate::withoutGlobalScopes()->findOrFail($this->createTpl($this->elecBody($over))->assertCreated()->json('id'));
    }

    /**
     * POST /expenses as the S2 form sends it after picking $tpl: the template's fields (R5), today's date, a reference,
     * plus $over. The server never fills fields itself.
     */
    protected function useTpl(RecurringTemplate $tpl, array $over = [], ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        $tpl->refresh();
        $body = array_merge([
            'template_id' => $tpl->id, 'account_id' => $tpl->account_id, 'category_id' => $tpl->category_id,
            'amount' => $tpl->amount_cents === null ? null : Money::fromCents($tpl->amount_cents),
            'payee_payer' => $tpl->payee_payer, 'payment_method' => $tpl->payment_method, 'description' => $tpl->description,
            'txn_date' => BusinessDate::today(), 'reference' => 'INV-11',
        ], $over);

        return $this->as($by ?? $this->csh, $branch ?? Branch::find($tpl->branch_id))->postJson('/api/v1/expenses', $body);
    }

    protected function templateAudits(RecurringTemplate $t, ?string $event = null)
    {
        return AuditLog::where('auditable_type', RecurringTemplate::class)->where('auditable_id', $t->id)
            ->when($event, fn ($q, $e) => $q->where('event', $e))->orderBy('id')->get();
    }

    protected function list(User $by, Branch|string $branch, array $query = []): array
    {
        return $this->as($by, $branch)->getJson('/api/v1/expense-templates'.($query ? '?'.http_build_query($query) : ''))->assertOk()->json();
    }

    protected function rowOf(array $list, RecurringTemplate $t): ?array
    {
        return collect($list['data'])->firstWhere('id', $t->id);
    }
}
