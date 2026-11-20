<?php

namespace Tests\Feature\Transactions;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\AccountingTestCase;

/**
 * Fixture of docs/specs/transaction-search.md section 7 (E1..E9), posted through LedgerService as the named users.
 * E9 (a KDY year-end close of FY 2025-26) is a stored row only: the list reads it, and closing a real year needs a
 * whole fiscal year of locked periods, which is not what these tests are about.
 */
abstract class TransactionSearchTestCase extends AccountingTestCase
{
    protected Branch $kdy;

    protected Branch $gal;

    protected Account $kdyCash;

    protected Account $galCash;

    protected Account $galBank;

    protected User $nimal;

    protected User $kamal;

    protected User $saman;

    protected Category $retail;

    protected Category $walkIn;

    protected Category $utilities;

    protected Category $electricity;

    protected Category $rent;

    /** @var array<string, Transaction> E1..E9 */
    protected array $e = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->kdy = $this->makeBranch('KDY');
        $this->kdy->update(['name' => 'Kandy']);
        $this->gal = $this->makeBranch('GAL');
        $this->gal->update(['name' => 'Galle']);
        $this->kdyCash = $this->cashOf($this->kdy);
        $this->galCash = $this->cashOf($this->gal);
        $this->galBank = Account::withoutGlobalScopes()->create(['branch_id' => $this->gal->id, 'name' => 'Bank', 'type' => 'bank']);

        $this->nimal = $this->named($this->userIn($this->kdy, 'Accountant'), 'Nimal Perera');
        $this->kamal = $this->named($this->userIn($this->kdy, 'Accountant'), 'Kamal Silva');
        $this->saman = $this->named($this->userIn($this->gal, 'Accountant'), 'Saman Fernando');

        $this->retail = Category::create(['type' => 'income', 'name' => 'Retail']);
        $this->walkIn = Category::create(['type' => 'income', 'name' => 'Walk-in', 'parent_id' => $this->retail->id]);
        $this->utilities = Category::create(['type' => 'expense', 'name' => 'Utilities']);
        $this->electricity = Category::create(['type' => 'expense', 'name' => 'Electricity', 'parent_id' => $this->utilities->id]);
        $this->rent = Category::create(['type' => 'expense', 'name' => 'Rent']);

