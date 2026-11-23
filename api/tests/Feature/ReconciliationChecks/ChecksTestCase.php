<?php

namespace Tests\Feature\ReconciliationChecks;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementCheck;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BankReconciliation\BankReconciliationTestCase;

/**
 * docs/specs/reconciliation-checked-by.md section 7 setup (AG-033d), on the AG-033c section 7 setup (today 2026-12-10):
 *  - ST4: GAL Bank 2026-10-31 completed by GACC;
 *  - ST1: KDY Bank 2026-10-31, 479,500.00, completed and every line ticked by ACC on 2026-11-03; E3 (the 500.00 bank
 *    charge) recorded by BA; LATE: on 2026-11-05 ACC records an expense of 1,000.00 dated 2026-10-25 (1 late entry);
 *  - ST2: KDY Bank 2026-11-30 completed by ACC on 2026-12-02, undone by BA on 2026-12-03;
 *  - ST3: KDY Bank 2026-11-30, completed and every line ticked by BA on 2026-12-03.
 * Users (fresh install): VW Viewer KDY (holds account.reconcile_check), BA Branch Admin KDY, ACC Accountant KDY (no key),
 * CSH Cashier KDY, GVW Viewer GAL, SA Super Admin, VW2 KDY with report.view and without the key.
 */
abstract class ChecksTestCase extends BankReconciliationTestCase
{
    protected BankStatement $st1;

    protected BankStatement $st2;

    protected BankStatement $st3;

    protected BankStatement $st4;

    protected Transaction $e3;

    protected Transaction $late;

    protected User $gvw;

    protected User $vw2;

    /** MySQL stores audit JSON with its own key order: compare key-sorted (types stay strict). */
    protected static function ksorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map([self::class, 'ksorted'], $v);
        ksort($v);

        return $v;
    }

    protected function assertSameJson(array $expected, mixed $actual): void
    {
        $this->assertSame(self::ksorted($expected), self::ksorted($actual));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->gvw = $this->userIn($this->gal, 'Viewer');
        $role = Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions(['branch.view', 'report.view']);
        $this->vw2 = User::factory()->create(['status' => 'active']);
        $this->vw2->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $this->vw2->assignRole($role);
        $this->scenario();
    }

    protected function scenario(): void
    {
        $gal = $this->allocate($this->galBank, '100000.00', '2026-10-01');
        $this->tick([[$gal, '2026-10-31']], $this->gacc, [], $this->galBank)->assertOk();
        $this->st4 = $this->statement($this->complete('2026-10-31', '100000.00', $this->gacc, $this->galBank));

        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->tick([[$this->top, '2026-10-31'], [$this->e1, '2026-10-31'], [$this->i1, '2026-10-31']])->assertOk();
        $this->e3 = $this->expense($this->ba, '500.00', '2026-10-31', null, null, 'Bank charges');
        $this->tick([[$this->e3, '2026-10-31']])->assertOk();
        $this->st1 = $this->statement($this->complete('2026-10-31', '479500.00'));
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->late = $this->expense($this->acc, '1000.00', '2026-10-25', '001300');

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();
        $this->st2 = $this->statement($this->complete('2026-11-30', '469500.00'));
        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->undo($this->st2->id, 'Wrong closing balance typed', $this->ba)->assertOk();
        Carbon::setTestNow('2026-12-03 10:00:00');
        // BA ticks the lines again (new marks by BA), then completes.
        $this->tick([[$this->i2, '2026-11-04'], [$this->e2, '2026-11-05']], $this->ba)->assertOk();
        $this->st3 = $this->statement($this->complete('2026-11-30', '469500.00', $this->ba));

        Carbon::setTestNow('2026-12-10 10:00:00');
    }

    protected function statement(TestResponse $r): BankStatement
    {
        return BankStatement::withoutGlobalScopes()->findOrFail($r->assertCreated()->json('id'));
    }

    protected function checkUrl(BankStatement $s, string $path = ''): string
    {
        return "/api/v1/accounts/{$s->account_id}/reconciliation/statements/{$s->id}/checks".$path;
    }

    protected function check(BankStatement $s, User $by, array $body = [], Branch|string|null $branch = null): TestResponse
    {
        $branch ??= Branch::find($s->branch_id);

        return $this->as($by, $branch)->postJson($this->checkUrl($s), $body + ['compared_with' => 'internet_banking', 'confirmed' => true]);
    }

    protected function withdraw(BankStatement $s, int $checkId, User $by, string $reason = 'Compared with the September statement by mistake',
        Branch|string|null $branch = null): TestResponse
    {
        return $this->as($by, $branch ?? Branch::find($s->branch_id))->postJson($this->checkUrl($s, "/{$checkId}/withdraw"), ['reason' => $reason]);
    }

    protected function activeCheck(BankStatement $s): ?BankStatementCheck
    {
        return BankStatementCheck::withoutGlobalScopes()->where('bank_statement_id', $s->id)->where('status', 'active')->first();
    }

    /** The S3 reprint `statement`. */
    protected function reprint(BankStatement $s, ?User $by = null, Branch|string|null $branch = null): array
    {
        return $this->report(['statement_id' => $s->id], $by ?? $this->vw, $branch ?? Branch::find($s->branch_id))->assertOk()->json('statement');
    }

    protected function listRow(BankStatement $s, array $q = [], ?User $by = null): ?array
    {
        $r = $this->as($by ?? $this->vw, Branch::find($s->branch_id))->getJson('/api/v1/reports/reconciliation-statements?'.http_build_query($q))->assertOk();

        return collect($r->json('data'))->firstWhere('id', $s->id);
    }

    protected function listIds(array $q = [], ?User $by = null, Branch|string|null $branch = null): array
    {
        return array_column($this->as($by ?? $this->vw, $branch ?? $this->kdy)->getJson('/api/v1/reports/reconciliation-statements?'.http_build_query($q))
            ->assertOk()->json('data'), 'id');
    }

    /** @return Collection<int, DatabaseNotification> */
    protected function notes(User $u, ?string $kind = null)
    {
        return DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $u->id)
            ->when($kind, fn ($q, $k) => $q->where('type', $k))->orderBy('created_at')->get();
    }

    /** KDY Bank 2: 10,000.00 allocated 2026-11-01 (ticked by $ticker, cleared 2026-11-30), a 300.00 payment by BA dated 2026-11-15 left uncleared, completed by ACC. */
    protected function bankTwoStatement(User $ticker): BankStatement
    {
        $bank2 = $this->bankIn($this->kdy, 'KDY Bank 2');
        $alloc = $this->allocate($bank2, '10000.00', '2026-11-01');
        $this->expense($this->ba, '300.00', '2026-11-15', '009001', $bank2);
        $this->tick([[$alloc, '2026-11-30']], $ticker, [], $bank2)->assertOk();

        return $this->statement($this->complete('2026-11-30', '10000.00', $this->acc, $bank2));
    }

    protected function bank(int $id): Account
    {
        return Account::withoutGlobalScopes()->findOrFail($id);
    }

    /** Every text cell of the S3 Excel export of $s. */
    protected function sheetText(BankStatement $s): array
    {
        $ws = $this->xlsx($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export', ['statement_id' => $s->id, 'format' => 'xlsx'])
            ->assertOk())->getSheet(0);

        return collect($ws->toArray(null, false, false))->flatten()->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (string) $v)->values()->all();
    }
}
