<?php

namespace Tests\Feature\YearEnd;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
use App\Models\YearEndClose;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Reports\ReportTestCase;

/**
 * docs/specs/settings.md section 7, the Y-dataset: the reports E-dataset (KDY +24,300.00, GAL Retail 25,000.00 and
 * Water 1,800.00) plus GAL Rent 30,000.00 (2027-01-10) and MAIN Rent 10,000.00 (2027-02-15). "Today" 2027-04-10.
 */
abstract class YearEndTestCase extends ReportTestCase
{
    protected Account $mainBank;

    protected function setUp(): void
    {
        Carbon::setTestNow('2027-04-10 10:00:00');
        parent::setUp();
        Notification::fake();
        $this->mainBank = $this->acc($this->main, 'Main Account');
        $this->expense($this->gal, $this->acc($this->gal, 'Cash'), 'Rent', '2027-01-10', '30000.00');
        $this->expense($this->main, $this->mainBank, 'Rent', '2027-02-15', '10000.00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function sa(Branch|string $branch = 'all')
    {
        return $this->flushHeaders()->actingAs($this->admin)->withHeader('X-Branch-Id', $branch instanceof Branch ? (string) $branch->id : $branch);
    }

    protected function as(User $u, Branch $b)
    {
        return $this->flushHeaders()->actingAs($u)->withHeader('X-Branch-Id', (string) $b->id);
    }

    protected function expense(Branch $b, Account $acc, string $category, string $date, string $amount): array
    {
        return $this->sa($b)->postJson('/api/v1/expenses', ['account_id' => $acc->id, 'category_id' => Category::where('name', $category)->value('id'),
            'txn_date' => $date, 'amount' => $amount])->assertCreated()->json();
    }

    protected function lock(string $period, ?Branch $b = null): TestResponse
    {
        return $this->sa()->postJson('/api/v1/period-locks', ['period' => $period, 'branch_id' => $b?->id])->assertCreated();
    }

    protected function close(int $year = 2026, ?string $confirm = null): TestResponse
    {
        $confirm ??= sprintf('%d-%02d', $year, ($year + 1) % 100);

        return $this->sa()->postJson("/api/v1/fiscal-years/{$year}/close", ['confirm' => $confirm]);
    }

    protected function reopen(int $year = 2026, string $reason = 'Late supplier bill for March'): TestResponse
    {
        return $this->sa()->postJson("/api/v1/fiscal-years/{$year}/reopen", ['reason' => $reason]);
    }

    /** AC 24 / 25: March 2027 locked for all branches (one row), then the close. */
    protected function closeY(): TestResponse
    {
        $this->lock('2027-03');

        return $this->close()->assertCreated();
    }

    protected function years(): array
    {
        return collect($this->sa()->getJson('/api/v1/fiscal-years')->assertOk()->json('data'))->keyBy('start_year')->all();
    }

    protected function tb(array $q, string|int $branch = 'all'): array
    {
        return $this->sa($branch)->getJson('/api/v1/reports/trial-balance?'.http_build_query($q))->assertOk()->json();
    }

    protected function closeTxns(): Collection
    {
        return Transaction::withoutGlobalScopes()->where('type', Transaction::YEAR_END_CLOSE)->orderBy('id')->get();
    }

    protected function retained(): Account
    {
        return Account::withoutGlobalScopes()->where('type', 'retained_earnings')->sole();
    }

    protected function ho(Branch $b): Account
    {
        return Account::withoutGlobalScopes()->where('branch_id', $b->id)->where('type', 'head_office')->sole();
    }

    protected function bca(Branch $b): Account
    {
        return Account::withoutGlobalScopes()->where('branch_id', $this->main->id)->where('type', 'branch_current')->where('counterparty_branch_id', $b->id)->sole();
    }

    /** Ledger balance (debit - credit) in cents. */
    protected function ledger(Account $a): int
    {
        return Money::toCents($a->ledgerBalance());
    }

    /** "BRANCH Dr|Cr <account> <amount>" for every line of the closing transactions. */
    protected function closingLines(): array
    {
        return LedgerEntry::with(['account', 'branch'])->whereIn('transaction_id', $this->closeTxns()->pluck('id'))->whereNull('contra_of_id')->orderBy('id')->get()
            ->map(fn ($l) => $l->branch->code.' '.(Money::cmp($l->debit, 0) > 0 ? 'Dr '.$l->account->name.' '.$l->debit : 'Cr '.$l->account->name.' '.$l->credit))->all();
    }

    protected function balances(): array
    {
        return Account::withoutGlobalScopes()->orderBy('id')->get()->mapWithKeys(fn ($a) => [$a->id => [$a->current_balance, $a->ledgerBalance()]])->all();
    }

    protected function globalLock(string $period): ?PeriodLock
    {
        return PeriodLock::active()->whereNull('branch_id')->where('period', $period)->first();
    }

    protected function activeClose(): ?YearEndClose
    {
        return YearEndClose::active()->first();
    }

    /** A raw (bypassing every control) transaction row; returns its id. */
    protected function rawTxn(Branch $b, string $date, string $type = 'income'): int
    {
        return DB::table('transactions')->insertGetId(['branch_id' => $b->id, 'type' => $type, 'txn_date' => $date, 'amount' => '100.00',
            'status' => 'posted', 'created_by' => $this->admin->id, 'ref_no' => 'TEST-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function rawLine(int $txn, Account $a, string $date, string $debit, string $credit, string $origin = 'posting'): int
    {
        return DB::table('ledger_entries')->insertGetId(['transaction_id' => $txn, 'account_id' => $a->id, 'branch_id' => $a->branch_id, 'entry_date' => $date,
            'debit' => $debit, 'credit' => $credit, 'origin' => $origin, 'created_at' => now(), 'updated_at' => now()]);
    }
}
