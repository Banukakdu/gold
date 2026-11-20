<?php

namespace Tests\Feature\ExpenseApprovals;

use App\Events\ExpenseApprovalRequested;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** AC 1-6, 25, 27, 28 and 35a: when an expense is held, what the request stores, the limit and its warning. */
class RequestTest extends ExpenseApprovalTestCase
{
    public function test_ac1_a_request_posts_nothing(): void
    {
        Event::fake([ExpenseApprovalRequested::class]);
        $entries = LedgerEntry::count();
        $r = $this->record()->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('approval_status', 'pending')
            ->assertJsonPath('requested_date', '2026-11-18')->assertJsonPath('requested_on', '2026-11-20')
            ->assertJsonPath('approval_threshold', '50000.00')->assertJsonPath('hold_reason', 'threshold')->assertJsonPath('warning', null)
            ->assertJsonMissingPath('entries');
        $this->assertMatchesRegularExpression('/^KDY-EXP-2026-\d{6}$/', $r->json('ref_no'));
        $t = $this->txn($r->json('id'));
        $this->assertSame(0, LedgerEntry::where('transaction_id', $t->id)->count());
        $this->assertSame($entries, LedgerEntry::count());
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
        // R5: every entry field is kept on the request.
        $this->assertSame(['Ceylon Electricity Board', 'BILL-7781', 'November bill', 'cash', $this->electricity->id, $this->kdyCash->id],
            [$t->payee_payer, $t->reference, $t->description, $t->payment_method, (int) $t->category_id, (int) $t->account_id]);
        $a = $this->audits($t, 'expense.approval_requested');
        $this->assertCount(1, $a);
        $this->assertSame(self::sorted([
            'ref_no' => $t->ref_no, 'amount' => '75000.00', 'requested_date' => '2026-11-18', 'requested_on' => '2026-11-20',
            'account_id' => $this->kdyCash->id, 'category_id' => $this->electricity->id, 'threshold' => '50000.00', 'hold_reason' => 'threshold',
            'same_day_total' => '0.00', 'super_admin_only' => false, 'other_pending_from_account' => '0.00', 'files_count' => 0, 'status' => 'pending',
        ]), self::sorted($a[0]->new_values));
        $this->assertCount(0, $this->audits($t, 'transaction.expense'));
        Event::assertDispatchedTimes(ExpenseApprovalRequested::class, 1);
        Event::assertDispatched(ExpenseApprovalRequested::class, fn ($e) => $e->transactionId === $t->id && $e->userId === $this->csh->id);
        $this->assertVerifies();
    }

    public function test_ac2_the_limit_itself_posts_and_one_cent_more_waits(): void
    {
        $posted = $this->record('50000.00')->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('approval_status', null)
            ->assertJsonPath('hold_reason', null)->json();
        // QA D3: the keys are present (null) on an expense that posted at once.
        foreach (['status', 'approval_status', 'requested_date', 'requested_on', 'hold_reason', 'same_day_total', 'warning'] as $key) {
            $this->assertArrayHasKey($key, $posted, $key);
        }
        $this->record('50000.01', '2026-11-19')->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertSame('150000.00', $this->bal($this->kdyCash));
    }

    public function test_ac3_empty_limit_never_holds_and_zero_holds_every_expense(): void
    {
        $this->limit($this->kdy, null);
        $this->record('190000.00')->assertCreated()->assertJsonPath('status', 'posted');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->where('approval_status', 'pending')->count());

