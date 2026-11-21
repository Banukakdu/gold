<?php

namespace Tests\Feature\TransferApprovals;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\DoubleEntry\DoubleEntryTestCase;

/**
 * docs/specs/transfer-approvals.md section 8 (AG-051), on the controls setup (MAIN, KDY, GAL) plus a 100,000.00 top-up
 * of KDY Cash, so KDY Cash 200,000.00 and GAL Cash 50,000.00. "Today" 2026-11-20; threshold 100,000.00.
 * Users: KDY Accountant ($this->acc, the requester), KDY Branch Admin, MAIN Branch Admin (approver), MAIN Accountant,
 * Super Admin. T1 = KDY Cash -> GAL Cash 150,000.00 dated 2026-11-18 by the KDY Accountant.
 */
abstract class TransferApprovalTestCase extends DoubleEntryTestCase
{
    protected User $kdyBa;

    protected User $mainBa;

    protected User $mainAccUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '100000.00', 'txn_date' => '2026-11-01', 'kind' => 'top_up'])->assertCreated();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->kdyBa = $this->userIn($this->kdy, 'Branch Admin');
        $this->mainBa = $this->userIn($this->main, 'Branch Admin');
        $this->mainAccUser = $this->userIn($this->main, 'Accountant');
        $this->threshold('100000.00');
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
    }

    protected function threshold(?string $value): TestResponse
    {
        return $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => $value])->assertOk();
    }

    /** A KDY Cash -> $to transfer by $by (default the KDY Accountant, in KDY). */
    protected function request(string $amount = '150000.00', string $date = '2026-11-18', ?Account $to = null, ?User $by = null, ?Branch $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id,
            'to_account_id' => ($to ?? $this->galCash)->id, 'amount' => $amount, 'txn_date' => $date, 'note' => 'Cash for GAL']);
    }

    protected function t1(string $amount = '150000.00', string $date = '2026-11-18'): Transaction
    {
        return $this->txn($this->request($amount, $date)->assertCreated()->json('id'));
    }

    protected function approve(int $id, ?User $by = null, Branch|string|null $branch = null, array $body = []): TestResponse
    {
        return $this->as($by ?? $this->mainBa, $branch ?? $this->main)->postJson("/api/v1/transfer-approvals/{$id}/approve", $body);
    }

    protected function reject(int $id, ?string $comment = 'Split into two smaller transfers', ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->mainBa, $branch ?? $this->main)->postJson("/api/v1/transfer-approvals/{$id}/reject",
            array_filter(['comment' => $comment], fn ($v) => $v !== null));
    }

    protected function cancel(int $id, ?User $by = null, Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by ?? $this->acc, $branch ?? $this->kdy)->postJson("/api/v1/transfer-approvals/{$id}/cancel", []);
    }

    protected function widget(User $by, Branch|string $branch): array
    {
        return $this->as($by, $branch)->getJson('/api/v1/transfer-approvals')->assertOk()->json();
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
        return AuditLog::where('event', $event)->where('auditable_type', Transaction::class)->where('auditable_id', $t->id)->get();
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
