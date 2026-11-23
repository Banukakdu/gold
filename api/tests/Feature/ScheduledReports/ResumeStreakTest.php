<?php

namespace Tests\Feature\ScheduledReports;

use App\Models\AuditLog;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleRun;
use App\Notifications\InAppNotification;
use App\Services\ScheduledReportRuns;
use App\Support\ReadSnapshot;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * docs/specs/scheduled-reports.md R10 / R15 "Resume is a streak boundary", AC 57: a run planned before the last resume
 * that fails later (stale, job error or timeout, owner lost, nothing sent) is recorded and alerted, but not counted and
 * never pauses the schedule. A run planned after the resume counts as usual.
 */
class ResumeStreakTest extends ScheduledReportsTestCase
{
    /** D1 with a run queued at 2026-11-21 07:00; SA pauses at 07:10 and $resumer resumes at 07:30. */
    private function queuedThenPausedAndResumed(array $over = [], $resumer = null): array
    {
        $d1 = $this->createD1($over);
        $this->dispatchAt('2026-11-21 07:00');
        $run = $this->latestRun($d1);
        $this->assertSame(ReportScheduleRun::QUEUED, $run->status);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:10', 'Asia/Colombo'));
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:30', 'Asia/Colombo'));
        $this->as($resumer ?? $this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/resume")->assertOk();
        $this->assertTrue($run->planned_at->lt($d1->fresh()->resumed_at));

        return [$d1, $run];
    }

    private function failedRow(ReportScheduleRun $run): AuditLog
    {
        return AuditLog::where('event', 'report_schedule.run_failed')->get()->filter(fn ($a) => $a->new_values['run_id'] === $run->id)->sole();
    }

    private function systemPauses(string $reason): int
    {
        return AuditLog::where('event', 'report_schedule.paused')->get()->filter(fn ($a) => $a->new_values['paused_reason'] === $reason)->count();
    }

    private function failBuild(?string $id = null): void
    {
        ReadSnapshot::$hook = fn (string $p) => $p === 'counted' ? throw new RuntimeException('boom') : null;
        $this->work($id);
        ReadSnapshot::$hook = null;
    }

    public function test_ac57_a_stale_run_planned_before_the_resume_is_not_counted(): void
    {
        [$d1, $run] = $this->queuedThenPausedAndResumed();

        // Never picked up: failed stale at 09:01 (more than 120 minutes after it was queued).
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:01', 'Asia/Colombo'));
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $run->refresh();
        $this->assertSame(ReportScheduleRun::FAILED, $run->status);
        $this->assertSame('stale', $run->failure_code);
        $d1->refresh();
        $this->assertSame(0, $d1->consecutive_failures);
        $this->assertNull($d1->last_failure_planned_at);
        $this->assertSame(ReportSchedule::ACTIVE, $d1->status);
        $row = $this->failedRow($run);
        $this->assertSame('stale', $row->new_values['code']);
        $this->assertFalse($row->new_values['counted']);
        $this->assertSame('planned_before_resume', $row->new_values['not_counted_reason']);
        // Still alerted as today.
        $this->assertCount(1, $this->bell($this->admin, InAppNotification::SCHEDULE_RUN_FAILED));
        $this->assertCount(1, $this->problemMailsTo($this->admin));

        // A run planned after the resume that fails stale counts.
        $this->dispatchAt('2026-11-22 07:00');
        $next = $this->latestRun($d1);
        $this->travelTo(CarbonImmutable::parse('2026-11-22 09:01', 'Asia/Colombo'));
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $this->assertSame('stale', $next->fresh()->failure_code);
        $d1->refresh();
        $this->assertSame(1, $d1->consecutive_failures);
        $this->assertTrue($d1->last_failure_planned_at->eq($next->planned_at));
        $row = $this->failedRow($next);
        $this->assertTrue($row->new_values['counted']);
        $this->assertNull($row->new_values['not_counted_reason']);
        $this->assertCount(2, $this->bell($this->admin, InAppNotification::SCHEDULE_RUN_FAILED));
    }

    public function test_ac57_a_job_failure_after_the_resume_never_pauses_the_resumed_schedule(): void
    {
        config(['scheduled_reports.failures_before_pause' => 1]);
        [$d1, $run] = $this->queuedThenPausedAndResumed();

        // The worker picks up the old run after the resume; both attempts fail, failed() records it.
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:35', 'Asia/Colombo'));
        $this->failBuild($run->id);
        $this->assertSame('error', $run->fresh()->failure_code);
        $d1->refresh();
        $this->assertSame(ReportSchedule::ACTIVE, $d1->status);
        $this->assertSame(0, $d1->consecutive_failures);
        $this->assertNotNull($d1->next_run_at);
        $this->assertFalse($this->failedRow($run)->new_values['counted']);
        $this->assertSame(0, $this->systemPauses('repeated_failures'));
        $this->assertCount(1, $this->bell($this->admin, InAppNotification::SCHEDULE_RUN_FAILED));

        // The same failure of a run planned after the resume counts and, at the limit, pauses.
        $this->dispatchAt('2026-11-22 07:00');
        $next = $this->latestRun($d1);
        $this->failBuild($next->id);
        $d1->refresh();
        $this->assertSame(1, $d1->consecutive_failures);
        $this->assertSame(ReportSchedule::PAUSED, $d1->status);
        $this->assertSame('repeated_failures', $d1->paused_reason);
        $this->assertTrue($this->failedRow($next)->new_values['counted']);
    }

    public function test_ac57_owner_lost_on_a_run_planned_before_the_resume_does_not_pause_again(): void
    {
        // SA leaves; SA2 resumes and becomes the owner. The queued run still carries SA as its owner.
        $d1 = $this->createD1();
        $this->admin->update(['status' => 'inactive']);
        $this->dispatchAt('2026-11-21 07:00');
        $run = $this->latestRun($d1);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:10', 'Asia/Colombo'));
        $this->as($this->sa2)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:30', 'Asia/Colombo'));
        $this->as($this->sa2)->postJson("/api/v1/report-schedules/{$d1->id}/resume")->assertOk()->assertJsonPath('owner.id', $this->sa2->id);

        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:35', 'Asia/Colombo'));
        $this->work($run->id);
        $run->refresh();
        $this->assertSame('owner_lost_access', $run->failure_code);
        $this->assertSame(ScheduledReportRuns::OWNER_LOST_BEFORE_RESUME, $run->error);
        $d1->refresh();
        $this->assertSame(ReportSchedule::ACTIVE, $d1->status);
        $this->assertNull($d1->paused_reason);
        $this->assertSame(0, $d1->consecutive_failures);
        $this->assertSame(0, $this->systemPauses('owner_lost_access'));
        $row = $this->failedRow($run);
        $this->assertFalse($row->new_values['counted']);
        $this->assertSame('planned_before_resume', $row->new_values['not_counted_reason']);
        // No pause alert, so the new owner gets the run_failed alert instead.
        $this->assertCount(0, $this->bell($this->sa2, InAppNotification::SCHEDULE_PAUSED));
        $this->assertCount(1, $this->bell($this->sa2, InAppNotification::SCHEDULE_RUN_FAILED));
        $this->assertCount(1, $this->problemMailsTo($this->sa2));
    }

    public function test_ac57_nothing_sent_for_a_run_planned_before_the_resume_is_not_counted(): void
    {
        [$d1, $run] = $this->queuedThenPausedAndResumed(['recipient_ids' => [$this->ak->id]]);
        $this->ak->update(['status' => 'inactive']);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:35', 'Asia/Colombo'));
        $this->work($run->id);
        $this->assertSame(ReportScheduleRun::NOTHING_SENT, $run->fresh()->delivery_outcome);
        $this->assertSame(0, $d1->fresh()->consecutive_failures);
        $built = AuditLog::where('event', 'report_schedule.run_built')->sole();
        $this->assertTrue($built->new_values['nothing_sent']);
        $this->assertFalse($built->new_values['counted']);
        $this->assertSame('planned_before_resume', $built->new_values['not_counted_reason']);
    }

    public function test_ac57_missed_runs_are_never_planned_before_the_resume_and_count(): void
    {
        [$d1] = $this->queuedThenPausedAndResumed();
        // The dispatcher is down until 14:00 the next day: the 07:00 run is more than 6 h late.
        $this->dispatchAt('2026-11-22 14:00');
        $missed = ReportScheduleRun::where('schedule_id', $d1->id)->where('status', ReportScheduleRun::MISSED)->sole();
        $d1->refresh();
        $this->assertTrue($missed->planned_at->gte($d1->resumed_at));
        $this->assertSame(1, $d1->consecutive_failures);
    }
}
