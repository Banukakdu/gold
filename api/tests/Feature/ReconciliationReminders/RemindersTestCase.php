<?php

namespace Tests\Feature\ReconciliationReminders;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BankReconciliation\BankReconciliationTestCase;

/**
 * docs/specs/reconciliation-overdue-reminder.md section 7 setup (AG-033f), on the AG-033 setup (BankReconciliationTestCase):
 *  - KDY Bank: last completed statement 2026-10-31 (ACC);
 *  - KDY Bank 5: last completed statement 2026-09-15 (ACC);
 *  - KDY Bank 2: first line 2026-11-05, no statement; KDY Bank 3: no lines; KDY Cash: a cash account;
 *  - GAL Bank: statements 2026-10-31 and 2026-11-30 (GACC);
 *  - MAIN Main Account: first line a capital line dated 2026-08-01, no statement.
 * Users: KDY ACC (Accountant), VW (Viewer), BA (Branch Admin), CSH (Cashier), VW2 (Viewer, inactive), REC (custom
 * "Reconciler" role with account.reconcile only); GAL GACC (Accountant); MAIN MBA (Branch Admin); Super Admins SA (the
 * seeded admin), SA2 (active), SA3 (inactive). The clock is frozen at 2026-12-11 01:45 UTC (07:15 Asia/Colombo).
 */
abstract class RemindersTestCase extends BankReconciliationTestCase
{
    protected const KIND = InAppNotification::RECONCILIATION_OVERDUE;

    protected const T = '2026-12-11 01:45:00';

    protected Account $bank2;

    protected Account $bank3;

    protected Account $bank5;

    protected BankStatement $kdySt;

    protected BankStatement $bank5St;

    protected BankStatement $galOct;

    protected BankStatement $galNov;

    protected User $vw2;

    protected User $rec;

    protected User $mba;

    protected User $sa2;

    protected User $sa3;

    protected function setUp(): void
    {
        parent::setUp();
        // MAIN Main Account: a capital line dated 2026-08-01 is its first line.
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '1000.00', 'txn_date' => '2026-08-01'])->assertCreated();

        $this->bank2 = $this->bankIn($this->kdy, 'KDY Bank 2');
        $this->bank3 = $this->bankIn($this->kdy, 'KDY Bank 3');
        $this->bank5 = $this->bankIn($this->kdy, 'KDY Bank 5');
        $this->allocate($this->bank2, '5000.00', '2026-11-05');

        $this->kdySt = $this->statementOne();
        $in5 = $this->income($this->acc, '8000.00', '2026-09-10', $this->bank5);
        $this->tick([[$in5, '2026-09-12']], $this->acc, [], $this->bank5)->assertOk();
        $this->bank5St = $this->statement($this->complete('2026-09-15', '8000.00', $this->acc, $this->bank5));

        $gal1 = $this->allocate($this->galBank, '100000.00', '2026-10-01');
        $this->tick([[$gal1, '2026-10-31']], $this->gacc, [], $this->galBank)->assertOk();
        $this->galOct = $this->statement($this->complete('2026-10-31', '100000.00', $this->gacc, $this->galBank));
        $gal2 = $this->allocate($this->galBank, '5000.00', '2026-11-10');
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->tick([[$gal2, '2026-11-15']], $this->gacc, [], $this->galBank)->assertOk();
        $this->galNov = $this->statement($this->complete('2026-11-30', '105000.00', $this->gacc, $this->galBank));

        $this->vw2 = $this->userIn($this->kdy, 'Viewer');
        $this->vw2->update(['status' => 'inactive']);
        $role = Role::create(['name' => 'Reconciler', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions(['account.reconcile']);
        $this->rec = User::factory()->create(['status' => 'active']);
        $this->rec->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $this->rec->assignRole($role);
        $this->mba = $this->userIn($this->main, 'Branch Admin');
        $this->sa2 = User::factory()->create(['status' => 'active', 'is_super_admin' => true]);
        $this->sa3 = User::factory()->create(['status' => 'inactive', 'is_super_admin' => true]);

        Carbon::setTestNow(Carbon::parse(self::T, 'UTC'));
    }

    protected function statement(TestResponse $r): BankStatement
    {
        return BankStatement::withoutGlobalScopes()->findOrFail($r->assertCreated()->json('id'));
    }

    /** Runs the command at $utc (null = now). Returns [exit code, output]. */
    protected function remind(?string $utc = null, array $options = []): array
    {
        if ($utc !== null) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        }
        $code = Artisan::call('reconciliation:remind-overdue', $options);

        return [$code, Artisan::output()];
    }

    /** Runs at a Colombo wall-clock time. */
    protected function remindAtColombo(string $local, array $options = []): array
    {
        return $this->remind(Carbon::parse($local, 'Asia/Colombo')->utc()->format('Y-m-d H:i:s'), $options);
    }

    protected function undoOn(Account $acc, BankStatement $s, User $by, string $reason = 'Wrong closing balance typed'): void
    {
        $this->as($by, Branch::find($acc->branch_id))->postJson($this->url($acc, "/statements/{$s->id}/undo"), ['reason' => $reason])->assertOk();
    }

    /** @return Collection<int, DatabaseNotification> the user's reminder rows, oldest first */
    protected function rows(User $u, ?Account $acc = null): Collection
    {
        return DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $u->id)->where('type', self::KIND)
            ->when($acc, fn ($q, $a) => $q->where('data->account_id', $a->id))->orderBy('created_at')->orderBy('id')->get();
    }

    protected function unread(User $u, ?Account $acc = null): Collection
    {
        return $this->rows($u, $acc)->whereNull('read_at')->values();
    }

    /** @return Collection<int, object> reminder table rows */
    protected function reminders(?Account $acc = null): Collection
    {
        return DB::table('bank_reconciliation_reminders')->when($acc, fn ($q, $a) => $q->where('account_id', $a->id))->orderBy('id')->get();
    }

    /** [account id, overdue_through, months, round] per reminder row. */
    protected function brief(?Collection $rows = null): array
    {
        return ($rows ?? $this->reminders())->map(fn ($r) => [(int) $r->account_id, substr((string) $r->overdue_through, 0, 10),
            (int) $r->months_overdue, (int) $r->round])->sortBy(fn ($x) => [$x[1], $x[0], $x[3]])->values()->all();
    }

    protected function counts(): array
    {
        return [
            'reminders' => DB::table('bank_reconciliation_reminders')->count(),
            'notifications' => DB::table('notifications')->where('type', self::KIND)->count(),
            'audit' => DB::table('audit_logs')->where('event', 'reconciliation.overdue_reminder_sent')->count(),
        ];
    }

    protected function ids(User ...$users): array
    {
        $ids = array_map(fn (User $u) => (int) $u->id, $users);
        sort($ids);

        return $ids;
    }

    /** Ids of the accounts with a reminder for overdue_through $e. */
    protected function remindedFor(string $e): array
    {
        $ids = DB::table('bank_reconciliation_reminders')->whereDate('overdue_through', $e)->distinct()->pluck('account_id')->map(fn ($v) => (int) $v)->all();
        sort($ids);

        return $ids;
    }
}
