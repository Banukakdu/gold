<?php

namespace Tests\Feature\BankReconciliation;

use App\Models\Account;
use App\Models\BankReconciliationMark;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\AccountingTestCase;

/**
 * docs/specs/bank-reconciliation.md section 8 setup: branches MAIN, KDY, GAL; KDY Bank (bank, 11002), KDY Cash (cash),
 * GAL Bank (bank). Users SA, ACC (Accountant KDY), BA (Branch Admin KDY), VW (Viewer KDY), CSH (Cashier KDY), GACC
 * (Accountant GAL). Section 7 data on KDY Bank: TOP top-up 500,000.00 (2026-10-01, by SA), E1 expense 60,000.00
 * cheque 001234 (2026-10-05, by ACC), I1 income 40,000.00 (2026-10-20, by CSH), E2 expense 25,000.00 cheque 001235
 * (2026-10-30, by CSH), I2 income 15,000.00 (2026-10-31, by CSH). Today 2026-11-20.
 */
abstract class BankReconciliationTestCase extends AccountingTestCase
{
    protected Branch $kdy;

    protected Branch $gal;

    protected Account $kdyBank;

    protected Account $kdyCash;

    protected Account $galBank;

    protected User $acc;

    protected User $ba;

    protected User $vw;

    protected User $csh;

    protected User $gacc;

    protected Transaction $top;

    protected Transaction $e1;

    protected Transaction $i1;

    protected Transaction $e2;

