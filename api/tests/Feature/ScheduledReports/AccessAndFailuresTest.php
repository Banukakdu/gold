<?php

namespace Tests\Feature\ScheduledReports;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Export;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Models\Transaction;
use App\Notifications\InAppNotification;
use App\Notifications\ReportScheduleProblemMail;
use App\Services\LedgerService;
use App\Services\ScheduledReportRuns;
use App\Support\Money;
use App\Support\ReadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** docs/specs/scheduled-reports.md ACs 11-18, 20-24, 27, 40, 41, 42 (cash book). */
class AccessAndFailuresTest extends ScheduledReportsTestCase
{
    private function d1Run(?ReportSchedule $d1 = null): ReportScheduleRun
    {
        $d1 ??= $this->createD1();
        $this->dispatchAt('2026-11-21 07:00');

        return $this->latestRun($d1);
    }

    private function delivery(ReportScheduleRun $run, $user): ReportScheduleDelivery
    {
        return ReportScheduleDelivery::where('run_id', $run->id)->where('user_id', $user->id)->sole();
    }

    // ------------------------------------------------------------------ access at build and send

    public function test_ac11_ac35_permission_lost_before_build(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $role = $this->branchRole($this->kdy, 'Accountant');
        $role->revokePermissionTo('report.export');
        $this->work();
        $this->sendMails();
        $this->assertSame('skipped', $this->delivery($run, $this->ak)->status);
        $this->assertSame('missing_permission', $this->delivery($run, $this->ak)->reason);
        $this->assertCount(0, $this->emailsTo('ak@gold.example'));
        $this->assertCount(1, $this->emailsTo('sa@gold.example'));
        $this->assertSame('sent', $this->delivery($run, $this->admin)->status);
        $bell = $this->bell($this->admin, InAppNotification::SCHEDULE_RECIPIENTS_SKIPPED);
        $this->assertCount(1, $bell);
        $this->assertSame('KDY daily I&E: 1 recipient skipped', json_decode($bell[0]->data, true)['title']);
        $this->assertSame(['missing_permission'], array_values(AuditLog::where('event', 'report_schedule.run_built')->sole()->new_values['skipped']));

        $row = $this->as($this->admin)->getJson('/api/v1/report-schedules')->json('data.0');
        $this->assertSame(1, $row['cannot_receive']);
        $this->assertContains($this->ak->id, array_column($row['recipients'], 'id'));
        // AC 35: "Partly sent" on S1 (one sent, one skipped), and the reason in S3.
        $this->assertSame(['status' => 'done', 'sent' => 1, 'skipped' => 1], array_intersect_key($row['last_run'], array_flip(['status', 'sent', 'skipped'])));
        $d = collect($this->as($this->admin)->getJson("/api/v1/report-schedules/{$d1->id}/runs")->json('data.0.deliveries'))->keyBy('user.id');
        $this->assertSame('No View reports or Export permission in KDY', $d[$this->ak->id]['reason_text']);
        $this->assertSame('sent', $d[$this->admin->id]['status']);

        // Restored: the next run sends to AK.
        $role->givePermissionTo('report.export');
        $this->dispatchAt('2026-11-22 07:00');
        $this->work();
        $this->sendMails();
        $this->assertSame('sent', $this->delivery($this->latestRun($d1), $this->ak)->status);
        $this->assertCount(1, $this->emailsTo('ak@gold.example'));
    }

    public function test_ac12_deactivated_before_build(): void
    {
        $run = $this->d1Run();
        $this->ak->update(['status' => 'inactive']);
        $this->work();
        $this->assertSame('skipped', $this->delivery($run, $this->ak)->status);
        $this->assertSame('inactive', $this->delivery($run, $this->ak)->reason);
    }

    public function test_ac13_removed_from_branch_before_send(): void
    {
        $run = $this->d1Run();
        $this->work();
        $this->assertSame('queued', $this->delivery($run, $this->ak)->status);
        $this->ak->branches()->detach($this->kdy->id);
        $this->sendMails();
        $this->assertCount(0, $this->emailsTo('ak@gold.example'));
        $this->assertSame('skipped', $this->delivery($run, $this->ak)->status);
        $this->assertSame('not_member', $this->delivery($run, $this->ak)->reason);
        $row = AuditLog::where('event', 'report_schedule.delivery_skipped')->sole();
        $this->assertSame('not_member', $row->new_values['reason']);
        $this->assertSame($this->ak->id, $row->new_values['recipient_id']);
        $this->assertTrue($row->new_values['by_system']);
        $this->assertCount(1, $this->emailsTo('sa@gold.example'));
    }

