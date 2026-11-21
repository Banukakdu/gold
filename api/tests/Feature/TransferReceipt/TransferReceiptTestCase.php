<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\DoubleEntry\DoubleEntryTestCase;

/**
 * docs/specs/transfer-receipt.md section 7 (AG-052), on the controls setup (MAIN, KDY, GAL; KDY Cash 100,000.00,
 * GAL Cash 50,000.00). "Today" 2026-11-20; the setting is on unless a test turns it off.
 * Users: KDY Accountant ($this->acc, the creator of T1); GAL Cashier, GAL Accountant, GAL Viewer; MAIN Accountant.
 * T1 = KDY Cash -> GAL Cash 10,000.00 dated 2026-11-18 by the KDY Accountant.
 */
abstract class TransferReceiptTestCase extends DoubleEntryTestCase
{
    protected User $galCsh;

    protected User $galAcc;

    protected User $galVw;

    protected User $mainAccUser;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->galCsh = $this->userIn($this->gal, 'Cashier');
        $this->galAcc = $this->userIn($this->gal, 'Accountant');
        $this->galVw = $this->userIn($this->gal, 'Viewer');
        $this->mainAccUser = $this->userIn($this->main, 'Accountant');
        $this->setting(true);
    }

    protected function setting(bool $on): TestResponse
    {
        return $this->sa()->putJson('/api/v1/settings', ['transfer_receipt_required' => $on])->assertOk();
    }

    /** T1 (or another KDY -> GAL branch transfer). */
    protected function t1(string $amount = '10000.00', string $date = '2026-11-18'): Transaction
    {
        $id = $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => $amount, 'txn_date' => $date, 'note' => 'Cash for GAL'])->assertCreated()->json('id');

        return $this->txn($id);
    }

    /** Super Admin top-up MAIN -> GAL. */
    protected function topUp(string $amount = '5000.00', string $date = '2026-11-19'): Transaction
    {
        $id = $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->galCash->id,
            'amount' => $amount, 'txn_date' => $date, 'kind' => 'top_up'])->assertCreated()->json('id');

        return $this->txn($id);
    }

    protected function receipt(int $id, User $by, Branch|string $branch, array $body): TestResponse
    {
        return $this->as($by, $branch)->postJson("/api/v1/transactions/{$id}/receipt", $body);
    }

    protected function confirm(int $id, ?User $by = null, Branch|string|null $branch = null, string $date = '2026-11-19', ?string $note = null): TestResponse
    {
        return $this->receipt($id, $by ?? $this->galCsh, $branch ?? $this->gal, array_filter(['action' => 'received', 'received_on' => $date, 'note' => $note]));
    }

    protected function dispute(int $id, ?string $note = 'Short by 500', ?User $by = null): TestResponse
    {
        return $this->receipt($id, $by ?? $this->galCsh, $this->gal, array_filter(['action' => 'discrepancy', 'note' => $note], fn ($v) => $v !== null));
    }

    protected function widget(User $by, Branch|string $branch, string $query = ''): array
    {
        return $this->as($by, $branch)->getJson('/api/v1/transfer-receipts'.$query)->assertOk()->json();
    }

    /** Ledger lines, every cached balance, status and amount of every transaction. */
    protected function ledgerState(): array
    {
        return [
            'entries' => LedgerEntry::count(),
            'lines' => DB::table('ledger_entries')->orderBy('id')->get(['id', 'account_id', 'debit', 'credit'])->map(fn ($r) => (array) $r)->all(),
            'balances' => Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all(),
            'transactions' => DB::table('transactions')->orderBy('id')->get(['id', 'status', 'amount'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** Everything a refused request must leave unchanged: the ledger, the receipt columns and the audit log. */
    protected function state(): array
    {
        return $this->ledgerState() + [
            'receipts' => DB::table('transactions')->orderBy('id')
                ->get(['id', 'receipt_status', 'received_on', 'receipt_note', 'receipt_by', 'receipt_at'])->map(fn ($r) => (array) $r)->all(),
            'audit' => AuditLog::count(),
        ];
    }

    /** JSON values with their keys sorted (MySQL does not keep JSON key order). */
    protected static function sorted(?array $v): ?array
    {
        if ($v !== null) {
            ksort($v);
        }

        return $v;
    }

    protected function assertLedgerVerifies(): void
    {
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    /** Header lines, labels and rows (values by column label) of the first sheet of an xlsx response. */
    protected function xlsxSheet(TestResponse $response, int $index = 0): array
    {
        $response->assertOk();
        $ws = IOFactory::createReader('Xlsx')->load($response->getFile()->getPathname())->getSheet($index);
        $lines = [];
        $r = 1;
        while (($v = $ws->getCell([1, $r])->getValue()) !== null && $v !== '') {
            $lines[] = (string) $v;
            $r++;
        }
        $r++;
        $labels = [];
        for ($c = 1; ($v = $ws->getCell([$c, $r])->getValue()) !== null; $c++) {
            $labels[] = (string) $v;
        }
        $rows = [];
        for ($r++; $ws->getCell([2, $r])->getValue() !== null; $r++) {
            $row = [];
            foreach ($labels as $i => $label) {
                $row[$label] = $ws->getCell([$i + 1, $r])->getFormattedValue();
            }
            $rows[] = $row;
        }

        return ['lines' => $lines, 'labels' => $labels, 'rows' => $rows];
    }
}
