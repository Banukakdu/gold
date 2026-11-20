<?php

namespace Tests\Feature\Controls;

use App\Exceptions\LedgerException;
use App\Models\AuditLog;
use App\Models\PeriodLock;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;

/** AG-091 period locks: controls spec AC 10-19, 29-31, 36-38. */
class PeriodLockTest extends ControlsTestCase
{
    public function test_ac10_lock_a_branch_month(): void
    {
        $res = $this->lock('2026-10', $this->kdy)->assertCreated();
        $this->assertSame($this->kdy->id, $res->json('branch_id'));
        $this->assertSame('2026-10', $res->json('period'));

        $grid = $this->sa()->getJson('/api/v1/period-locks?year=2026')->assertOk()->json();
        $lock = collect($grid['locks'])->sole();
        $this->assertSame([$this->kdy->id, '2026-10', $this->admin->name, null], [$lock['branch_id'], $lock['period'], $lock['locked_by']['name'], $lock['unlocked_at']]);
        $this->assertNotNull($lock['locked_at']);
        $this->assertSame(['MAIN', 'GAL', 'KDY'], array_column($grid['branches'], 'code'));
        $this->assertSame([$this->kdy->id => '2026-10'], $grid['closed_through']['branches']);

        $audit = AuditLog::where('event', 'period.locked')->sole();
        $this->assertSame($this->kdy->id, $audit->branch_id);
        $this->assertSame(['period' => '2026-10', 'branch_id' => $this->kdy->id], $audit->new_values);
    }

