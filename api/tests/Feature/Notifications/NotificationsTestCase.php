<?php

namespace Tests\Feature\Notifications;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\InAppNotifications;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\TransferApprovals\TransferApprovalTestCase;

/**
 * docs/specs/notifications.md section 7 data (AG-094): MAIN, KDY, GAL; approval threshold 100,000.00; receipt
 * confirmation on; "today" 2026-11-20. KDY Cash 200,000.00, GAL Cash 50,000.00 (TransferApprovalTestCase).
 * Users: KDY Accountant A. Perera ($acc, transfer.create + transfer.reverse), KDY Branch Admin ($kdyBa), MAIN Branch
 * Admin ($mainBa, transfer.approve in MAIN), MAIN Accountant ($mainAccUser), GAL Cashier, GAL Accountant, GAL Viewer,
 * GAL Branch Admin ($ba), Super Admin S1 ($admin), inactive MAIN Branch Admin 2 ($mainBa2).
 * T1 = KDY Cash -> GAL Cash 150,000.00 requested by the KDY Accountant; T2 = KDY Cash -> GAL Cash 10,000.00 posted by
 * the KDY Accountant.
 */
abstract class NotificationsTestCase extends TransferApprovalTestCase
{
    protected User $galCsh;

    protected User $galAcc;

    protected User $galVw;

    protected User $mainBa2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acc->update(['name' => 'A. Perera']);
        $this->mainBa->update(['name' => 'M. Silva']);
        $this->galCsh = $this->userIn($this->gal, 'Cashier');
        $this->galAcc = $this->userIn($this->gal, 'Accountant');
        $this->galVw = $this->userIn($this->gal, 'Viewer');
        $this->mainBa2 = $this->userIn($this->main, 'Branch Admin');
        $this->mainBa2->update(['status' => 'inactive']);
        $this->sa()->putJson('/api/v1/settings', ['transfer_receipt_required' => true])->assertOk();
        // Rows written while the fixture was built (none expected) do not count.
        DB::table('notifications')->delete();
    }

    /** T2 (or another KDY -> GAL transfer below the threshold), posted with an open receipt. */
    protected function t2(string $amount = '10000.00', ?User $by = null): Transaction
    {
        $id = $this->as($by ?? $this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id,
            'to_account_id' => $this->galCash->id, 'amount' => $amount, 'txn_date' => '2026-11-20', 'note' => 'Cash for GAL'])
            ->assertCreated()->json('id');

        return $this->txn($id);
    }

    protected function dispute(Transaction $t, ?User $by = null): TestResponse
    {
        return $this->as($by ?? $this->galCsh, $this->gal)->postJson("/api/v1/transactions/{$t->id}/receipt",
            ['action' => 'discrepancy', 'note' => 'Short by 500 rupees in the bag']);
    }

    protected function confirmReceipt(Transaction $t, ?User $by = null, ?string $note = null): TestResponse
    {
        return $this->as($by ?? $this->galAcc, $this->gal)->postJson("/api/v1/transactions/{$t->id}/receipt",
            array_filter(['action' => 'received', 'received_on' => '2026-11-20', 'note' => $note]));
    }

    /** @return Collection<int, DatabaseNotification> a user's rows (optionally one kind) */
    protected function rows(User $u, ?string $kind = null)
    {
        return DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $u->id)
            ->when($kind, fn ($q, $k) => $q->where('type', $k))->orderBy('created_at')->get();
    }

    /** @return int[] ids of the users holding a row of $kind about $t */
    protected function recipients(string $kind, ?Transaction $t = null): array
    {
        return DatabaseNotification::where('type', $kind)->get()
            ->filter(fn ($n) => $t === null || (int) ($n->data['transaction_id'] ?? 0) === $t->id)
            ->pluck('notifiable_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    /** @param  User[]  $users */
    protected static function ids(array $users): array
    {
        return collect($users)->map(fn (User $u) => (int) $u->id)->sort()->values()->all();
    }

    /** A row written directly (the API tests), created at $at (UTC). */
    protected function note(User $u, string $at, ?string $title = null, string $kind = InAppNotification::EXPORT_READY, ?int $branchId = null, ?string $code = null): string
    {
        Carbon::setTestNow($at);
        $id = app(InAppNotifications::class)->write($u, $kind, ['title' => $title ?? "Row {$at}", 'body' => 'Body', 'branch_id' => $branchId,
            'branch_code' => $code, 'url' => '/exports?id=1']);
        Carbon::setTestNow('2026-11-20 10:00:00');

        return $id;
    }

    /** The ledger: lines, every cached balance, and status / amount of every transaction (accounting review 6). */
    protected function ledgerState(): array
    {
        return [
            'lines' => DB::table('ledger_entries')->orderBy('id')->get(['id', 'account_id', 'debit', 'credit'])->map(fn ($r) => (array) $r)->all(),
            'balances' => Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all(),
            'transactions' => DB::table('transactions')->orderBy('id')->get(['id', 'status', 'amount', 'approval_status', 'receipt_status'])
                ->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** Signed in with no X-Branch-Id header (the notification routes are outside `branch`). */
    protected function bare(User $u)
    {
        return $this->flushHeaders()->actingAs($u);
    }
}
