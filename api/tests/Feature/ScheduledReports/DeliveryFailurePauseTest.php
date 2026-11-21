<?php

namespace Tests\Feature\ScheduledReports;

use App\Models\AuditLog;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Notifications\InAppNotification;
use App\Services\ScheduledReportRuns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * docs/specs/scheduled-reports.md R15 (accepted risk 3 follow-up): a built run whose emails all failed counts towards
 * the automatic pause (R10), once, when it is settled. ACs 44-50.
 */
class DeliveryFailurePauseTest extends ScheduledReportsTestCase
{
    /** Addresses the mail server refuses (TransportException on every try). */
    private array $refuse = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $transport = new class($test) extends AbstractTransport
        {
            public function __construct(private DeliveryFailurePauseTest $test)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                foreach ($message->getEnvelope()->getRecipients() as $r) {
                    if (in_array($r->getAddress(), $this->test->refused(), true)) {
                        throw new TransportException('550 mailbox unavailable');
                    }
                }
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        };
        Mail::extend('refusing', fn () => $transport);
        config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing']);
        app('mail.manager')->forgetMailers();
    }

    public function refused(): array
    {
        return $this->refuse;
    }

    /** Dispatch at $local, build the run and run its emails. */
    private function runAt(ReportSchedule $s, string $local): ReportScheduleRun
    {
        $this->dispatchAt($local);
        $this->work();
        $this->sendMails();

        return $this->latestRun($s)->fresh();
    }

    private function statuses(ReportScheduleRun $run): array
    {
        return ReportScheduleDelivery::where('run_id', $run->id)->orderBy('user_id')->pluck('status', 'user_id')->all();
    }

    private function notDeliveredBell()
    {
        return $this->bell($this->admin, InAppNotification::SCHEDULE_RUN_FAILED)
            ->filter(fn ($n) => str_ends_with(json_decode($n->data, true)['title'], 'was not delivered'))->values();
    }

    public function test_ac44_all_emails_fail_counts_as_a_failure(): void
    {
        $this->refuse = ['sa@gold.example', 'ak@gold.example'];
        $d1 = $this->createD1();
        $run = $this->runAt($d1, '2026-11-21 07:00');

        $this->assertSame('done', $run->status);
        $this->assertSame([ReportScheduleDelivery::FAILED], array_values(array_unique($this->statuses($run))));
        $this->assertSame(['mail_error'], ReportScheduleDelivery::where('run_id', $run->id)->distinct()->pluck('reason')->all());
        $this->assertNotNull($run->settled_at);
        $this->assertSame(ReportScheduleRun::NOT_DELIVERED, $run->delivery_outcome);
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $this->assertSame('active', $d1->fresh()->status);

        $audit = AuditLog::where('event', 'report_schedule.run_not_delivered')->sole();
        $this->assertTrue($audit->new_values['by_system']);
        $this->assertTrue($audit->new_values['counted']);
        $this->assertSame($run->id, $audit->new_values['run_id']);
        $this->assertSame(1, $audit->new_values['consecutive_failures']);
        $this->assertSame([(string) $this->admin->id => 'mail_error', (string) $this->ak->id => 'mail_error'],
            array_map('strval', $audit->new_values['failed']));
        $this->assertSame($this->kdy->id, $audit->branch_id);

        // The owner: a bell row per failed email (unchanged) plus one run-level bell row and one email.
        $this->assertCount(2, $this->bell($this->admin, InAppNotification::SCHEDULE_DELIVERY_FAILED));
        $bell = $this->notDeliveredBell();
        $this->assertCount(1, $bell);
        $this->assertSame('Scheduled report KDY daily I&E was not delivered', json_decode($bell[0]->data, true)['title']);
        $this->assertStringContainsString('may have been sent', json_decode($bell[0]->data, true)['body']);
        $this->assertCount(1, $this->problemMailsTo($this->admin));
    }

    public function test_ac45_one_email_sent_does_not_count_and_resets(): void
    {
        $d1 = $this->createD1();
        $this->refuse = ['sa@gold.example', 'ak@gold.example'];
        $this->runAt($d1, '2026-11-21 07:00');
        $this->assertSame(1, $d1->fresh()->consecutive_failures);

        // Mixed: AK's email fails, SA's is sent.
        $this->refuse = ['ak@gold.example'];
        $run = $this->runAt($d1, '2026-11-22 07:00');
        $this->assertSame([$this->admin->id => 'sent', $this->ak->id => 'failed'], $this->statuses($run));
        $this->assertSame(ReportScheduleRun::DELIVERED, $run->delivery_outcome);
        $this->assertNotNull($run->settled_at);
        $this->assertSame(0, $d1->fresh()->consecutive_failures);
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.run_not_delivered')->count());
        $this->assertCount(1, $this->notDeliveredBell());
        $this->assertCount(1, $this->problemMailsTo($this->admin));
    }

    public function test_ac46_recipients_skipped_for_access_keep_the_existing_behaviour(): void
    {
        // (a) Nobody passes at build: "Nothing sent", counted once at build (R5), no owner email, settled at once.
        $d1 = $this->createD1(['recipient_ids' => [$this->ak->id]]);
        $this->branchRole($this->kdy, 'Accountant')->revokePermissionTo('report.export');
        $run = $this->runAt($d1, '2026-11-21 07:00');
        $this->assertSame([$this->ak->id => 'skipped'], $this->statuses($run));
        $this->assertSame(ReportScheduleRun::NOTHING_SENT, $run->delivery_outcome);
        $this->assertNotNull($run->settled_at);
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $this->assertFalse(app(ScheduledReportRuns::class)->settleRun($run->id));
        $this->assertSame(0, app(ScheduledReportRuns::class)->settleOpenRuns());
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $this->assertSame(0, AuditLog::where('event', 'report_schedule.run_not_delivered')->count());
        $this->assertCount(1, $this->bell($this->admin, InAppNotification::SCHEDULE_RECIPIENTS_SKIPPED));
        $this->assertCount(0, $this->problemMailsTo($this->admin));

        // (b) Passed at build, skipped at send (access lost in between): not a failure, the count is unchanged.
        $this->branchRole($this->kdy, 'Accountant')->givePermissionTo('report.export');
        $this->dispatchAt('2026-11-22 07:00');
        $this->work();
        $this->branchRole($this->kdy, 'Accountant')->revokePermissionTo('report.export');
        $this->sendMails();
        $run2 = $this->latestRun($d1)->fresh();
        $this->assertSame([$this->ak->id => 'skipped'], $this->statuses($run2));
        $this->assertSame('missing_permission', ReportScheduleDelivery::where('run_id', $run2->id)->value('reason'));
        $this->assertSame(ReportScheduleRun::NOTHING_SENT, $run2->delivery_outcome);
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $this->assertSame(0, AuditLog::where('event', 'report_schedule.run_not_delivered')->count());
        $this->assertCount(0, $this->problemMailsTo($this->admin));
    }

    public function test_ac47_three_runs_with_all_emails_failed_pause_the_schedule(): void
    {
        $this->refuse = ['sa@gold.example', 'ak@gold.example'];
        $d1 = $this->createD1();
        $this->runAt($d1, '2026-11-21 07:00');
        $this->runAt($d1, '2026-11-22 07:00');
        $this->assertSame('active', $d1->fresh()->status);
        $this->runAt($d1, '2026-11-23 07:00');

        $s = $d1->fresh();
        $this->assertSame('paused', $s->status);
        $this->assertSame('repeated_failures', $s->paused_reason);
        $this->assertNull($s->next_run_at);
        $this->assertSame(3, $s->consecutive_failures);
        $this->assertSame(3, AuditLog::where('event', 'report_schedule.run_not_delivered')->count());
        $paused = AuditLog::where('event', 'report_schedule.paused')->sole();
        $this->assertSame('repeated_failures', $paused->new_values['paused_reason']);
        $this->assertTrue($paused->new_values['by_system']);
        $this->assertCount(1, $this->bell($this->admin, InAppNotification::SCHEDULE_PAUSED));
        $this->assertCount(1, $this->bell($this->sa2, InAppNotification::SCHEDULE_PAUSED));
        $this->assertCount(1, $this->problemMailsTo($this->sa2));
        // Three "not delivered" emails and the pause email to the owner.
        $this->assertCount(3, $this->notDeliveredBell());
        $this->assertCount(4, $this->problemMailsTo($this->admin));
        // Paused: no run at the next planned time.
        $this->dispatchAt('2026-11-24 07:00');
        $this->assertSame(3, ReportScheduleRun::where('schedule_id', $d1->id)->count());
    }

    public function test_ac48_unknown_and_stale_deliveries_count_as_failed(): void
    {
        $d1 = $this->createD1();
        // The worker died during both sends: `sending` for more than 60 minutes becomes `unknown` (may have been sent).
        $this->dispatchAt('2026-11-21 07:00');
        $this->work();
        $run = $this->latestRun($d1);
        DB::table('report_schedule_deliveries')->where('run_id', $run->id)->update(['status' => 'sending', 'sending_at' => now()]);
        $this->sendMails();
        $this->assertNull($run->fresh()->settled_at);
        $this->travel(61)->minutes();
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $run->refresh();
        $this->assertSame(['unknown'], ReportScheduleDelivery::where('run_id', $run->id)->distinct()->pluck('reason')->all());
        $this->assertSame(ReportScheduleRun::NOT_DELIVERED, $run->delivery_outcome);
        $this->assertSame(1, $d1->fresh()->consecutive_failures);

        // Emails never picked up: `queued` for more than 24 h becomes `stale`.
        $this->dispatchAt('2026-11-22 07:00');
        $this->work();
        $run2 = $this->latestRun($d1);
        $this->travel(25)->hours(); // 2026-11-23 08:00: also queues and builds that day's run (left unsent)
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $run2->refresh();
        $this->assertSame(['stale'], ReportScheduleDelivery::where('run_id', $run2->id)->distinct()->pluck('reason')->all());
        $this->assertSame(ReportScheduleRun::NOT_DELIVERED, $run2->delivery_outcome);
        $this->assertSame(2, $d1->fresh()->consecutive_failures);
    }

    public function test_ac49_a_run_is_counted_once(): void
    {
        $this->refuse = ['sa@gold.example', 'ak@gold.example'];
        $d1 = $this->createD1();
        $run = $this->runAt($d1, '2026-11-21 07:00');
        $settledAt = $run->settled_at;
        $runs = app(ScheduledReportRuns::class);
        $this->assertFalse($runs->settleRun($run->id));
        $this->assertFalse($runs->settleRun($run->id));
        $this->assertSame(0, $runs->settleOpenRuns());
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.run_not_delivered')->count());
        $this->assertCount(1, $this->notDeliveredBell());
        $this->assertCount(1, $this->problemMailsTo($this->admin));
        $this->assertEquals($settledAt, $run->fresh()->settled_at);

        // The settle after the commit was lost (the worker died in between): the dispatcher sweep settles the run, once.
        $this->dispatchAt('2026-11-22 07:00');
        $this->work();
        $run2 = $this->latestRun($d1);
        DB::table('report_schedule_deliveries')->where('run_id', $run2->id)
            ->update(['status' => 'failed', 'reason' => 'mail_error', 'failed_at' => now()]);
        $this->assertNull($run2->fresh()->settled_at);
        $this->assertSame(1, $runs->settleOpenRuns());
        $this->assertSame(0, $runs->settleOpenRuns());
        $this->assertFalse($runs->settleRun($run2->id));
        $this->assertSame(2, $d1->fresh()->consecutive_failures);
        $this->assertSame(2, AuditLog::where('event', 'report_schedule.run_not_delivered')->count());
    }

    public function test_ac50_an_older_run_settled_after_a_later_run_sent_is_not_counted(): void
    {
        $d1 = $this->createD1();
        // Run A (21 Nov): both emails left `sending` by a dead worker; the claim is dated so it goes stale only after B.
        $this->dispatchAt('2026-11-21 07:00');
        $this->work();
        $a = $this->latestRun($d1);
        DB::table('report_schedule_deliveries')->where('run_id', $a->id)
            ->update(['status' => 'sending', 'sending_at' => CarbonImmutable::parse('2026-11-22 07:00', 'Asia/Colombo')->utc()]);
        // Run B (22 Nov) is sent: the streak is 0.
        $b = $this->runAt($d1, '2026-11-22 07:00');
        $this->assertSame(ReportScheduleRun::DELIVERED, $b->delivery_outcome);
        $this->assertSame(0, $d1->fresh()->consecutive_failures);
        // A becomes `unknown` and settles after B: not delivered, recorded and alerted, but not counted.
        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:01', 'Asia/Colombo'));
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $this->assertSame(ReportScheduleRun::NOT_DELIVERED, $a->fresh()->delivery_outcome);
        $this->assertSame(0, $d1->fresh()->consecutive_failures);
        $audit = AuditLog::where('event', 'report_schedule.run_not_delivered')->sole();
        $this->assertFalse($audit->new_values['counted']);
        $this->assertSame($a->id, $audit->new_values['run_id']);
        $this->assertCount(1, $this->notDeliveredBell());
        $this->assertCount(1, $this->problemMailsTo($this->admin));
    }
}