    public function test_ac11_ac13_duplicate_and_future_locks(): void
    {
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $this->lock('2026-10', $this->kdy)->assertStatus(422)->assertJsonPath('message', 'October 2026 is already locked for KDY.');

        $this->lock('2026-10', null)->assertCreated();
        $this->lock('2026-10', $this->gal)->assertStatus(422)->assertJsonPath('message', 'October 2026 is already locked for all branches.');
        $this->lock('2026-10', null)->assertStatus(422)->assertJsonPath('message', 'October 2026 is already locked for all branches.');

        $this->lock('2099-01', null)->assertStatus(422)->assertJsonPath('message', 'Future months cannot be locked.');
        $this->lock('2026-12', $this->kdy)->assertStatus(422)->assertJsonPath('message', 'Future months cannot be locked.');
        $this->lock('2026-11', $this->kdy)->assertCreated(); // the current month can be locked
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-10', 'branch_id' => 9999])->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-13'])->assertStatus(422)->assertJsonValidationErrors('period');
    }

    public function test_ac12_only_super_admin(): void
    {
        $kdyAdmin = $this->userIn($this->kdy, 'Branch Admin');
        $lock = PeriodLock::create(['branch_id' => $this->kdy->id, 'period' => '2026-10', 'locked_by' => $this->admin->id]);
        foreach ([[$this->acc, $this->kdy], [$this->ba, $this->gal], [$kdyAdmin, $this->kdy]] as [$u, $b]) {
            $this->as($u, $b)->getJson('/api/v1/period-locks?year=2026')->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
            $this->as($u, $b)->getJson('/api/v1/period-locks/review?period=2026-10')->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
            $this->as($u, $b)->postJson('/api/v1/period-locks', ['period' => '2026-09', 'branch_id' => $b->id])->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
            $this->as($u, $b)->postJson("/api/v1/period-locks/{$lock->id}/unlock", ['reason' => 'Late supplier bill'])->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
        }
        $this->assertNull($lock->fresh()->unlocked_at);
        $this->assertSame(1, PeriodLock::count());
        // No edit/delete routes.
        $this->assertContains($this->sa()->deleteJson("/api/v1/period-locks/{$lock->id}")->status(), [404, 405]);
        $this->assertContains($this->sa()->putJson("/api/v1/period-locks/{$lock->id}", [])->status(), [404, 405]);
        $this->assertNotNull($lock->fresh());
    }

    public function test_ac14_unlock_needs_a_reason_and_keeps_history(): void
    {
        $id = $this->lock('2026-10', $this->kdy)->assertCreated()->json('id');
        $this->sa()->postJson("/api/v1/period-locks/{$id}/unlock", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->unlock($id, 'Too short')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->unlock($id, str_repeat('x', 501))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertNull(PeriodLock::find($id)->unlocked_at);

        $this->unlock($id)->assertOk();
        $row = PeriodLock::find($id);
        $this->assertSame($this->admin->id, $row->locked_by);
        $this->assertNotNull($row->unlocked_at);
        $this->assertSame($this->admin->id, $row->unlocked_by);
        $this->assertSame('Late supplier bill', $row->unlock_reason);
        $audit = AuditLog::where('event', 'period.unlocked')->sole();
        $this->assertSame('Late supplier bill', $audit->new_values['reason']);
        $this->assertSame($this->admin->id, $audit->old_values['locked_by']);
        $this->assertNotNull($audit->old_values['locked_at']);
        $this->assertSame($this->kdy->id, $audit->branch_id);
        $this->unlock($id)->assertStatus(422)->assertJsonPath('message', 'This lock is already unlocked.');

        $this->lock('2026-10', $this->kdy)->assertCreated();
        $locks = $this->sa()->getJson('/api/v1/period-locks?year=2026')->json('locks');
        $this->assertCount(2, $locks);
        $this->assertSame('Late supplier bill', $locks[0]['unlock_reason']);
        $this->assertSame($this->admin->name, $locks[0]['unlocked_by']['name']);
        $this->assertNull($locks[1]['unlocked_at']);
    }

    public function test_ac15_global_and_branch_locks(): void
    {
        $global = $this->lock('2026-10', null)->assertCreated()->json('id');
        // A branch lock cannot be added under the global one; create it first, then the global lock (allowed).
        $this->unlock($global)->assertOk();
        $kdy = $this->lock('2026-10', $this->kdy)->assertCreated()->json('id');
        $global = $this->lock('2026-10', null)->assertCreated()->json('id');

        $this->unlock($kdy)->assertStatus(422)->assertJsonPath('message', 'October 2026 is also locked for all branches. Unlock all branches first.');
        $this->unlock($global)->assertOk();
        $this->assertTrue(PeriodLock::isLocked($this->kdy->id, '2026-10-15'));
        $this->assertFalse(PeriodLock::isLocked($this->gal->id, '2026-10-15'));
    }

    /** AC 16: every money or entry-data path honours a branch lock and a global lock. */
    public function test_ac16_every_path_refuses_a_locked_month_branch_lock(): void
    {
        $this->assertEveryPathRefused(fn () => $this->lock('2026-10', $this->kdy)->assertCreated());
    }

    public function test_ac16_every_path_refuses_a_locked_month_global_lock(): void
    {
        $this->assertEveryPathRefused(fn () => $this->lock('2026-10', null)->assertCreated());
    }

    private function assertEveryPathRefused(callable $lock): void
    {
        $oct = $this->expense('2026-10-05', '1000.00');
        $lock();
        $before = $this->snapshot();
        $msg = 'The period 2026-10 is locked.';
        $date = '2026-10-20';

        $this->accKdy()->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id, 'category_id' => $this->category('income')->id, 'txn_date' => $date, 'amount' => '10'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $this->category('expense')->id, 'txn_date' => $date, 'amount' => '10'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id, 'amount' => '10', 'txn_date' => $date, 'note' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->as($this->ba, $this->gal)->postJson('/api/v1/transfers', ['from_account_id' => $this->galCash->id, 'to_account_id' => $this->kdyCash->id, 'amount' => '10', 'txn_date' => $date, 'note' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id, 'amount' => '10', 'txn_date' => $date, 'kind' => 'top_up'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->putJson("/api/v1/transactions/{$oct['id']}", ['payee_payer' => 'Changed'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson("/api/v1/transactions/{$oct['id']}/void", ['reason' => 'Duplicate'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson("/api/v1/transactions/{$oct['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => $date])->assertStatus(422)->assertJsonPath('message', $msg);

        $this->assertSame($before, $this->snapshot());
    }

    /** Accountant sign-off A: non-ISO dates must not slip past the lock (text compare) on any money path. */
    public function test_ac16_non_iso_dates_are_refused_on_every_path(): void
    {
        $oct = $this->expense('2026-10-05', '1000.00');
        $this->lock('2026-10', null)->assertCreated();
        $before = $this->snapshot();
        $cat = ['income' => $this->category('income')->id, 'expense' => $this->category('expense')->id];

        foreach (['2026/10/05', '5 October 2026', '20261005', '2026-10-5', '2026-10-05 10:00:00'] as $date) {
            $this->accKdy()->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id, 'category_id' => $cat['income'], 'txn_date' => $date, 'amount' => '10'])
                ->assertStatus(422)->assertJsonValidationErrors('txn_date');
            $this->accKdy()->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $cat['expense'], 'txn_date' => $date, 'amount' => '10'])
                ->assertStatus(422)->assertJsonValidationErrors('txn_date');
            $this->accKdy()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id, 'amount' => '10', 'txn_date' => $date, 'note' => 'x'])
                ->assertStatus(422)->assertJsonValidationErrors('txn_date');
            $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id, 'amount' => '10', 'txn_date' => $date, 'kind' => 'top_up'])
                ->assertStatus(422)->assertJsonValidationErrors('txn_date');
            $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '10', 'txn_date' => $date])
                ->assertStatus(422)->assertJsonValidationErrors('txn_date');
            $this->accKdy()->postJson("/api/v1/transactions/{$oct['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => $date])
                ->assertStatus(422)->assertJsonValidationErrors('txn_date');
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Transaction::withoutGlobalScopes()->whereDate('txn_date', '<', '2000-01-01')->count());

        // The service and the lock check refuse them too (no text compare on another format).
        Auth::setUser($this->acc);
        $ledger = app(LedgerService::class);
        foreach ([
            fn () => $ledger->incomeOrExpense('income', ['account_id' => $this->kdyCash->id, 'category_id' => $cat['income'], 'txn_date' => '2026/10/05', 'amount' => '10']),
            fn () => $ledger->transfer($this->kdyCash->id, $this->galCash->id, '10', '20261005'),
            fn () => $ledger->capital($this->mainAcc->id, '10', '5 October 2026'),
            fn () => $ledger->reverse($this->txn($oct['id']), '2026/11/05'),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("service call {$i} accepted a non-ISO date");
            } catch (LedgerException $e) {
                $this->assertSame('The date must be in YYYY-MM-DD format.', $e->getMessage());
            }
        }
        $this->assertSame($before, $this->snapshot());
        $this->expectException(\InvalidArgumentException::class);
        PeriodLock::isLocked($this->kdy->id, '2026/10/05');
    }

    public function test_lock_review_covers_every_newly_closed_month(): void
    {
        $sep = $this->expense('2026-09-25', '10.00');
        $oct = $this->expense('2026-10-05', '10.00');
        $aug = $this->expense('2026-08-20', '10.00');
        foreach ([$sep, $oct, $aug] as $e) {
            $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        }
        $q = "/api/v1/period-locks/review?period=2026-10&branch_id={$this->kdy->id}";
        $this->sa()->getJson($q)->assertOk()->assertJsonPath('self_voids', 3)->assertJsonPath('closes_from', null);

        $this->lock('2026-08', $this->kdy)->assertCreated();
        $this->sa()->getJson($q)->assertOk()->assertJsonPath('self_voids', 2)->assertJsonPath('closes_from', '2026-09');
        // A global lock newly closes different months per branch: KDY from September, GAL/MAIN from the start.
        $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->assertOk()->assertJsonPath('self_voids', 2)->assertJsonPath('closes_from', null);
    }

    public function test_ac17_reversal_in_an_open_month_leaves_the_locked_month_unchanged(): void
    {
        $oct = $this->expense('2026-10-05', '1000.00');
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $report = fn () => $this->accKdy()->getJson('/api/v1/reports/income-expense?'.http_build_query($q))->assertOk()->json();
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $before = $report();
        $this->assertSame('1000.00', $before['expense']['total']);

        $rev = $this->accKdy()->postJson("/api/v1/transactions/{$oct['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-05'])->assertCreated();
        $this->assertSame('2026-11-05', $rev->json('txn_date'));
        $this->assertSame('reversed', $this->txn($oct['id'])->status);
        $this->assertSame($before, $report());
    }

    public function test_ac18_reversal_date_rules(): void
    {
        $e = $this->expense('2026-11-10', '1000.00');
        $before = $this->snapshot();
        foreach (['2026-11-09', '2026-11-19', '2027-01-01'] as $date) {
            $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => $date])
                ->assertStatus(422)->assertJsonValidationErrors('txn_date');
        }
        $this->assertSame($before, $this->snapshot());
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-10'])->assertCreated();
    }

    public function test_ac19_provisional_follows_active_locks(): void
    {
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31', 'branches' => [$this->kdy->id, $this->gal->id]];
        $provisional = fn () => $this->sa()->getJson('/api/v1/reports/income-expense?'.http_build_query($q))->assertOk()->json('provisional');

        $this->assertTrue($provisional());
        $kdy = $this->lock('2026-10', $this->kdy)->assertCreated()->json('id');
        $this->assertTrue($provisional());
        $this->lock('2026-10', $this->gal)->assertCreated();
        $this->assertFalse($provisional());
        $this->unlock($kdy)->assertOk();
        $this->assertTrue($provisional());
    }

    public function test_ac29_a_later_lock_closes_earlier_months(): void
    {
        $oct = $this->expense('2026-10-05', '1000.00');
        $this->lock('2026-11', $this->kdy)->assertCreated();
        $before = $this->snapshot();
        $msg = 'The period 2026-10 is locked.';

        $this->accKdy()->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $this->category('expense')->id, 'txn_date' => '2026-10-20', 'amount' => '10'])
            ->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->putJson("/api/v1/transactions/{$oct['id']}", ['payee_payer' => 'Changed'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->accKdy()->postJson("/api/v1/transactions/{$oct['id']}/void", ['reason' => 'Duplicate'])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->assertSame($before, $this->snapshot());
        // GAL is not affected.
        $this->expense('2026-10-20', '10.00', $this->ba, $this->galCash);

        // S2 data: KDY is closed through November; October has no lock row (the grid shows "Closed (through Nov 2026)").
        $grid = $this->sa()->getJson('/api/v1/period-locks?year=2026')->json();
        $this->assertSame([$this->kdy->id => '2026-11'], $grid['closed_through']['branches']);
        $this->assertNull(collect($grid['locks'])->firstWhere('period', '2026-10'));
        $this->lock('2026-10', $this->kdy)->assertStatus(422)->assertJsonPath('message', 'October 2026 is already closed for KDY (locked through November 2026).');

        $this->assertFalse($this->accKdy()->getJson('/api/v1/reports/income-expense?from=2026-10-01&to=2026-10-31')->assertOk()->json('provisional'));
    }

    public function test_ac30_unlock_from_the_latest_month_down(): void
    {
        $oct = $this->lock('2026-10', $this->kdy)->assertCreated()->json('id');
        $nov = $this->lock('2026-11', $this->kdy)->assertCreated()->json('id');
        $this->unlock($oct)->assertStatus(422)->assertJsonPath('message', 'Unlock November 2026 for KDY first.');
        $this->unlock($nov)->assertOk();

        $global = $this->lock('2026-11', null)->assertCreated()->json('id');
        $this->unlock($oct)->assertStatus(422)->assertJsonPath('message', 'Unlock November 2026 for all branches first.');
        $this->assertNull(PeriodLock::find($oct)->unlocked_at);

        // A global lock may be unlocked while later branch locks exist; those branches stay closed.
        $this->unlock($global)->assertOk();
        $gOct = $this->lock('2026-09', null)->assertCreated()->json('id');
        $this->lock('2026-11', $this->gal)->assertCreated();
        $this->unlock($gOct)->assertOk();
        $this->assertTrue(PeriodLock::isLocked($this->gal->id, '2026-09-10'));
        $this->unlock($oct)->assertOk();
    }

    public function test_ac31_capital_and_allocation_dates(): void
    {
        $this->lock('2026-10', $this->main)->assertCreated();
        $before = $this->snapshot();
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '10', 'txn_date' => '2026-09-15'])
            ->assertStatus(422)->assertJsonPath('message', 'The period 2026-09 is locked.');
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '10', 'txn_date' => '2020-01-01'])
            ->assertStatus(422)->assertJsonPath('message', 'The period 2020-01 is locked.');
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id, 'amount' => '10', 'txn_date' => '2026-09-15', 'kind' => 'top_up'])
            ->assertStatus(422)->assertJsonPath('message', 'The period 2026-09 is locked.');
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '10', 'txn_date' => '2026-11-19'])
            ->assertStatus(422)->assertJsonValidationErrors('txn_date');
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id, 'amount' => '10', 'txn_date' => '2026-11-19', 'kind' => 'top_up'])
            ->assertStatus(422)->assertJsonValidationErrors('txn_date');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ac36_reversal_rights(): void
    {
        $mainAcc = $this->userIn($this->main, 'Accountant');
        $cap = Transaction::withoutGlobalScopes()->where('type', 'capital')->firstOrFail();
        $opening = Transaction::withoutGlobalScopes()->where('type', 'opening_allocation')->firstOrFail();
        $topUp = $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '10', 'txn_date' => '2026-11-12', 'kind' => 'top_up'])->assertCreated()->json();
        $this->assertSame('fund_allocation', $topUp['type']);
        $before = $this->snapshot();
        foreach ([$cap->id, $opening->id, $topUp['id']] as $id) {
            $this->as($mainAcc, $this->main)->postJson("/api/v1/transactions/{$id}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-15'])
                ->assertForbidden()->assertJsonPath('message', 'Only a Super Admin can reverse this entry.');
        }
        $this->assertSame($before, $this->snapshot());
        $this->sa()->postJson("/api/v1/transactions/{$topUp['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2026-11-15'])->assertCreated();

        // transfer.reverse without expense.void cannot reverse an expense.
        $role = Role::create(['name' => 'Reverser', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions(['transaction.view', 'transfer.reverse', 'expense.create']);
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($role);
        $e = $this->expense('2026-11-10', '100.00');
        $this->as($u, $this->kdy)->postJson("/api/v1/transactions/{$e['id']}/reverse", ['reason' => 'Wrong'])
            ->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.void');
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/reverse", ['reason' => 'Wrong'])->assertCreated();
    }

    public function test_ac37_lock_confirm_counts_self_voids(): void
    {
        $a = $this->expense('2026-10-05', '100.00');
        $b = $this->expense('2026-10-06', '100.00');
        $c = $this->expense('2026-10-07', '100.00');
        $nov = $this->expense('2026-11-07', '100.00');
        $this->accKdy()->postJson("/api/v1/transactions/{$a['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->accKdy()->postJson("/api/v1/transactions/{$b['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->sa($this->kdy)->postJson("/api/v1/transactions/{$c['id']}/void", ['reason' => 'Duplicate'])->assertOk(); // not a self-void
        $this->accKdy()->postJson("/api/v1/transactions/{$nov['id']}/void", ['reason' => 'Duplicate'])->assertOk(); // other month

        $this->sa()->getJson("/api/v1/period-locks/review?period=2026-10&branch_id={$this->kdy->id}")->assertOk()
            ->assertJsonPath('self_voids', 2)->assertJsonPath('self_reversals', 0);
        $this->sa()->getJson("/api/v1/period-locks/review?period=2026-10&branch_id={$this->gal->id}")->assertOk()->assertJsonPath('self_voids', 0);
        $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->assertOk()->assertJsonPath('self_voids', 2);
        $this->lock('2026-10', $this->kdy)->assertCreated();
    }

    public function test_ac38_one_active_lock_per_scope_even_under_concurrency(): void
    {
        $this->lock('2026-10', null)->assertCreated();
        // A second insert that slipped past the check (a concurrent request) is refused by the database.
        try {
            PeriodLock::create(['branch_id' => null, 'period' => '2026-10', 'locked_by' => $this->admin->id]);
            $this->fail('duplicate active lock was inserted');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->lock('2026-10', null)->assertStatus(422);
        $this->assertSame(1, PeriodLock::active()->where('period', '2026-10')->whereNull('branch_id')->count());

        // Unlocked rows do not count: re-locking after an unlock works, and history may hold several rows.
        $this->unlock($this->activeLock('2026-10', null))->assertOk();
        $this->lock('2026-10', null)->assertCreated();
        $this->assertSame(2, PeriodLock::where('period', '2026-10')->whereNull('branch_id')->count());
        $this->assertSame(1, PeriodLock::active()->where('period', '2026-10')->whereNull('branch_id')->count());
    }
}