        $ledger = app(LedgerService::class);
        $this->as($this->admin, fn () => $ledger->capital($this->mainAcc->id, '1000000.00', '2026-09-01', 'Owner capital'));
        $this->e['E5'] = $this->as($this->admin, fn () => $ledger->transfer($this->mainAcc->id, $this->kdyCash->id, '100000.00', '2026-10-01', 'Top-up'));
        $this->e['E1'] = $this->as($this->nimal, fn () => $ledger->incomeOrExpense(Transaction::INCOME, ['account_id' => $this->kdyCash->id,
            'category_id' => $this->walkIn->id, 'txn_date' => '2026-10-05', 'amount' => '12500.00', 'payee_payer' => 'CEB refund']));
        $this->e['E8'] = $this->as($this->saman, fn () => $ledger->incomeOrExpense(Transaction::INCOME, ['account_id' => $this->galCash->id,
            'category_id' => $this->walkIn->id, 'txn_date' => '2026-10-06', 'amount' => '900.00', 'payee_payer' => 'CEB walk-in']));
        $this->e['E2'] = $this->as($this->kamal, fn () => $ledger->incomeOrExpense(Transaction::EXPENSE, ['account_id' => $this->kdyCash->id,
            'category_id' => $this->electricity->id, 'txn_date' => '2026-10-10', 'amount' => '4200.00', 'payee_payer' => 'CEB']));
        $this->e['E3'] = $this->as($this->kamal, fn () => $ledger->incomeOrExpense(Transaction::EXPENSE, ['account_id' => $this->kdyCash->id,
            'category_id' => $this->utilities->id, 'txn_date' => '2026-10-12', 'amount' => '50.00', 'description' => '50% advance']));
        $this->e['E4'] = $this->as($this->kamal, fn () => $ledger->incomeOrExpense(Transaction::EXPENSE, ['account_id' => $this->kdyCash->id,
            'category_id' => $this->rent->id, 'txn_date' => '2026-10-15', 'amount' => '500.00', 'description' => 'Rent_Oct']));
        $this->e['E4'] = $this->as($this->kamal, fn () => $ledger->void($this->e['E4'], 'duplicate'));
        $this->e['E6'] = $this->as($this->nimal, fn () => $ledger->transfer($this->kdyCash->id, $this->galBank->id, '3000.00', '2026-10-20', 'Support for Galle'));
        $this->e['E7'] = $this->as($this->nimal, fn () => $ledger->reverse($this->e['E6'], '2026-10-21', 'Wrong account'));
        $this->e['E6']->refresh();
        $this->e['E9'] = Transaction::withoutGlobalScopes()->create(['branch_id' => $this->kdy->id, 'type' => Transaction::YEAR_END_CLOSE,
            'txn_date' => '2026-03-31', 'amount' => '80000.00', 'status' => 'posted', 'created_by' => $this->admin->id,
            'ref_no' => 'KDY-YEC-2026-999999', 'description' => 'Year-end close FY 2025-26']);
        Auth::forgetUser();
    }

    private function named(User $u, string $name): User
    {
        $u->update(['name' => $name]);

        return $u;
    }

    protected function as(User $u, callable $fn): mixed
    {
        Auth::setUser($u);

        return $fn();
    }

    /** ids of E-keys, for comparing with list results */
    protected function ids(string ...$keys): array
    {
        return array_map(fn ($k) => $this->e[$k]->id, $keys);
    }

    protected function kdyAccountant(): User
    {
        return $this->nimal;
    }

    protected function listAs(User $u, string|int $branch, array $q = [])
    {
        return $this->actingAs($u)->withHeader('X-Branch-Id', (string) $branch)->getJson('/api/v1/transactions?'.http_build_query($q));
    }

    protected function kdyList(array $q = [])
    {
        return $this->listAs($this->kdyAccountant(), $this->kdy->id, $q);
    }

    /** Row ids of an OK list response, in order. */
    protected function rowIds($response): array
    {
        $response->assertOk();

        return array_column($response->json('data'), 'id');
    }

    protected function exportAs(User $u, string|int $branch, array $body = [])
    {
        return $this->actingAs($u)->withHeader('X-Branch-Id', (string) $branch)->postJson('/api/v1/transactions/export', $body);
    }

    /** The one sheet of an xlsx response: header lines, column labels and data rows (by column label). */
    protected function sheet($response): array
    {
        $response->assertOk();
        $book = IOFactory::createReader('Xlsx')->load($response->getFile()->getPathname());
        $this->assertSame(['Transactions'], $book->getSheetNames());
        /** @var Worksheet $ws */
        $ws = $book->getSheet(0);
        $lines = [];
        $r = 1;
        while (($v = $ws->getCell([1, $r])->getValue()) !== null && $v !== '') {
            $lines[] = (string) $v;
            $r++;
        }
        $r++; // blank row
        $labels = [];
        for ($c = 1; ($v = $ws->getCell([$c, $r])->getValue()) !== null; $c++) {
            $labels[] = (string) $v;
        }
        $rows = [];
        for ($r++; $ws->getCell([3, $r])->getValue() !== null; $r++) {
            $row = ['_row' => $r];
            foreach ($labels as $i => $label) {
                $row[$label] = self::cellAt($ws, $i + 1, $r);
            }
            $rows[] = $row;
        }

        return ['lines' => $lines, 'labels' => $labels, 'rows' => $rows, 'ws' => $ws];
    }

    /**
     * A cell looked up when read: PhpSpreadsheet detaches Cell objects held across other getCell() calls (their style
     * then reads as General).
     */
    private static function cellAt(Worksheet $ws, int $col, int $row): object
    {
        return new class($ws, $col, $row)
        {
            public function __construct(private Worksheet $ws, private int $col, private int $row) {}

            public function __call(string $method, array $args): mixed
            {
                return $this->ws->getCell([$this->col, $this->row])->{$method}(...$args);
            }
        };
    }

    /** Ref nos of the exported rows, in order. */
    protected function refs(array $sheet): array
    {
        return array_map(fn ($row) => (string) $row['Ref no']->getValue(), $sheet['rows']);
    }

    protected function refsOf(string ...$keys): array
    {
        return array_map(fn ($k) => $this->e[$k]->ref_no, $keys);
    }

    /** Keys sorted at every depth: MySQL JSON does not keep key order. */
    protected static function sorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map([self::class, 'sorted'], $v);
    }
}
