<?php

namespace Tests\Feature\ScheduledReports;

use App\Models\AuditLog;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Services\ScheduledReportRuns;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * R15 migration 2026_12_05_000000_report_schedule_run_settlement (review S2, N1, N2): the backfill on an existing
 * install, the frozen settled columns, a resumable up() and a clean down(). No RefreshDatabase: the test changes the
 * schema and MySQL commits DDL. It starts from migrate:fresh and, on MySQL, leaves an empty migrated database.
 */
class SettlementMigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_05_000000_report_schedule_run_settlement.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    private function rollback(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::FILE, '--force' => true])->assertExitCode(0);
    }

    private function oldRun(int $schedule, int $owner, string $planned, string $status, array $deliveries = []): string
    {
        $id = (string) Str::ulid();
        $day = substr($planned, 0, 10);
        DB::table('report_schedule_runs')->insert(['id' => $id, 'schedule_id' => $schedule, 'planned_at' => $planned, 'period_from' => $day,
            'period_to' => $day, 'status' => $status, 'owner_id' => $owner, 'report' => 'income-expense', 'params' => '{}', 'branch_ids' => '[]',
            'attached' => false, 'attempts' => 1, 'finished_at' => $status === 'queued' ? null : $planned, 'created_at' => $planned, 'updated_at' => $planned]);
        foreach ($deliveries as $status) {
            $user = DB::table('users')->insertGetId(['name' => 'R '.Str::random(6), 'email' => Str::random(10).'@gold.example', 'password' => 'x',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('report_schedule_deliveries')->insert(['run_id' => $id, 'user_id' => $user, 'email' => "u{$user}@gold.example", 'status' => $status,
                'reason' => $status === 'failed' ? 'mail_error' : null, 'attempts' => 1, 'queued_at' => $planned, 'created_at' => $planned, 'updated_at' => $planned]);
        }

        return $id;
    }

    public function test_backfill_trigger_resumable_up_and_down(): void
    {
        $this->rollback();
        foreach (['settled_at', 'delivery_outcome'] as $c) {
            $this->assertFalse(Schema::hasColumn('report_schedule_runs', $c));
        }
        $this->assertFalse(Schema::hasColumn('report_schedules', 'resumed_at'));

        // An install before this release: schedule S (streak 2, paused, resumed once), its history and one run in flight.
        $owner = (int) DB::table('users')->value('id');
        $s = DB::table('report_schedules')->insertGetId(['name' => 'Old schedule', 'report' => 'income-expense', 'params' => '{}', 'branch_ids' => '[]',
            'frequency' => 'daily', 'send_time' => '07:00', 'status' => 'paused', 'paused_reason' => 'manual', 'owner_id' => $owner,
            'created_by' => $owner, 'consecutive_failures' => 2, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        DB::table('audit_logs')->insert(['event' => 'report_schedule.resumed', 'auditable_type' => ReportSchedule::class, 'auditable_id' => $s,
            'user_id' => $owner, 'created_at' => '2026-11-10 03:00:00']);
        $allFailed = $this->oldRun($s, $owner, '2026-11-15 01:30:00', 'done', ['failed', 'failed']);
        $sent = $this->oldRun($s, $owner, '2026-11-16 01:30:00', 'done', ['sent', 'skipped']);
        $failed = $this->oldRun($s, $owner, '2026-11-17 01:30:00', 'failed');
        $missed = $this->oldRun($s, $owner, '2026-11-18 01:30:00', 'missed');
        $open = $this->oldRun($s, $owner, '2026-11-19 01:30:00', 'done', ['failed', 'queued']);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $runs = DB::table('report_schedule_runs')->get()->keyBy('id');
        // Finished runs whose deliveries are final: settled with no outcome, so they are never counted.
        $this->assertNotNull($runs[$allFailed]->settled_at);
        $this->assertNull($runs[$allFailed]->delivery_outcome);
        $this->assertNotNull($runs[$sent]->settled_at);
        // In flight, failed and missed runs: untouched.
        foreach ([$open, $failed, $missed] as $id) {
            $this->assertNull($runs[$id]->settled_at, $id);
            $this->assertNull($runs[$id]->delivery_outcome, $id);
        }
        $row = DB::table('report_schedules')->find($s);
        $this->assertStringStartsWith('2026-11-10 03:00:00', (string) $row->resumed_at);
        $this->assertStringStartsWith('2026-11-18 01:30:00', (string) $row->last_failure_planned_at);

        // The sweep counts nothing from history; the run in flight settles normally when its last delivery fails.
        $svc = app(ScheduledReportRuns::class);
        $this->assertSame(0, $svc->settleOpenRuns());
        $this->assertSame(2, (int) DB::table('report_schedules')->where('id', $s)->value('consecutive_failures'));
        $queued = ReportScheduleDelivery::where('run_id', $open)->where('status', 'queued')->sole();
        $this->assertTrue($svc->deliveryFailed($queued->id, 'stale', ['queued']));
        $this->assertSame(ReportScheduleRun::NOT_DELIVERED, ReportScheduleRun::find($open)->delivery_outcome);
        $this->assertSame(3, (int) DB::table('report_schedules')->where('id', $s)->value('consecutive_failures'));
        $this->assertSame([$open], AuditLog::where('event', 'report_schedule.run_not_delivered')->get()->map(fn ($a) => $a->new_values['run_id'])->all());
        $this->assertSame(0, $svc->settleOpenRuns());

        // Review N1: settled_at and delivery_outcome are frozen once set; an unset one can still be written.
        foreach ([['settled_at' => null], ['settled_at' => '2026-11-20 00:00:00'], ['delivery_outcome' => 'delivered']] as $change) {
            try {
                DB::table('report_schedule_runs')->where('id', $open)->update($change);
                $this->fail('Changed a settled run: '.json_encode($change));
            } catch (QueryException $e) {
                $this->assertStringContainsString('evidence', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('report_schedule_runs')->where('id', $allFailed)->update(['delivery_outcome' => 'not_delivered']));
        // The 2026_12_03 rules still hold.
        $this->expectRefused(fn () => DB::table('report_schedule_runs')->where('id', $failed)->update(['period_from' => '2026-01-01']));

        // Review N2: up() again (a partial or repeated run) changes nothing and does not fail.
        $before = DB::table('report_schedule_runs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        (require base_path(self::FILE))->up();
        $this->assertEquals($before, DB::table('report_schedule_runs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());

        // down(): the columns and the index go, the old trigger is back; a missing index does not stop it.
        $this->rollback();
        $this->assertFalse(Schema::hasColumn('report_schedule_runs', 'settled_at'));
        $this->assertFalse(Schema::hasColumn('report_schedule_runs', 'delivery_outcome'));
        $this->assertFalse(Schema::hasColumn('report_schedules', 'resumed_at'));
        $this->assertFalse(Schema::hasColumn('report_schedules', 'last_failure_planned_at'));
        $this->assertNotContains('rs_runs_status_settled_index', array_column(Schema::getIndexes('report_schedule_runs'), 'name'));
        $this->expectRefused(fn () => DB::table('report_schedule_runs')->where('id', $failed)->update(['period_from' => '2026-01-01']));
        $this->assertSame(1, DB::table('report_schedule_runs')->where('id', $failed)->update(['error' => 'x']));
        (require base_path(self::FILE))->down();

        // A partial up() (columns there, index missing) is completed by the next migrate.
        Schema::table('report_schedule_runs', fn ($t) => $t->timestamp('settled_at')->nullable());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('report_schedule_runs', 'delivery_outcome'));
        $this->assertContains('rs_runs_status_settled_index', array_column(Schema::getIndexes('report_schedule_runs'), 'name'));
        $this->assertTrue(Schema::hasColumn('report_schedules', 'last_failure_planned_at'));
    }

    private function expectRefused(callable $fn): void
    {
        try {
            $fn();
            $this->fail('The change was not refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }
    }
}
