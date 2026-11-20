<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Worked example of docs/specs/reports.md section 7 (E-1..E-4), posted through LedgerService.
 * Used by the report tests and by QA / the accountant:  php artisan db:seed --class=ReportExampleSeeder
 * Needs DatabaseSeeder first (Main branch, Main Account, Super Admin, categories).
 */
class ReportExampleSeeder extends Seeder
{
    /** @var array<int, Transaction> entries (1)..(13) of the example, plus 'rev10', 'rev11', 'rev13' */
    public static array $txn = [];

    public function run(LedgerService $ledger): void
    {
        // Demo/test data posts real ledger entries: never in production.
        if (app()->environment('production')) {
            throw new RuntimeException('ReportExampleSeeder must not run in production.');
        }
        $admin = User::where('is_super_admin', true)->firstOrFail();
        $previous = Auth::user();
        Auth::setUser($admin);

        $main = Branch::main();
        $mainBank = Account::withoutGlobalScopes()->where('branch_id', $main->id)->where('is_main_account', true)->firstOrFail();
        $kdy = $this->branch('KDY', 'Kandy');
        $gal = $this->branch('GAL', 'Galle');
        $kdyCash = $this->account($kdy, 'Cash', 'cash');
        $kdyBank = $this->account($kdy, 'Bank', 'bank');
        $galCash = $this->account($gal, 'Cash', 'cash');

        $sales = $this->category('income', 'Sales');
        $retail = $this->category('income', 'Retail', $sales);
        $service = $this->category('income', 'Service income');
        $utilities = $this->category('expense', 'Utilities');
        $electricity = $this->category('expense', 'Electricity', $utilities);
        $water = $this->category('expense', 'Water', $utilities);
        $rent = $this->category('expense', 'Rent');

        $inc = fn (Account $a, Category $c, string $date, string $amt, ?string $payee = null) => $ledger->incomeOrExpense(Transaction::INCOME,
            ['account_id' => $a->id, 'category_id' => $c->id, 'txn_date' => $date, 'amount' => $amt, 'payee_payer' => $payee]);
        $exp = fn (Account $a, Category $c, string $date, string $amt, ?string $payee = null) => $ledger->incomeOrExpense(Transaction::EXPENSE,
            ['account_id' => $a->id, 'category_id' => $c->id, 'txn_date' => $date, 'amount' => $amt, 'payee_payer' => $payee]);

        // Before October 2026
        $t = ['cap' => $ledger->capital($mainBank->id, '1000000.00', '2026-09-01', 'Owner capital')];
        $t['opn_kdy'] = $ledger->transfer($mainBank->id, $kdyCash->id, '200000.00', '2026-09-02', 'Opening balance KDY', Transaction::OPENING_ALLOCATION);
        $t['opn_gal'] = $ledger->transfer($mainBank->id, $galCash->id, '100000.00', '2026-09-02', 'Opening balance GAL', Transaction::OPENING_ALLOCATION);
        $t['sep_rent'] = $exp($kdyCash, $rent, '2026-09-20', '30000.00', 'Landlord');

        // October 2026
        $t[1] = $inc($kdyCash, $retail, '2026-10-01', '50000.00');
        $t[2] = $inc($kdyCash, $service, '2026-10-02', '12500.00');
        $t[3] = $exp($kdyCash, $electricity, '2026-10-03', '8200.00', 'CEB');
        $t[4] = $exp($galCash, $water, '2026-10-03', '1800.00', 'Water Board');
        $t[5] = $inc($galCash, $retail, '2026-10-05', '25000.00');
        $t[6] = $ledger->transfer($mainBank->id, $kdyCash->id, '100000.00', '2026-10-06', 'Top-up');
        $t[7] = $ledger->transfer($kdyCash->id, $galCash->id, '30000.00', '2026-10-07', 'Support for Galle');
        $t[8] = $ledger->transfer($kdyCash->id, $kdyBank->id, '40000.00', '2026-10-08', 'Banking');
        $t[9] = $ledger->transfer($galCash->id, $mainBank->id, '20000.00', '2026-10-10', 'Return to Main');
        $t[10] = $exp($kdyCash, $electricity, '2026-10-12', '5000.00', 'CEB');
        $t['rev10'] = $ledger->reverse($t[10], '2026-10-14', null);
        $t[11] = $ledger->transfer($galCash->id, $kdyCash->id, '10000.00', '2026-10-15', 'Wrong transfer');
        $t['rev11'] = $ledger->reverse($t[11], '2026-10-16', null);
        $t[12] = $exp($galCash, $rent, '2026-10-20', '15000.00', 'Landlord');
        $t[12] = $ledger->void($t[12], 'Duplicate');
        $t[13] = $inc($galCash, $retail, '2026-10-30', '4000.00');
        $t['rev13'] = $ledger->reverse($t[13], '2026-11-02', null);

        self::$txn = $t;
        $previous ? Auth::setUser($previous) : Auth::forgetUser();
    }

    private function branch(string $code, string $name): Branch
    {
        return Branch::firstOrCreate(['code' => $code], ['name' => $name, 'status' => 'active']);
    }

    private function account(Branch $b, string $name, string $type): Account
    {
        return Account::withoutGlobalScopes()->firstOrCreate(['branch_id' => $b->id, 'name' => $name], ['type' => $type]);
    }

    private function category(string $type, string $name, ?Category $parent = null): Category
    {
        return Category::firstOrCreate(['parent_id' => $parent?->id, 'branch_id' => null, 'name' => $name], ['type' => $type]);
    }
}
