<?php

namespace Tests\Feature\ReconciliationReminders;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\BankReconciliation;
use App\Services\InAppNotifications;
use App\Services\ReconciliationReminders;
use App\Services\YearEnd;
use App\Support\BranchContext;
use App\Support\ReportParams;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/**
 * AG-033f (docs/specs/reconciliation-overdue-reminder.md section 7): AC 13 (API side of the open flow), 15, 16, 18, 20,
 * 21, 22, the never-updated / never-deleted reminder table and the Complete hook that must never fail the Complete.
 */
class OperationsTest extends RemindersTestCase
{
    public function test_ac13_open_flow_marks_read_and_s7_shows_the_overdue_rows(): void
    {
        $this->remind();
        $n = $this->rows($this->acc, $this->bank5)->sole();
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/notifications/{$n->id}/read")->assertOk();
        $this->assertNotNull($n->fresh()->read_at);
        $r = $this->as($this->acc, $this->kdy)->getJson('/api/v1/reports/reconciliation-status?status=overdue')->assertOk();
        $rows = collect($r->json('data'));
        $ids = $rows->pluck('account.id')->sort()->values()->all();
        $expected = [$this->kdyBank->id, $this->bank2->id, $this->bank5->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(['overdue'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(['KDY'], $rows->pluck('branch.code')->unique()->values()->all());

        // SA in All branches mode opens the MAIN row: the web switches to MAIN (AG-094 F6), and S7 there lists it.
        $main = $this->rows($this->admin, $this->mainAcc)->sole();
        $this->assertSame($this->main->id, $main->data['branch_id']);
        $row = collect($this->sa($this->main)->getJson('/api/v1/reports/reconciliation-status?status=overdue')->assertOk()->json('data'))->sole();
        $this->assertSame([$this->mainAcc->id, 'overdue', 4], [$row['account']['id'], $row['status'], $row['months_overdue']]);
    }

    public function test_ac15_one_system_audit_row_per_reminder(): void
    {
        $this->remind();
        $rows = AuditLog::where('event', 'reconciliation.overdue_reminder_sent')->orderBy('id')->get();
        $this->assertCount(4, $rows);
        foreach ($rows as $a) {
            $acc = Account::withoutGlobalScopes()->find($a->auditable_id);
            $this->assertNull($a->user_id);
            $this->assertSame(Account::class, $a->auditable_type);
            $this->assertSame((int) $acc->branch_id, (int) $a->branch_id);
            $this->assertSame('console', $a->user_agent);
            foreach (['account', 'overdue_through', 'months_overdue', 'never_reconciled', 'last_statement_date', 'recipient_ids', 'super_admin_fallback', 'round'] as $k) {
                $this->assertArrayHasKey($k, $a->new_values);
            }
            $this->assertTrue($a->new_values['by_system']);
            $this->assertSame('2026-11-30', $a->new_values['overdue_through']);
            $this->assertArrayNotHasKey('undone_statement_id', $a->new_values);
        }
        $five = $rows->firstWhere('auditable_id', $this->bank5->id);
        $this->assertSame('KDY Bank 5 ('.$this->bank5->code.')', $five->new_values['account']);
        $this->assertSame($this->ids($this->acc, $this->vw, $this->ba), $five->new_values['recipient_ids']);
        $this->assertFalse($five->new_values['super_admin_fallback']);
        $this->assertFalse($five->new_values['never_reconciled']);
        $this->assertSame([3, '2026-09-15', 1], [$five->new_values['months_overdue'], $five->new_values['last_statement_date'], $five->new_values['round']]);
        $main = $rows->firstWhere('auditable_id', $this->mainAcc->id);
        $this->assertTrue($main->new_values['never_reconciled']);
        $this->assertNull($main->new_values['last_statement_date']);
        $this->assertSame($this->ids($this->mba, $this->admin, $this->sa2), $main->new_values['recipient_ids']);

        // BA (audit.view) sees the 3 KDY rows in the Bank reconciliation group, labelled, user "System"; not the MAIN row.
        $list = $this->as($this->ba, $this->kdy)->getJson('/api/v1/audit-logs?'.http_build_query(['from' => '2026-12-11', 'to' => '2026-12-11',
            'event_group' => 'bank_reconciliation']))->assertOk()->json('data');
        $mine = collect($list)->where('event', 'reconciliation.overdue_reminder_sent');
        $this->assertCount(3, $mine);
        foreach ($mine as $r) {
            $this->assertSame('Overdue reconciliation reminder sent', $r['event_label']);
            $this->assertTrue($r['system']);
            $this->assertNull($r['user']);
            $this->assertSame('KDY', $r['branch']['code']);
        }
        $this->assertNotContains($this->mainAcc->id, $mine->pluck('auditable_id')->all());

        // Reading, read-all and the R7 auto-read write no audit row.
        $max = (int) AuditLog::max('id');
        $this->as($this->ba, $this->kdy)->postJson('/api/v1/notifications/'.$this->rows($this->ba)->first()->id.'/read')->assertOk();
        $this->as($this->vw, $this->kdy)->postJson('/api/v1/notifications/read-all')->assertOk();
        app(ReconciliationReminders::class)->closeFor($this->bank2->id, '2026-11-30');
        $this->assertSame(0, AuditLog::where('id', '>', $max)->count());
        $this->assertSame(0, $this->unread($this->acc, $this->bank2)->count());
    }

    public function test_ac16_the_command_posts_nothing(): void
    {
        $tables = ['ledger_entries', 'transactions', 'bank_reconciliation_marks', 'bank_statements', 'bank_statement_checks', 'settings'];
        $snap = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy($t === 'settings' ? 'key' : 'id')->get()->map(fn ($r) => (array) $r)->all()])
            ->put('balances', DB::table('accounts')->orderBy('id')->pluck('current_balance', 'id')->all())->all();
        $before = $snap();
        foreach (['2026-12-10', '2026-12-11', '2026-12-16', '2026-12-31', '2027-01-11'] as $day) {
            $this->remindAtColombo("{$day} 07:15");
        }
        $this->remind(null, ['--dry-run' => true]);
        $this->assertSame($before, $snap());
        $this->assertVerifies();
    }

    public function test_ac18_one_failing_account_does_not_stop_the_others(): void
    {
        Exceptions::fake();
        $target = $this->bank2->id;
        $this->app->instance(InAppNotifications::class, new class($target) extends InAppNotifications
        {
            public function __construct(private int $target) {}

            public function write(User $user, string $kind, array $data): string
            {
                if (($data['account_id'] ?? null) === $this->target) {
                    throw new \RuntimeException('notifications insert failed');
                }

                return parent::write($user, $kind, $data);
            }
        });
        [$code, $out] = $this->remind();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Sent 3 reminder(s), skipped 0 already sent, 1 failed.', $out);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'notifications insert failed');
        foreach ([$this->kdyBank, $this->bank5, $this->mainAcc] as $ok) {
            $this->assertCount(1, $this->reminders($ok));
        }
        $this->assertCount(0, $this->reminders($this->bank2));
        $this->assertSame(0, DB::table('notifications')->where('type', self::KIND)->where('data->account_id', $target)->count());
        $this->assertSame(0, AuditLog::where('event', 'reconciliation.overdue_reminder_sent')->where('auditable_id', $target)->count());

        $this->app->forgetInstance(InAppNotifications::class);
        [$code, $out] = $this->remind();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Sent 1 reminder(s), skipped 3 already sent, 0 failed.', $out);
        $this->assertCount(1, $this->reminders($this->bank2));
        $this->assertCount(1, $this->rows($this->acc, $this->bank2));
    }

    public function test_ac20_schedule_and_no_queue(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'reconciliation:remind-overdue'));
        $this->assertNotNull($event);
        $this->assertSame('15 7 * * *', $event->expression);
        $this->assertSame('Asia/Colombo', $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        config(['queue.default' => 'database']);
        $jobs = DB::table('jobs')->count();
        $this->remind();
        $this->assertSame($jobs, DB::table('jobs')->count());
        $this->assertCount(3, $this->unread($this->acc));
        $this->assertCount(1, $this->unread($this->admin));
    }