    public function test_ac14_nobody_can_receive(): void
    {
        $d1 = $this->createD1(['recipient_ids' => [$this->ak->id]]);
        $run = $this->d1Run($d1);
        $this->ak->update(['status' => 'inactive']);
        $this->work();
        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertNull($run->file_path);
        $this->assertNull($run->sha256);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $last = $this->as($this->admin)->getJson('/api/v1/report-schedules')->json('data.0.last_run');
        $this->assertSame(['status' => 'done', 'sent' => 0, 'queued' => 0], array_intersect_key($last, array_flip(['status', 'sent', 'queued'])));
        $this->assertTrue(AuditLog::where('event', 'report_schedule.run_built')->sole()->new_values['nothing_sent']);
    }

    public function test_ac40_email_changed_after_build(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $this->work();
        $this->assertSame('ak@gold.example', $this->delivery($run, $this->ak)->email);
        $this->ak->update(['email' => 'anula.new@gold.example']);
        $this->sendMails();
        $this->assertCount(0, $this->emailsTo('ak@gold.example'));
        $this->assertCount(0, $this->emailsTo('anula.new@gold.example'));
        $this->assertSame('email_changed', $this->delivery($run, $this->ak)->reason);
        $this->assertSame('skipped', $this->delivery($run, $this->ak)->status);
        $this->assertSame('email_changed', AuditLog::where('event', 'report_schedule.delivery_skipped')->sole()->new_values['reason']);

        $this->dispatchAt('2026-11-22 07:00');
        $this->work();
        $this->assertSame('anula.new@gold.example', $this->delivery($this->latestRun($d1), $this->ak)->email);
        $this->sendMails();
        $this->assertCount(1, $this->emailsTo('anula.new@gold.example'));
    }

    public function test_ac27_paused_before_send(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $this->work();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk();
        $this->sendMails();
        $this->assertSame('schedule_paused', $this->delivery($run, $this->ak)->reason);
        $this->assertSame('schedule_paused', $this->delivery($run, $this->admin)->reason);
        $this->assertSame([], $this->sentEmails());
    }