    protected Transaction $i2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        Notification::fake();
        $this->kdy = $this->makeBranch('KDY');
        $this->gal = $this->makeBranch('GAL');
        $this->kdyCash = $this->cashOf($this->kdy);
        $this->kdyBank = $this->bankIn($this->kdy, 'KDY Bank');
        $this->galBank = $this->bankIn($this->gal, 'GAL Bank');

        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '1000000.00', 'txn_date' => '2026-09-01'])->assertCreated();
        $this->acc = $this->userIn($this->kdy, 'Accountant');
        $this->ba = $this->userIn($this->kdy, 'Branch Admin');
        $this->vw = $this->userIn($this->kdy, 'Viewer');
        $this->csh = $this->userIn($this->kdy, 'Cashier');
        $this->gacc = $this->userIn($this->gal, 'Accountant');

        $this->top = $this->allocate($this->kdyBank, '500000.00', '2026-10-01');
        $this->e1 = $this->expense($this->acc, '60000.00', '2026-10-05', '001234');
        $this->i1 = $this->income($this->csh, '40000.00', '2026-10-20');
        $this->e2 = $this->expense($this->csh, '25000.00', '2026-10-30', '001235');
        $this->i2 = $this->income($this->csh, '15000.00', '2026-10-31');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function as(User $u, Branch|string $branch)
    {
        return $this->flushHeaders()->actingAs($u)->withHeader('X-Branch-Id', $branch instanceof Branch ? (string) $branch->id : $branch);
    }

    protected function sa(Branch|string $branch = 'all')
    {
        return $this->as($this->admin, $branch);
    }

    protected function bankIn(Branch $b, string $name): Account
    {
        $id = $this->sa($b)->postJson('/api/v1/accounts', ['name' => $name, 'type' => 'bank', 'bank_name' => 'BOC'])->assertCreated()->json('id');

        return Account::withoutGlobalScopes()->findOrFail($id);
    }

    protected function allocate(Account $to, string $amount, string $date, string $kind = 'top_up'): Transaction
    {
        $id = $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $to->id,
            'amount' => $amount, 'txn_date' => $date, 'kind' => $kind])->assertCreated()->json('id');

        return Transaction::withoutGlobalScopes()->findOrFail($id);
    }

    protected function expense(User $by, string $amount, string $date, ?string $reference = null, ?Account $acc = null, string $payee = 'Supplier'): Transaction
    {
        $acc ??= $this->kdyBank;
        $id = $this->as($by, Branch::find($acc->branch_id))->postJson('/api/v1/expenses', ['account_id' => $acc->id, 'category_id' => $this->category('expense')->id,
            'txn_date' => $date, 'amount' => $amount, 'payee_payer' => $payee, 'reference' => $reference, 'payment_method' => 'cheque'])->assertCreated()->json('id');

        return Transaction::withoutGlobalScopes()->findOrFail($id);
    }

    protected function income(User $by, string $amount, string $date, ?Account $acc = null): Transaction
    {
        $acc ??= $this->kdyBank;
        $id = $this->as($by, Branch::find($acc->branch_id))->postJson('/api/v1/incomes', ['account_id' => $acc->id, 'category_id' => $this->category('income')->id,
            'txn_date' => $date, 'amount' => $amount, 'payee_payer' => 'Customer', 'payment_method' => 'bank_transfer'])->assertCreated()->json('id');

        return Transaction::withoutGlobalScopes()->findOrFail($id);
    }

    /** The line of a transaction on an account (default KDY Bank). */
    protected function line(Transaction $t, ?Account $acc = null): LedgerEntry
    {
        return LedgerEntry::where('transaction_id', $t->id)->where('account_id', ($acc ?? $this->kdyBank)->id)->whereNull('contra_of_id')->sole();
    }

    protected function url(?Account $acc = null, string $path = ''): string
    {
        return '/api/v1/accounts/'.($acc ?? $this->kdyBank)->id.'/reconciliation'.$path;
    }

    /** @param  array<int, array{0: Transaction, 1: string}>  $ticks  [transaction, cleared date] */
    protected function tick(array $ticks, ?User $by = null, array $unmark = [], ?Account $acc = null, Branch|string|null $branch = null): TestResponse
    {
        $acc ??= $this->kdyBank;

        return $this->as($by ?? $this->acc, $branch ?? Branch::find($acc->branch_id))->postJson($this->url($acc, '/marks'), array_filter([
            'mark' => array_map(fn ($t) => ['entry_id' => $this->line($t[0], $acc)->id, 'cleared_date' => $t[1]], $ticks),
            'unmark' => array_map(fn (Transaction $t) => $this->line($t, $acc)->id, $unmark),
        ]));
    }

    protected function complete(string $date, string $balance, ?User $by = null, ?Account $acc = null): TestResponse
    {
        $acc ??= $this->kdyBank;

        return $this->as($by ?? $this->acc, Branch::find($acc->branch_id))->postJson($this->url($acc, '/complete'),
            ['statement_date' => $date, 'statement_balance' => $balance]);
    }

    protected function undo(int $statementId, string $reason = 'Wrong closing balance typed', ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->postJson($this->url(null, "/statements/{$statementId}/undo"), ['reason' => $reason]);
    }

    protected function screen(array $params = ['statement_date' => '2026-10-31'], ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->getJson($this->url().'?'.http_build_query($params));
    }

    protected function report(array $params, ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->vw, $branch ?? $this->kdy)->getJson('/api/v1/reports/bank-reconciliation?'.http_build_query($params));
    }

    /** Section 7 steps 1 and 2: tick TOP, E1, I1 (cleared 2026-10-31), record E3 bank charge 500.00 by ACC, tick it, complete S1. */
    protected function statementOne(): BankStatement
    {
        $this->tick([[$this->top, '2026-10-31'], [$this->e1, '2026-10-31'], [$this->i1, '2026-10-31']])->assertOk();
        $e3 = $this->expense($this->acc, '500.00', '2026-10-31', null, null, 'Bank charges');
        $this->tick([[$e3, '2026-10-31']])->assertOk();
        $id = $this->complete('2026-10-31', '479500.00')->assertCreated()->json('id');

        return BankStatement::withoutGlobalScopes()->findOrFail($id);
    }

    /** Section 7 step 4: today 2026-12-01; I2 cleared 2026-11-02, E2 cleared 2026-11-03; complete S2 (2026-11-30, 469,500.00). */
    protected function statementTwo(): BankStatement
    {
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();
        $id = $this->complete('2026-11-30', '469500.00')->assertCreated()->json('id');

        return BankStatement::withoutGlobalScopes()->findOrFail($id);
    }

    protected function activeMark(Transaction $t, ?Account $acc = null): ?BankReconciliationMark
    {
        return BankReconciliationMark::withoutGlobalScopes()->active()->where('ledger_entry_id', $this->line($t, $acc)->id)->first();
    }

    /** Everything the reconciliation must never change (it posts nothing). */
    protected function books(): array
    {
        return [
            'entries' => DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'balances' => DB::table('accounts')->orderBy('id')->pluck('current_balance', 'id')->all(),
            'transactions' => DB::table('transactions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    protected function assertVerifies(): void
    {
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    /** Text of each PDF page (as Tests\Feature\Reports\ReportTestCase::pdfPages). */
    protected function pdfPages(string $pdf): array
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $pages = [];
        preg_match_all('/\/Length (\d+)[^>]*>>\s*stream\r?\n/', $pdf, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            $content = @gzuncompress(substr($pdf, $hit[0][1] + strlen($hit[0][0]), (int) $hit[1][0]));
            if ($content === false || str_contains($content, "\0") || ! str_contains($content, ' Tf')) {
                continue;
            }
            preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $content, $s);
            $pages[] = implode(' ', array_map(fn ($x) => stripcslashes(substr($x, 1, -1)), $s[0]));
        }

        return $pages;
    }

    protected function xlsx(TestResponse $response): Spreadsheet
    {
        return IOFactory::createReader('Xlsx')->load($response->getFile()->getPathname());
    }
}
