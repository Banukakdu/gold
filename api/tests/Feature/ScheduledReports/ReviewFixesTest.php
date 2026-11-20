<?php

namespace Tests\Feature\ScheduledReports;

use App\Jobs\RunScheduledReport;
use App\Models\AuditLog;
use App\Models\Export;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Notifications\InAppNotification;
use App\Notifications\ScheduledReportMail;
use App\Services\InAppNotifications;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Services\ScheduledReportRuns;
use App\Services\ScheduledReports;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * AG-088 review (S1-S4, N1, N3-N5, N7) and QA (D4, D7) fixes.
 */
class ReviewFixesTest extends ScheduledReportsTestCase
{
    private function d1Run(?ReportSchedule $d1 = null): ReportScheduleRun
    {
        $d1 ??= $this->createD1();
        $this->dispatchAt('2026-11-21 07:00');

        return $this->latestRun($d1);
    }

    private function statuses(ReportScheduleRun $run): array
    {
        return ReportScheduleDelivery::where('run_id', $run->id)->orderBy('user_id')->pluck('status', 'user_id')->all();
    }

    /** @return SendQueuedNotifications[] the scheduled report mail jobs queued so far */
    private function mailJobs(): array
    {
        return $this->queuedNotifications(ScheduledReportMail::class);
    }

    private function reportEmails(): array
    {
        return array_values(array_filter($this->sentEmails(), fn ($m) => str_contains((string) $m->getSubject(), 'Income & Expense')));
    }

    // ------------------------------------------------------------------ S1: an email is sent at most once

    public function test_s1_a_second_run_of_the_same_mail_job_sends_nothing(): void
    {
        $run = $this->d1Run();
        $this->work();
        $this->sendMails();
        $this->assertCount(2, $this->reportEmails());
        $this->assertSame(['sent', 'sent'], array_values($this->statuses($run)));
        // The queue hands the same jobs out again (retry_after passed during a slow send, or a retry).
        foreach ($this->mailJobs() as $job) {
            $job->handle(app(ChannelManager::class));
        }
        $this->assertCount(2, $this->reportEmails());
        $this->assertSame(2, AuditLog::where('event', 'report_schedule.delivered')->count());
    }

    public function test_s1_a_delivery_being_sent_is_not_sent_by_another_job(): void
    {
        $run = $this->d1Run();
        $this->work();
        $d = ReportScheduleDelivery::where('run_id', $run->id)->where('user_id', $this->ak->id)->sole();
        // Another worker claimed it and is talking to the mail server.
        DB::table('report_schedule_deliveries')->where('id', $d->id)->update(['status' => 'sending', 'sending_at' => now()]);
        $this->sendMails();
        $this->assertCount(1, $this->reportEmails());
        $this->assertSame('sa@gold.example', $this->reportEmails()[0]->getTo()[0]->getAddress());
        $this->assertSame('sending', $d->fresh()->status);
    }