    public function test_ac21_a_run_that_lost_the_race_sends_nothing(): void
    {
        // Another run (or server) inserts and commits the same reminder between this run's read and its insert: the
        // unique key and insertOrIgnore stop the second one, so each reminder exists once.
        $this->app->bind(ReconciliationReminders::class, fn ($app) => new class($app->make(BankReconciliation::class), $app->make(InAppNotifications::class)) extends ReconciliationReminders
        {
            protected function send(array $plan): string
            {
                parent::send($plan); // the other run

                return parent::send($plan);
            }
        });
        [$code, $out] = $this->remind();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Sent 0 reminder(s), skipped 4 already sent, 0 failed.', $out);
        $this->assertSame(['reminders' => 4, 'notifications' => 3 * 3 + 3, 'audit' => 4], $this->counts());
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertCount(3, $this->rows($u));
        }
    }

    public function test_ac21_the_key_refuses_a_second_row_for_the_same_round(): void
    {
        $this->remind();
        $r = (array) $this->reminders($this->bank5)->sole();
        unset($r['id']);
        $this->expectException(QueryException::class);
        DB::table('bank_reconciliation_reminders')->insert($r);
    }

    public function test_ac22_pruned_bell_rows_are_not_resent(): void
    {
        $this->remind();
        DB::table('notifications')->update(['created_at' => now()->subDays(91)]);
        $this->artisan('notifications:prune')->assertExitCode(0);
        $this->assertSame(0, DB::table('notifications')->count());
        [, $out] = $this->remindAtColombo('2026-12-20 07:15');
        $this->assertStringContainsString('Sent 0 reminder(s), skipped 4 already sent, 0 failed.', $out);
        $this->assertSame(['reminders' => 4, 'notifications' => 0, 'audit' => 4], $this->counts());
    }

    public function test_reminder_rows_are_never_updated_or_deleted(): void
    {
        $this->remind();
        $id = $this->reminders()->first()->id;
        foreach ([
            fn () => DB::table('bank_reconciliation_reminders')->where('id', $id)->update(['months_overdue' => 9]),
            fn () => DB::table('bank_reconciliation_reminders')->where('id', $id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The change was not refused.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('bank_reconciliation_reminders rows are evidence', $e->getMessage());
            }
        }
        $this->assertCount(4, $this->reminders());
        $this->assertSame(3, (int) $this->reminders($this->bank5)->sole()->months_overdue);
    }

    public function test_a_failing_close_never_fails_the_complete(): void
    {
        $this->remind();
        Exceptions::fake();
        $this->app->bind(ReconciliationReminders::class, fn ($app) => new class($app->make(BankReconciliation::class), $app->make(InAppNotifications::class)) extends ReconciliationReminders
        {
            public function closeFor(int $accountId, string $statementDate): int
            {
                throw new \RuntimeException('close failed');
            }
        });
        Carbon::setTestNow(Carbon::parse('2026-12-12 09:00', 'Asia/Colombo'));
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();
        $this->statement($this->complete('2026-11-30', '469500.00'));
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'close failed');
        $this->assertNull($this->rows($this->acc, $this->kdyBank)->sole()->read_at);
    }

    public function test_s1_an_account_completed_during_the_run_gets_no_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-11 07:00', 'Asia/Colombo'));
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();
        Carbon::setTestNow(Carbon::parse(self::T, 'UTC'));
        $kdyBank = $this->kdyBank;
        $acc = $this->acc;
        // ACC completes KDY Bank to E after status() was read and before this account's send().
        $this->app->bind(ReconciliationReminders::class, fn ($app) => new class($app->make(BankReconciliation::class), $app->make(InAppNotifications::class), $kdyBank, $acc) extends ReconciliationReminders
        {
            public function __construct(BankReconciliation $r, InAppNotifications $n, private Account $target, private User $by)
            {
                parent::__construct($r, $n);
            }

            protected function send(array $plan): string
            {
                if ((int) $plan['account']->id === (int) $this->target->id) {
                    app(BankReconciliation::class)->complete($this->target, '2026-11-30', '469500.00', $this->by);
                }

                return parent::send($plan);
            }
        });
        [$code, $out] = $this->remind();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('reconciled during the run, not sent', $out);
        $this->assertStringContainsString('Sent 3 reminder(s), skipped 1 already sent, 0 failed.', $out);
        $this->assertCount(0, $this->reminders($this->kdyBank));
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertCount(0, $this->rows($u, $this->kdyBank));
            $this->assertCount(2, $this->unread($u));
        }
        $this->assertSame(0, AuditLog::where('event', 'reconciliation.overdue_reminder_sent')->where('auditable_id', $this->kdyBank->id)->count());
    }

    public function test_s2_the_branch_context_is_restored(): void
    {
        $ctx = app(BranchContext::class);
        $ctx->set($this->kdy->id, false);
        $r = app(ReconciliationReminders::class)->run();
        $this->assertSame(4, $r['sent']);
        $this->assertSame([$this->kdy->id, false], [$ctx->branchId, $ctx->allBranches]);

        $ctx->set(null, true);
        $this->app->bind(BankReconciliation::class, fn ($app) => new class($app->make(YearEnd::class)) extends BankReconciliation
        {
            public function status(ReportParams $p): array
            {
                throw new \RuntimeException('status failed');
            }
        });
        try {
            app(ReconciliationReminders::class)->run();
            $this->fail('The exception was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('status failed', $e->getMessage());
        }
        $this->assertSame([null, true], [$ctx->branchId, $ctx->allBranches]);
    }

    public function test_n6_n7_counts_and_names_only_when_asked(): void
    {
        $r = app(ReconciliationReminders::class)->run(true);
        $this->assertSame([4, 0, 0, 0], [$r['would'], $r['sent'], $r['skipped'], $r['failed']]);
        [, $out] = $this->remind();
        $this->assertStringContainsString(': sent to 3 user(s)', $out);
        $this->assertStringNotContainsString("{$this->acc->name} (#{$this->acc->id})", $out);
    }

    public function test_n8_a_rolled_back_complete_leaves_the_reminders_unread(): void
    {
        $this->remind();
        Carbon::setTestNow(Carbon::parse('2026-12-12 09:00', 'Asia/Colombo'));
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();
        try {
            DB::transaction(function () {
                app(BankReconciliation::class)->complete($this->kdyBank, '2026-11-30', '469500.00', $this->acc);
                throw new \RuntimeException('outer failure');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('bank_statements')->where('account_id', $this->kdyBank->id)->whereDate('statement_date', '2026-11-30')->count());
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertNull($this->rows($u, $this->kdyBank)->sole()->read_at);
        }
    }

    public function test_n8_inactive_branch_included_and_inactive_role_does_not_qualify(): void
    {
        DB::table('branches')->where('id', $this->gal->id)->update(['status' => 'inactive']);
        DB::table('roles')->where('branch_id', $this->kdy->id)->where('name', 'Viewer')->update(['status' => 'inactive']);
        Carbon::setTestNow(Carbon::parse('2026-12-15 10:00', 'Asia/Colombo'));
        $this->undoOn($this->galBank, $this->galNov, $this->gacc);
        $this->remindAtColombo('2026-12-16 07:15');
        $this->assertCount(1, $this->reminders($this->galBank));
        $this->assertCount(1, $this->rows($this->gacc, $this->galBank));
        $this->assertCount(0, $this->rows($this->vw));
        $this->assertCount(3, $this->rows($this->acc));
    }
}