    public function test_edit_while_queued_does_not_change_the_run(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['params' => ['type' => 'expense']]))->assertOk();
        $this->work();
        $this->assertSame('all', $run->fresh()->params['type']);
        $totals = AuditLog::where('event', 'report_schedule.run_built')->sole()->new_values['totals'];
        $this->assertSame('3750.00', $totals['total_income']);
    }

    // ------------------------------------------------------------------ owner (AC 15, 16)

    public function test_ac15_ac16_owner_deactivated_then_resumed(): void
    {
        $d1 = $this->createD1();
        $this->d1Run($d1);
        $this->admin->update(['status' => 'inactive']);
        $job = $this->work();
        $run = $this->latestRun($d1);
        $this->assertSame('failed', $run->status);
        $this->assertSame('owner_lost_access', $run->failure_code);
        $this->assertSame(1, $run->attempts);
        $this->assertTrue($job->job->hasFailed());
        $this->assertNull($run->file_path);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());
        $d1->refresh();
        $this->assertSame('paused', $d1->status);
        $this->assertSame('owner_lost_access', $d1->paused_reason);
        $paused = AuditLog::where('event', 'report_schedule.paused')->sole();
        $this->assertTrue($paused->new_values['by_system']);
        $this->assertSame('owner_lost_access', $paused->new_values['paused_reason']);
        $this->assertCount(1, $this->bell($this->sa2, InAppNotification::SCHEDULE_PAUSED));
        $this->assertCount(1, $this->problemMailsTo($this->sa2));
        $this->assertCount(0, $this->bell($this->sa2, InAppNotification::SCHEDULE_RUN_FAILED));
        $this->assertSame([], $this->sentEmails());

        // AC 16
        $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00', 'Asia/Colombo'));
        $this->as($this->sa2)->postJson("/api/v1/report-schedules/{$d1->id}/resume")->assertOk()
            ->assertJsonPath('owner.id', $this->sa2->id)
            ->assertJsonPath('next_run_at', '2026-11-23T01:30:00Z');
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.owner_changed')->count());
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $this->assertSame(1, ReportScheduleRun::where('schedule_id', $d1->id)->count());
    }

    public function test_owner_demoted_is_treated_as_deactivated(): void
    {
        $d1 = $this->createD1();
        $this->d1Run($d1);
        $this->admin->forceFill(['is_super_admin' => false])->save();
        $this->work();
        $this->assertSame('owner_lost_access', $this->latestRun($d1)->failure_code);
        $this->assertSame('paused', $d1->fresh()->status);
    }

    // ------------------------------------------------------------------ size (AC 17, 18, 42)

    public function test_ac17_ac42_too_many_rows_is_link_only(): void
    {
        $cash = $this->cashOf($this->kdy);
        $cat = Category::where('name', 'Retail')->first();
        $ledger = app(LedgerService::class);
        Auth::setUser($this->ak);
        DB::transaction(function () use ($ledger, $cash, $cat) {
            for ($i = 0; $i < 998; $i++) {
                $ledger->incomeOrExpense(Transaction::INCOME, ['account_id' => $cash->id, 'category_id' => $cat->id, 'txn_date' => '2026-11-20', 'amount' => '1.00']);
            }
        });
        Auth::forgetUser();
        $s = $this->createD1(['name' => 'KDY daily cash book', 'report' => 'cash-book', 'params' => []]);
        $run = $this->d1Run($s);
        $this->work();
        $run->refresh();
        $this->assertSame(1001, $run->rows);
        $this->assertFalse($run->attached);
        $this->assertNull($run->file_path);
        $this->assertNull($run->sha256);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());
        $this->sendMails();
        foreach (['sa@gold.example', 'ak@gold.example'] as $to) {
            $mail = $this->emailsTo($to)[0];
            $this->assertSame([], $mail->getAttachments());
            $body = self::text($mail);
            $this->assertStringContainsString('This report has 1,001 rows, which is too many to attach (limit 1,000 rows and 5 MB).', $body);
            $this->assertStringContainsString('They may differ from the extracted time', $body);
            $this->assertStringContainsString("https://gold.example/reports/cash-book?from=2026-11-20&amp;to=2026-11-20&amp;branch={$this->kdy->id}", (string) $mail->getHtmlBody());
        }
        $this->assertSame('link', AuditLog::where('event', 'report_schedule.delivered')->first()->new_values['file']);
        $this->assertSame("https://gold.example/reports/cash-book?from=2026-11-20&to=2026-11-20&branch={$this->kdy->id}", ScheduledReportRuns::reportLink($run));

        // AC 42: per account opening, receipts, payments, closing, closing = opening + receipts - payments.
        $accounts = AuditLog::where('event', 'report_schedule.run_built')->sole()->new_values['totals']['accounts'];
        $this->assertNotEmpty($accounts);
        foreach ($accounts as $id => $a) {
            $this->assertSame(Money::toCents($a['closing']), Money::toCents($a['opening']) + Money::toCents($a['receipts']) - Money::toCents($a['payments']));
            $this->assertSame((string) Account::withoutGlobalScopes()->find($id)->branch_id, (string) $this->kdy->id);
        }
        $this->assertSame('4748.00', $accounts[(string) $cash->id]['receipts']);
    }

    public function test_ac18_file_too_large_to_attach(): void
    {
        config(['scheduled_reports.attach_max_bytes' => 1024]);
        $run = $this->d1Run();
        $this->work();
        $run->refresh();
        $this->assertNotNull($run->sha256);
        $this->assertFalse($run->attached);
        $this->sendMails();
        $mail = $this->emailsTo('sa@gold.example')[0];
        $this->assertSame([], $mail->getAttachments());
        $this->assertStringContainsString('too large to attach', self::text($mail));
        $this->assertStringContainsString("/reports/income-expense?from=2026-11-20&amp;to=2026-11-20&amp;branch={$this->kdy->id}", (string) $mail->getHtmlBody());
    }

    // ------------------------------------------------------------------ failures and retries (AC 20-24, 41)

    public function test_ac20_retry_then_done(): void
    {
        $run = $this->d1Run();
        $calls = 0;
        ReadSnapshot::$hook = function (string $point) use (&$calls) {
            if ($point === 'counted' && ++$calls === 1) {
                throw new QueryException('sqlite', 'select 1', [], new \Exception('Deadlock found'));
            }
        };
        $this->work();
        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertSame(2, $run->attempts);
    }

    public function test_ac20_both_attempts_fail(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        ReadSnapshot::$hook = function (string $point) {
            if ($point === 'counted') {
                throw new QueryException('sqlite', 'select 1', [], new \Exception('SQLSTATE[HY000] secret detail'));
            }
        };
        $this->work();
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('error', $run->failure_code);
        $this->assertSame('The report could not be built. It will run again at the next scheduled time.', $run->error);
        $this->assertSame(2, $run->attempts);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());
        $this->assertSame(0, ReportScheduleDelivery::count());
        $this->sendMails();
        $this->assertSame([], $this->sentEmails());
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $bell = $this->bell($this->admin, InAppNotification::SCHEDULE_RUN_FAILED);
        $this->assertCount(1, $bell);
        $this->assertSame('Scheduled report KDY daily I&E could not be sent', json_decode($bell[0]->data, true)['title']);
        $this->assertCount(1, $this->problemMailsTo($this->admin));
        $this->assertSame('error', AuditLog::where('event', 'report_schedule.run_failed')->sole()->new_values['code']);
        $runRow = $this->as($this->admin)->getJson("/api/v1/report-schedules/{$d1->id}/runs")->json('data.0');
        $this->assertStringNotContainsString('secret', json_encode($runRow));
    }

    public function test_ac21_three_failures_pause_and_a_sent_run_resets(): void
    {
        $d1 = $this->createD1();
        $fail = function (string $at) use ($d1) {
            $this->dispatchAt($at);
            ReadSnapshot::$hook = fn (string $p) => $p === 'counted' ? throw new \RuntimeException('boom') : null;
            $this->work();
            ReadSnapshot::$hook = null;

            return $d1->fresh();
        };
        $fail('2026-11-21 07:00');
        $this->assertSame(2, $fail('2026-11-22 07:00')->consecutive_failures);
        // A run with a sent delivery resets the count.
        $this->dispatchAt('2026-11-23 07:00');
        $this->work();
        $this->sendMails();
        $this->assertSame(0, $d1->fresh()->consecutive_failures);

        $fail('2026-11-24 07:00');
        $fail('2026-11-25 07:00');
        $s = $fail('2026-11-26 07:00');
        $this->assertSame('paused', $s->status);
        $this->assertSame('repeated_failures', $s->paused_reason);
        $this->assertNull($s->next_run_at);
        $this->assertCount(1, $this->bell($this->admin, InAppNotification::SCHEDULE_PAUSED));
        $this->assertCount(1, $this->bell($this->sa2, InAppNotification::SCHEDULE_PAUSED));
        $this->assertCount(1, $this->problemMailsTo($this->sa2));
        $this->assertSame('repeated_failures', AuditLog::where('event', 'report_schedule.paused')->sole()->new_values['paused_reason']);
    }

    public function test_ac22_mail_transport_fails_for_one_recipient(): void
    {
        $transport = new class extends AbstractTransport
        {
            public array $sent = [];

            public int $failures = 0;

            protected function doSend(SentMessage $message): void
            {
                foreach ($message->getEnvelope()->getRecipients() as $r) {
                    if ($r->getAddress() === 'ak@gold.example') {
                        $this->failures++;
                        throw new TransportException('550 mailbox unavailable');
                    }
                }
                $this->sent[] = $message;
            }

            public function __toString(): string
            {
                return 'flaky://';
            }
        };
        Mail::extend('flaky', fn () => $transport);
        config(['mail.mailers.flaky' => ['transport' => 'flaky'], 'mail.default' => 'flaky']);
        app('mail.manager')->forgetMailers();

        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $this->work();
        $this->sendMails();
        $this->assertSame(3, $transport->failures);
        $this->assertCount(1, $transport->sent);
        $this->assertSame('failed', $this->delivery($run, $this->ak)->status);
        $this->assertSame('mail_error', $this->delivery($run, $this->ak)->reason);
        $this->assertSame('sent', $this->delivery($run, $this->admin)->status);
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.delivery_failed')->count());
        $bell = $this->bell($this->admin, InAppNotification::SCHEDULE_DELIVERY_FAILED);
        $this->assertCount(1, $bell);
        $this->assertSame('KDY daily I&E: email to Anula Kumari failed', json_decode($bell[0]->data, true)['title']);
    }

    public function test_ac23_damaged_or_missing_file(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $this->work();
        $run->refresh();
        Storage::disk(Export::DISK)->put($run->file_path, 'not the report');
        $this->sendMails();
        $this->assertSame([], $this->sentEmails());
        $this->assertSame('damaged', $this->delivery($run, $this->ak)->reason);
        $this->assertSame('failed', $this->delivery($run, $this->ak)->status);
        $this->assertSame('damaged', $this->delivery($run, $this->admin)->reason);
        $this->assertSame(2, AuditLog::where('event', 'report_schedule.delivery_failed')->count());
        $this->assertCount(2, $this->bell($this->admin, InAppNotification::SCHEDULE_DELIVERY_FAILED));

        $this->dispatchAt('2026-11-22 07:00');
        $this->work();
        $run2 = $this->latestRun($d1);
        Storage::disk(Export::DISK)->delete($run2->file_path);
        $this->sendMails();
        $this->assertSame([], $this->sentEmails());
        $this->assertSame('missing', $this->delivery($run2, $this->ak)->reason);
        $this->assertSame('failed', $this->delivery($run2, $this->ak)->status);
    }

    public function test_ac24_ac41_stale_run_and_stale_delivery(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        // Queued at 07:00 and never picked up: 121 minutes later.
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:01', 'Asia/Colombo'));
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('stale', $run->failure_code);
        $this->assertSame('The report was not built. Try again later.', $run->error);
        $row = AuditLog::where('event', 'report_schedule.run_failed')->sole();
        $this->assertSame('stale', $row->new_values['code']);
        $this->assertTrue($row->new_values['by_system']);
        $this->assertSame(1, $d1->fresh()->consecutive_failures);
        $this->assertCount(1, $this->bell($this->admin, InAppNotification::SCHEDULE_RUN_FAILED));
        $this->assertCount(1, $this->problemMailsTo($this->admin));
        // A worker that picks it up later does nothing.
        $this->work($run->id);
        $this->assertSame('failed', $run->fresh()->status);

        // Stale delivery: queued for 25 h.
        $this->dispatchAt('2026-11-22 07:00');
        $run2 = $this->latestRun($d1);
        $this->work($run2->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 08:01', 'Asia/Colombo'));
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
        $this->assertSame(['failed', 'failed'], ReportScheduleDelivery::where('run_id', $run2->id)->pluck('status')->all());
        $this->assertSame(['stale', 'stale'], ReportScheduleDelivery::where('run_id', $run2->id)->pluck('reason')->all());
        $this->assertSame(2, AuditLog::where('event', 'report_schedule.delivery_failed')->count());
        $this->assertCount(2, $this->bell($this->admin, InAppNotification::SCHEDULE_DELIVERY_FAILED));
        // ... and the later mail job sends nothing.
        $this->sendMails();
        $this->assertSame([], array_filter($this->sentEmails(), fn ($m) => str_contains((string) $m->getSubject(), 'Income & Expense')));
    }

    public function test_ac41_stale_running_run_skips_its_queued_deliveries(): void
    {
        $d1 = $this->createD1();
        $run = $this->d1Run($d1);
        $this->work();
        // A run that is still `running` (a killed worker after the deliveries were written) with queued deliveries.
        DB::table('report_schedule_runs')->where('id', $run->id)->update(['status' => 'running', 'started_at' => now()]);
        $this->travelTo(now()->addMinutes(121));
        app(ScheduledReportRuns::class)->failStaleRuns();
        $this->assertSame(['skipped', 'skipped'], ReportScheduleDelivery::where('run_id', $run->id)->pluck('status')->all());
        $this->assertSame(['run_failed', 'run_failed'], ReportScheduleDelivery::where('run_id', $run->id)->pluck('reason')->all());
        $this->assertSame('stale', $run->fresh()->failure_code);
    }

    public function test_problem_mail_is_not_sent_to_an_inactive_user(): void
    {
        $this->assertFalse((new ReportScheduleProblemMail(1, 'x'))->shouldSend($this->ax, 'mail'));
        $this->assertTrue((new ReportScheduleProblemMail(1, 'x'))->shouldSend($this->admin, 'mail'));
    }
}