    public function test_s1_recording_sent_fails_after_the_email_went_out_no_resend_then_unknown(): void
    {
        $run = $this->d1Run();
        $this->work();
        $failing = new class(app(ScheduledReports::class), app(ReportService::class), app(ReportExporter::class), app(InAppNotifications::class)) extends ScheduledReportRuns
        {
            public function markSent(int $deliveryId): void
            {
                throw new RuntimeException('Deadlock found when trying to get lock');
            }
        };
        $this->app->instance(ScheduledReportRuns::class, $failing);
        $this->sendMails();
        // Each email went out once; the job did not fail, so the queue does not retry it.
        $this->assertCount(2, $this->reportEmails());
        $this->assertSame(['sending', 'sending'], array_values($this->statuses($run)));
        foreach ($this->mailJobs() as $job) {
            $job->handle(app(ChannelManager::class));
        }
        $this->assertCount(2, $this->reportEmails());
        $this->app->forgetInstance(ScheduledReportRuns::class);

        // Not yet stale at 60 minutes; after that it is failed as `unknown`, never sent again.
        $this->travel(60)->minutes();
        app(ScheduledReportRuns::class)->failStaleDeliveries();
        $this->assertSame(['sending', 'sending'], array_values($this->statuses($run)));
        $this->travel(1)->minutes();
        $this->assertSame(2, app(ScheduledReportRuns::class)->failStaleDeliveries());
        $this->assertSame(['failed', 'failed'], array_values($this->statuses($run)));
        $this->assertSame(['unknown', 'unknown'], ReportScheduleDelivery::where('run_id', $run->id)->pluck('reason')->all());
        $this->assertSame(2, AuditLog::where('event', 'report_schedule.delivery_failed')->count());
        $bell = $this->bell($this->admin, InAppNotification::SCHEDULE_DELIVERY_FAILED);
        $this->assertCount(2, $bell);
        $this->assertStringContainsString('may have been sent', (string) json_decode($bell[0]->data, true)['body']);
        $this->as($this->admin)->getJson("/api/v1/report-schedules/{$run->schedule_id}/runs")->assertOk()
            ->assertJsonPath('data.0.deliveries.0.reason_text', ScheduledReports::REASONS['unknown']);
        foreach ($this->mailJobs() as $job) {
            $job->handle(app(ChannelManager::class));
        }
        $this->assertCount(2, $this->reportEmails());
    }

    public function test_s1_a_transport_error_releases_the_claim_and_the_retry_sends_once(): void
    {
        $transport = new class extends AbstractTransport
        {
            public array $sent = [];

            public int $failures = 0;

            protected function doSend(SentMessage $message): void
            {
                if ($this->failures === 0) {
                    $this->failures++;
                    throw new TransportException('421 try again later');
                }
                $this->sent[] = $message;
            }

            public function __toString(): string
            {
                return 'once://';
            }
        };
        Mail::extend('once', fn () => $transport);
        config(['mail.mailers.once' => ['transport' => 'once'], 'mail.default' => 'once']);
        app('mail.manager')->forgetMailers();

        $run = $this->d1Run();
        $this->work();
        $this->sendMails();
        $this->assertSame(1, $transport->failures);
        $this->assertCount(2, $transport->sent);
        $this->assertSame(['sent', 'sent'], array_values($this->statuses($run)));
        $this->assertSame(2, ReportScheduleDelivery::where('run_id', $run->id)->where('user_id', $this->admin->id)->value('attempts'));
        $this->assertSame(0, AuditLog::where('event', 'report_schedule.delivery_failed')->count());
    }

    public function test_s1_file_kept_while_a_delivery_is_sending(): void
    {
        $run = $this->d1Run();
        $this->work();
        DB::table('report_schedule_deliveries')->where('run_id', $run->id)->update(['status' => 'sending', 'sending_at' => now()]);
        $this->travel(8)->days();
        DB::table('report_schedule_deliveries')->where('run_id', $run->id)->update(['sending_at' => now()]);
        $this->assertSame(0, app(ScheduledReportRuns::class)->pruneFiles());
        $this->assertNotNull($run->fresh()->file_path);
    }

    // ------------------------------------------------------------------ S2: missed + pause keeps the on-time time

    public function test_s2_missed_run_that_pauses_records_the_on_time_planned_time(): void
    {
        $d1 = $this->createD1();
        $d1->forceFill(['consecutive_failures' => 2])->save();
        // The dispatcher is down from 2026-11-21 06:00 to 2026-11-23 09:00.
        $this->dispatchAt('2026-11-23 09:00');
        $runs = ReportScheduleRun::where('schedule_id', $d1->id)->get();
        $this->assertCount(1, $runs);
        $missed = $runs->sole();
        $this->assertSame('missed', $missed->status);
        $this->assertSame(3, $missed->missed_count);
        $this->assertSame(['2026-11-20', '2026-11-22'], [$missed->periodFrom(), $missed->periodTo()]);
        $audit = AuditLog::where('event', 'report_schedule.run_missed')->sole();
        $this->assertSame(3, $audit->new_values['missed_count']);
        $this->assertEquals(['from' => '2026-11-20', 'to' => '2026-11-22'], $audit->new_values['period']);
        $d1->refresh();
        $this->assertSame('paused', $d1->status);
        $this->assertSame('repeated_failures', $d1->paused_reason);
        Queue::assertNotPushed(RunScheduledReport::class);
    }