        $this->limit($this->kdy, '0');
        $this->record('0.01')->assertCreated()->assertJsonPath('status', 'pending');
    }

    public function test_ac4_the_limit_is_per_branch(): void
    {
        $this->limit($this->kdy, '0');
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->galCash->id,
            'amount' => '100000.00', 'txn_date' => '2026-11-19', 'kind' => 'top_up'])->assertCreated();
        $this->record('100000.00', '2026-11-20', $this->ba, acc: $this->galCash)->assertCreated()->assertJsonPath('status', 'posted');
    }

    public function test_ac5_income_and_super_admin_expenses_are_never_held(): void
    {
        $this->limit($this->kdy, '0');
        $this->as($this->csh, $this->kdy)->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id,
            'category_id' => $this->category('income')->id, 'txn_date' => '2026-11-20', 'amount' => '1000.00'])
            ->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('approval_status', null);

        // With the KDY limit at 50,000.00: S1 above it posts and is flagged; at the limit it is not.
        $this->limit($this->kdy, '50000.00');
        $above = $this->record('75000.00', '2026-11-20', $this->admin, branch: $this->kdy)->assertCreated()->assertJsonPath('status', 'posted')->json('id');
        $at = $this->record('50000.00', '2026-11-20', $this->admin, branch: $this->kdy)->assertCreated()->assertJsonPath('status', 'posted')->json('id');
        $this->assertSame('not_required_super_admin', $this->audits($this->txn($above), 'transaction.expense')[0]->new_values['approval']);
        $this->assertArrayNotHasKey('approval', $this->audits($this->txn($at), 'transaction.expense')[0]->new_values);
        $rows = $this->sa()->getJson('/api/v1/audit-logs?from=2026-11-20&to=2026-11-20&approval=not_required_super_admin')->assertOk()->json('data');
        $this->assertSame([$above], array_values(array_map(fn ($r) => $r['auditable_id'], $rows)));
    }

    public function test_ac6_every_check_runs_before_a_request_is_made(): void
    {
        $count = Transaction::withoutGlobalScopes()->count();
        $this->record('250000.00')->assertStatus(422)->assertJsonPath('message', 'Insufficient balance in KDY Cash (available 200,000.00).');
        $this->record('75000.00', '2026-11-18', cat: $this->inactiveCategory('expense'))->assertStatus(422)
            ->assertJsonPath('message', 'Category must be an active expense category.');
        $this->lock('2026-11', $this->kdy)->assertCreated();
        $this->record()->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked.');
        $this->assertSame($count, Transaction::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::where('event', 'expense.approval_requested')->count());
    }

    public function test_ac25_other_pending_from_the_account_and_the_over_commitment_warning(): void
    {
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => '100000.00'])->assertOk();
        $this->as($this->acc, $this->kdy)->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id,
            'to_account_id' => $this->galCash->id, 'amount' => '150000.00', 'txn_date' => '2026-11-18', 'note' => 'Cash for GAL'])
            ->assertCreated()->assertJsonPath('status', 'pending');
        $e1 = $this->e1();
        $row = collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $e1->id);
        $this->assertSame('150000.00', $row['other_pending_from_account']);

        $this->record('60000.00', '2026-11-20')->assertCreated()->assertJsonPath('status', 'pending')
            ->assertJsonPath('warning', 'Other requests on this account already wait for 225,000.00; together they exceed the balance.');
        $this->assertSame(225000.00, (float) $this->audits($this->txn(Transaction::withoutGlobalScopes()->max('id')), 'expense.approval_requested')[0]
            ->new_values['other_pending_from_account']);
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
    }

    public function test_ac27_super_admin_sets_the_limit_in_cents(): void
    {
        $before = AuditLog::where('event', 'branch.updated')->count();
        $this->sa()->putJson("/api/v1/branches/{$this->kdy->id}", ['expense_approval_threshold' => '60000.50'])->assertOk()
            ->assertJsonPath('expense_approval_threshold', '60000.50')->assertJsonMissingPath('expense_approval_threshold_cents');
        $this->assertSame(6000050, (int) DB::table('branches')->where('id', $this->kdy->id)->value('expense_approval_threshold_cents'));
        $kdy = collect($this->sa()->getJson('/api/v1/branches')->assertOk()->json())->firstWhere('code', 'KDY');
        $this->assertSame('60000.50', $kdy['expense_approval_threshold']);
        $this->assertNull(collect($this->sa()->getJson('/api/v1/branches')->json())->firstWhere('code', 'GAL')['expense_approval_threshold']);
        $a = AuditLog::where('event', 'branch.updated')->orderByDesc('id')->first();
        $this->assertSame($before + 1, AuditLog::where('event', 'branch.updated')->count());
        $this->assertSame(['expense_approval_threshold' => '50000.00'], $a->old_values);
        $this->assertSame(['expense_approval_threshold' => '60000.50'], $a->new_values);

        $this->sa()->putJson("/api/v1/branches/{$this->kdy->id}", ['expense_approval_threshold' => ''])->assertOk()->assertJsonPath('expense_approval_threshold', null);
        $this->assertNull(DB::table('branches')->where('id', $this->kdy->id)->value('expense_approval_threshold_cents'));
        foreach (['-1', '1.234', 'abc', '12345678901234'] as $bad) {
            $this->sa()->putJson("/api/v1/branches/{$this->kdy->id}", ['expense_approval_threshold' => $bad])->assertStatus(422)
                ->assertJsonValidationErrors('expense_approval_threshold');
        }
        $this->as($this->kdyBa, $this->kdy)->putJson("/api/v1/branches/{$this->kdy->id}", ['expense_approval_threshold' => '1.00'])->assertForbidden();
        $this->assertNull(DB::table('branches')->where('id', $this->kdy->id)->value('expense_approval_threshold_cents'));

        // Branch users see the limit read-only.
        $this->limit($this->kdy, '50000.00');
        $this->assertSame('50000.00', collect($this->as($this->csh, $this->kdy)->getJson('/api/v1/branches')->json())->firstWhere('code', 'KDY')['expense_approval_threshold']);
    }

    public function test_ac28_warning_when_only_the_super_admin_can_approve(): void
    {
        $this->sa()->putJson("/api/v1/branches/{$this->kdy->id}", ['expense_approval_threshold' => '50000.00'])->assertOk()
            ->assertJsonPath('expense_approval_warning', null);
        $this->sa()->putJson("/api/v1/branches/{$this->gal->id}", ['expense_approval_threshold' => '10000.00'])->assertOk()
            ->assertJsonPath('expense_approval_warning', null); // the GAL Branch Admin

        $fresh = $this->makeBranch('NEG');
        $this->sa()->putJson("/api/v1/branches/{$fresh->id}", ['expense_approval_threshold' => '50000.00'])->assertOk()
            ->assertJsonPath('expense_approval_warning', "Only the Super Admin can approve NEG's expenses above the limit.");
        // An inactive admin does not count; an active one does. An empty limit never warns.
        $inactive = $this->userIn($fresh, 'Branch Admin');
        $inactive->update(['status' => 'inactive']);
        $this->sa()->putJson("/api/v1/branches/{$fresh->id}", ['expense_approval_threshold' => '50000.00'])->assertOk()
            ->assertJsonPath('expense_approval_warning', "Only the Super Admin can approve NEG's expenses above the limit.");
        $this->sa()->putJson("/api/v1/branches/{$fresh->id}", ['expense_approval_threshold' => null])->assertOk()->assertJsonPath('expense_approval_warning', null);
        $this->userIn($fresh, 'Branch Admin');
        $this->sa()->putJson("/api/v1/branches/{$fresh->id}", ['expense_approval_threshold' => '50000.00'])->assertOk()
            ->assertJsonPath('expense_approval_warning', null);
    }

    public function test_ac35a_same_day_split_is_held(): void
    {
        $this->record('30000.00', '2026-11-18')->assertCreated()->assertJsonPath('status', 'posted');
        $held = $this->record('30000.00', '2026-11-18')->assertCreated()->assertJsonPath('status', 'pending')
            ->assertJsonPath('hold_reason', 'same_day_total')->assertJsonPath('same_day_total', '30000.00');
        $this->assertSame('same_day_total', $this->audits($this->txn($held->json('id')), 'expense.approval_requested')[0]->new_values['hold_reason']);
        $this->assertSame('30000.00', $this->audits($this->txn($held->json('id')), 'expense.approval_requested')[0]->new_values['same_day_total']);

        // Another day, another category, another user, or a Super Admin: posts at once.
        $this->record('30000.00', '2026-11-19')->assertCreated()->assertJsonPath('status', 'posted');
        $this->record('30000.00', '2026-11-18', cat: $this->water)->assertCreated()->assertJsonPath('status', 'posted');
        $this->record('30000.00', '2026-11-18', $this->acc)->assertCreated()->assertJsonPath('status', 'posted');
        $this->record('30000.00', '2026-11-18', $this->admin, branch: $this->kdy)->assertCreated()->assertJsonPath('status', 'posted');
        // Approved requests are not summed (they were authorised): 30,000.00 posted + 15,000.00 is within the limit.
        $this->approve($held->json('id'))->assertOk();
        $this->income('2026-11-20', '50000.00');
        $this->record('15000.00', '2026-11-18')->assertCreated()->assertJsonPath('status', 'posted');
        $this->record('5000.01', '2026-11-18')->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('hold_reason', 'same_day_total');
        $this->assertVerifies();
    }

    /**
     * AC 35a "two parallel 30,000.00 requests on an empty day: at most one posts". The tests run on one connection, so
     * the race is shown by its mechanism: the second request reads the first's committed row (the user's row is locked
     * FOR UPDATE before the sum, which serialises the two on MySQL) and is held.
     */
    public function test_ac35a_two_halves_on_an_empty_day_post_at_most_one(): void
    {
        DB::enableQueryLog();
        $first = $this->record('30000.00', '2026-11-20')->assertCreated()->json('status');
        $second = $this->record('30000.00', '2026-11-20')->assertCreated()->json('status');
        $this->assertSame(['posted', 'pending'], [$first, $second]);
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertNotEmpty(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from `users`') && str_contains($q['query'], 'for update')));
        }
    }

    public function test_threshold_change_does_not_touch_existing_requests(): void
    {
        $e1 = $this->e1();
        $this->limit($this->kdy, '100000.00');
        $this->assertSame('50000.00', (string) $this->txn($e1->id)->approval_threshold);
        $this->assertSame('pending', $this->txn($e1->id)->approval_status);
        $this->assertSame(Branch::class, get_class($this->kdy));
    }
}