    public function test_s2_missed_run_that_does_not_pause_still_queues_the_on_time_run(): void
    {
        $d1 = $this->createD1();
        $this->dispatchAt('2026-11-23 09:00');
        $this->assertSame(['missed', 'queued'], ReportScheduleRun::where('schedule_id', $d1->id)->orderBy('planned_at')->pluck('status')->all());
        $this->assertSame(2, ReportScheduleRun::where('status', 'missed')->value('missed_count'));
    }

    // ------------------------------------------------------------------ S3 / D4: stale runs

    public function test_d4_a_run_is_stale_only_after_more_than_120_minutes(): void
    {
        $run = $this->d1Run();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:00:00', 'Asia/Colombo'));
        $this->assertSame(0, app(ScheduledReportRuns::class)->failStaleRuns());
        $this->assertSame('queued', $run->fresh()->status);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:00:01', 'Asia/Colombo'));
        $this->assertSame(1, app(ScheduledReportRuns::class)->failStaleRuns());
        $this->assertSame('stale', $run->fresh()->failure_code);
    }

    public function test_s3_a_run_claimed_after_the_stale_select_is_not_failed(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:05', 'Asia/Colombo'));
        // The worker claims the run between the dispatcher's SELECT and its UPDATE.
        $armed = true;
        ReportScheduleRun::retrieved(function (ReportScheduleRun $r) use (&$armed, $run) {
            if ($armed && $r->id === $run->id) {
                $armed = false;
                DB::table('report_schedule_runs')->where('id', $run->id)->update(['status' => 'running', 'started_at' => now(), 'attempts' => 1]);
            }
        });
        $this->assertSame(0, app(ScheduledReportRuns::class)->failStaleRuns());
        $armed = false;
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(0, AuditLog::where('event', 'report_schedule.run_failed')->count());
        $this->assertSame(0, $d1->fresh()->consecutive_failures);
        $this->assertCount(0, $this->bell($this->admin, InAppNotification::SCHEDULE_RUN_FAILED));
    }

    // ------------------------------------------------------------------ N1: a worker that died mid-build

    public function test_n1_a_run_left_running_by_a_dead_worker_is_built_on_the_next_attempt(): void
    {
        $run = $this->d1Run();
        DB::table('report_schedule_runs')->where('id', $run->id)->update(['status' => 'running', 'started_at' => now(), 'attempts' => 1]);
        // Within the job timeout: a live worker may still be building it.
        $this->travel(5)->minutes();
        $this->work($run->id);
        $this->assertSame('running', $run->fresh()->status);
        // After the timeout (the queue re-reserves the job after retry_after 900 s).
        $this->travel(11)->minutes();
        $this->work($run->id);
        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertSame(2, $run->attempts);
        $this->assertSame(['queued', 'queued'], array_values($this->statuses($run)));
    }

    // ------------------------------------------------------------------ S4: the list without N+1

    public function test_s4_the_list_runs_a_fixed_number_of_queries(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $res = $this->as($this->admin)->getJson('/api/v1/report-schedules?status=all')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$n, $res];
        };
        $this->createD1();
        $this->createW1();
        $this->createD1(['name' => 'GAL daily', 'branches' => [$this->gal->id], 'recipient_ids' => [$this->admin->id, $this->ga->id]]);
        $this->dispatchAt('2026-11-21 07:00');
        $this->work();
        [$few] = $count();
        for ($i = 1; $i <= 8; $i++) {
            $this->createD1(['name' => "KDY daily {$i}", 'recipient_ids' => [$this->admin->id, $this->ak->id, $this->kba->id, $this->sa2->id]]);
        }
        $this->dispatchAt('2026-11-22 07:00');
        [$many, $res] = $count();
        $this->assertSame($few, $many);
        $rows = collect($res->json('data'))->keyBy('name');
        $this->assertCount(11, $rows);
        // The same eligibility as the single-schedule path.
        $this->assertSame(0, $rows['KDY daily I&E']['cannot_receive']);
        $this->assertSame(0, $rows['GAL daily']['cannot_receive']);
        foreach ($rows as $row) {
            $this->assertEquals($row, $this->as($this->admin)->getJson("/api/v1/report-schedules/{$row['id']}")->assertOk()->json());
        }
    }

    // ------------------------------------------------------------------ D7 / N3: owner alerts never reach a demoted owner

    public function test_d7_alerts_for_an_owner_who_is_no_longer_super_admin_go_to_the_other_super_admins(): void
    {
        $run = $this->d1Run();
        $this->work();
        $run->refresh();
        // Demoted after the build: still an active user (a KDY Accountant now).
        $this->admin->forceFill(['is_super_admin' => false])->save();
        Storage::disk(Export::DISK)->put($run->file_path, 'changed');
        $this->sendMails();
        // The demoted owner is no longer a KDY member, so their own delivery is skipped; AK's fails on the damaged file.
        $this->assertSame(['failed', 'skipped'], [$this->statuses($run)[$this->ak->id], $this->statuses($run)[$this->admin->id]]);
        $this->assertCount(0, $this->bell($this->admin, InAppNotification::SCHEDULE_DELIVERY_FAILED));
        $this->assertCount(1, $this->bell($this->sa2, InAppNotification::SCHEDULE_DELIVERY_FAILED));

        // The next run: owner lost access, the paused alert goes to the active Super Admins only.
        $this->dispatchAt('2026-11-22 07:00');
        $this->work();
        $this->assertCount(0, $this->bell($this->admin, InAppNotification::SCHEDULE_PAUSED));
        $this->assertCount(0, $this->problemMailsTo($this->admin));
        $this->assertCount(1, $this->bell($this->sa2, InAppNotification::SCHEDULE_PAUSED));
    }

    // ------------------------------------------------------------------ N4 / N5: the link email

    public function test_n4_n5_link_email_carries_the_filter_and_the_configured_limit(): void
    {
        config(['scheduled_reports.attach_max_bytes' => 1024]);
        $run = $this->d1Run($this->createD1(['params' => ['type' => 'expense']]));
        $this->work();
        $this->assertFalse($run->fresh()->attached);
        $this->sendMails();
        $mail = $this->emailsTo('sa@gold.example')[0];
        $this->assertStringContainsString('too large to attach (limit 1,000 rows and 1 KB)', self::text($mail));
        $this->assertStringContainsString("/reports/income-expense?from=2026-11-20&amp;to=2026-11-20&amp;branch={$this->kdy->id}&amp;type=expense", (string) $mail->getHtmlBody());
    }

    public function test_n4_transfers_link_carries_the_types(): void
    {
        $run = (new ReportScheduleRun)->setRawAttributes(['report' => 'transfers', 'period_from' => '2026-11-20', 'period_to' => '2026-11-20', 'branch_id' => null,
            'params' => json_encode(['types' => ['top_up', 'fund_return'], 'branches' => [$this->kdy->id, $this->gal->id]])], true);
        $this->assertSame("https://gold.example/reports/transfers?from=2026-11-20&to=2026-11-20&branch=all&branches={$this->kdy->id}&branches={$this->gal->id}&types=top_up&types=fund_return",
            ScheduledReportRuns::reportLink($run));
    }

    // ------------------------------------------------------------------ N7: editing the time keeps a due run

    public function test_n7_editing_the_time_keeps_a_due_planned_time(): void
    {
        $d1 = $this->createD1();
        // 07:00 is due; the dispatcher has not run yet this minute.
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:00:30', 'Asia/Colombo'));
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '06:30']))->assertOk()
            ->assertJsonPath('next_run_at', '2026-11-21T01:30:00Z');
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $run = $this->latestRun($d1);
        $this->assertSame('queued', $run->status);
        $this->assertSame('2026-11-21T01:30:00Z', $run->planned_at->toIso8601ZuluString());
        $this->assertSame('2026-11-22T01:00:00Z', $d1->fresh()->next_run_at->toIso8601ZuluString());
    }
}
