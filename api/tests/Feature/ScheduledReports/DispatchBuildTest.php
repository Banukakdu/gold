<?php

namespace Tests\Feature\ScheduledReports;

use App\Jobs\RunScheduledReport;
use App\Models\AuditLog;
use App\Models\Export;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Notifications\InAppNotification;
use App\Notifications\ReportScheduleProblemMail;
use App\Services\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Part\DataPart;

/** docs/specs/scheduled-reports.md ACs 7-10, 25, 33, 37, 38, 39, 42 (I&E) and 43 (W1). */
class DispatchBuildTest extends ScheduledReportsTestCase
{
    public function test_ac7_dispatch_queues_one_run(): void
    {
        $d1 = $this->createD1();
        $this->dispatchAt('2026-11-21 07:00');
        $runs = ReportScheduleRun::all();
        $this->assertCount(1, $runs);
        $run = $runs[0];
        $this->assertSame('queued', $run->status);
        $this->assertSame('2026-11-21T01:30:00Z', $run->planned_at->toIso8601ZuluString());
        $this->assertSame(['2026-11-20', '2026-11-20'], [$run->periodFrom(), $run->periodTo()]);
        $this->assertSame($this->kdy->id, $run->branch_id);
        $this->assertSame([$this->kdy->id], $run->branch_ids);
        Queue::assertPushedOn('exports', RunScheduledReport::class, fn (RunScheduledReport $j) => $j->runId === $run->id && $j->connection === 'exports');
        $this->assertSame('2026-11-22T01:30:00Z', $d1->fresh()->next_run_at->toIso8601ZuluString());

        $this->dispatchAt('2026-11-21 07:00');
        $this->dispatchAt('2026-11-21 07:01');
        $this->assertSame(1, ReportScheduleRun::count());
        Queue::assertPushed(RunScheduledReport::class, 1);
    }

    public function test_ac8_ac9_ac42_build_and_send(): void
    {
        $d1 = $this->createD1();
        $this->dispatchAt('2026-11-21 07:00');
        $this->work();
        $run = $this->latestRun($d1);

        // AC 8
        $this->assertSame('done', $run->status);
        $this->assertGreaterThan(0, $run->rows);
        $this->assertTrue($run->attached);
        $this->assertGreaterThan(0, $run->size_bytes);
        $this->assertSame(64, strlen($run->sha256));
        $this->assertNotNull($run->data_as_at);
        $this->assertSame(1, $run->attempts);
        $disk = Storage::disk(Export::DISK);
        $this->assertSame("scheduled/{$run->id}.pdf", $run->file_path);
        $this->assertTrue($disk->exists($run->file_path));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($disk->path($run->file_path))), -4));
        $this->assertFalse($disk->exists($run->partPath()));
        $this->assertSame(hash('sha256', $this->storedPdf($run)), $run->sha256);

        $text = implode(' ', $this->pdfPages($this->storedPdf($run)));
        $this->assertStringContainsString('Scheduled report "KDY daily I&E", set up by Sunil Admin. Printed at 21 Nov 2026 07:00 (Asia/Colombo).', $text);
        $this->assertMatchesRegularExpression('/Data extracted 2026-11-21 07:00:\d\d \(Asia\/Colombo\)\..*Requested 2026-11-21 07:00\./', $text);
        $this->assertStringContainsString('PROVISIONAL', $text);
        $this->assertStringContainsString('Branches: KDY Kandy', $text);

        // The same totals as a synchronous PDF export by SA in KDY branch mode for 2026-11-20.
        $sync = $this->actingAs($this->admin)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->post('/api/v1/reports/income-expense/export', ['from' => '2026-11-20', 'to' => '2026-11-20', 'type' => 'all', 'format' => 'pdf'])->assertOk();
        $syncText = implode(' ', $this->pdfPages($sync->getContent()));
        foreach (['Total income 3,750.00', 'Total expenses 400.00', 'Net (income - expenses) 3,350.00'] as $line) {
            $this->assertStringContainsString($line, $syncText);
            $this->assertStringContainsString($line, $text);
        }

        $deliveries = ReportScheduleDelivery::where('run_id', $run->id)->orderBy('user_id')->get();
        $this->assertSame([[$this->admin->id, 'queued'], [$this->ak->id, 'queued']], $deliveries->map(fn ($d) => [$d->user_id, $d->status])->all());
        $this->assertSame(['sa@gold.example', 'ak@gold.example'], $deliveries->pluck('email')->all());
        $built = AuditLog::where('event', 'report_schedule.run_built')->get();
        $this->assertCount(1, $built);
        $v = $built[0]->new_values;
        $this->assertTrue($v['by_system']);
        $this->assertSame($this->admin->id, $built[0]->user_id);
        $this->assertSame($this->kdy->id, $built[0]->branch_id);
        $this->assertSame($run->id, $v['run_id']);
        $this->assertSame($run->sha256, $v['sha256']);
        $this->assertSame([$this->admin->id, $this->ak->id], $v['queued']);

        // AC 42: the control totals as sent, and the provisional flag.
        $this->assertTrue($v['provisional']);
        $this->assertEquals(['total_income' => '3750.00', 'total_expense' => '400.00', 'net' => '3350.00'], $v['totals']);

        // AC 9
        $this->sendMails();
        $sa = $this->emailsTo('sa@gold.example');
        $ak = $this->emailsTo('ak@gold.example');
        $this->assertCount(1, $sa);
        $this->assertCount(1, $ak);
        foreach ([$sa[0], $ak[0]] as $mail) {
            $this->assertSame('KDY daily I&E: Income & Expense by Category, 20 Nov 2026', $mail->getSubject());
            $attachments = $mail->getAttachments();
            $this->assertCount(1, $attachments);
            /** @var DataPart $pdf */
            $pdf = $attachments[0];
            $this->assertSame('income-expense_KDY_2026-11-20_2026-11-20.pdf', $pdf->getFilename());
            $this->assertSame($run->sha256, hash('sha256', $pdf->getBody()));
            $body = self::text($mail);
            $this->assertDoesNotMatchRegularExpression('/\d\.\d\d/', $body);
            $this->assertStringNotContainsStringIgnoringCase('as at', $body);
            $this->assertStringContainsString('Income & Expense by Category for KDY, 20 Nov 2026.', $body);
            $this->assertStringContainsString('Data extracted 21 Nov 2026 07:00 (Asia/Colombo).', $body);
            $this->assertStringContainsString('PROVISIONAL - period not locked. Figures can still change.', $body);
            $this->assertStringContainsString('The report is attached as a PDF', $body);
            $this->assertStringContainsString('You receive this because Sunil Admin added you to the scheduled report "KDY daily I&E". To stop it, ask a Super Admin.', $body);
            $this->assertStringContainsString('This email contains company financial information: do not forward it.', $body);
            $this->assertStringNotContainsString('Sent late', $body);
        }
        $this->assertSame(['sent', 'sent'], ReportScheduleDelivery::where('run_id', $run->id)->orderBy('user_id')->pluck('status')->all());
        $delivered = AuditLog::where('event', 'report_schedule.delivered')->orderBy('id')->get();
        $this->assertCount(2, $delivered);
        $this->assertEqualsCanonicalizing(['sa@gold.example', 'ak@gold.example'], $delivered->map(fn ($a) => $a->new_values['email'])->all());
        $this->assertSame($run->sha256, $delivered[0]->new_values['file']);

        // S1 last run
        $last = $this->as($this->admin)->getJson("/api/v1/report-schedules/{$d1->id}")->json('last_run');
        $this->assertSame(['status' => 'done', 'sent' => 2, 'skipped' => 0, 'failed' => 0, 'queued' => 0], array_intersect_key($last, array_flip(['status', 'sent', 'skipped', 'failed', 'queued'])));

        // The run resource exposes no file path and no sha256.
        $runs = $this->as($this->admin)->getJson("/api/v1/report-schedules/{$d1->id}/runs")->assertOk()->json('data');
        $this->assertCount(1, $runs);
        $this->assertArrayNotHasKey('file_path', $runs[0]);
        $this->assertArrayNotHasKey('sha256', $runs[0]);
        $this->assertCount(2, $runs[0]['deliveries']);
        $this->assertSame(2, $runs[0]['sent']);
    }

    public function test_ac10_period_from_planned_time_not_pickup(): void
    {
        $d1 = $this->createD1();
        $this->dispatchAt('2026-11-21 07:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-21 08:30', 'Asia/Colombo'));
        $this->work();
        $run = $this->latestRun($d1);
        $this->assertSame('done', $run->status);
        $this->assertSame(['2026-11-20', '2026-11-20'], [$run->periodFrom(), $run->periodTo()]);
        // A5: started 90 minutes after it was planned: the email says so.
        $this->sendMails();
        $this->assertStringContainsString('Sent late: this email was planned for 21 Nov 2026 07:00 (Asia/Colombo).', self::text($this->emailsTo('sa@gold.example')[0]));
    }

    public function test_ac39_daily_covers_every_calendar_day(): void
    {
        $d1 = $this->createD1();
        $this->dispatchAt('2026-11-21 07:00');
        $this->dispatchAt('2026-11-22 07:00');
        $this->dispatchAt('2026-11-23 07:00');
        $periods = ReportScheduleRun::where('schedule_id', $d1->id)->orderBy('planned_at')->get()
            ->map(fn ($r) => [$r->planned_at->setTimezone('Asia/Colombo')->format('D Y-m-d H:i'), $r->periodFrom(), $r->periodTo()])->all();
        $this->assertSame([
            ['Sat 2026-11-21 07:00', '2026-11-20', '2026-11-20'],
            ['Sun 2026-11-22 07:00', '2026-11-21', '2026-11-21'],
            ['Mon 2026-11-23 07:00', '2026-11-22', '2026-11-22'],
        ], $periods);
        $this->assertSame(0, ReportScheduleRun::where('status', 'missed')->count());
    }

    public function test_ac25_missed_runs(): void
    {
        $d1 = $this->createD1();
        $this->dispatchAt('2026-11-21 06:00');
        $this->dispatchAt('2026-11-23 09:00');
        $missed = ReportScheduleRun::where('status', 'missed')->get();
        $this->assertCount(1, $missed);
        $this->assertSame(2, $missed[0]->missed_count);
        $this->assertSame(['2026-11-20', '2026-11-21'], [$missed[0]->periodFrom(), $missed[0]->periodTo()]);
        $this->assertSame('2026-11-21 07:00', $missed[0]->planned_at->setTimezone('Asia/Colombo')->format('Y-m-d H:i'));
        $queued = ReportScheduleRun::where('status', 'queued')->get();
        $this->assertCount(1, $queued);
        $this->assertSame('2026-11-23 07:00', $queued[0]->planned_at->setTimezone('Asia/Colombo')->format('Y-m-d H:i'));
        $this->assertSame(['2026-11-22', '2026-11-22'], [$queued[0]->periodFrom(), $queued[0]->periodTo()]);
        $this->assertSame('2026-11-24 07:00', $d1->fresh()->next_run_at->setTimezone('Asia/Colombo')->format('Y-m-d H:i'));
        $this->assertSame(1, $d1->fresh()->consecutive_failures);

        $bell = $this->bell($this->admin, InAppNotification::SCHEDULE_MISSED);
        $this->assertCount(1, $bell);
        $this->assertSame('KDY daily I&E: 2 scheduled emails were missed', json_decode($bell[0]->data, true)['title']);
        $this->assertSame("/scheduled-reports/{$d1->id}", json_decode($bell[0]->data, true)['url']);
        $this->assertCount(1, $this->problemMailsTo($this->admin));
        $audit = AuditLog::where('event', 'report_schedule.run_missed')->first();
        $this->assertSame(2, $audit->new_values['missed_count']);
        $this->assertEquals(['from' => '2026-11-20', 'to' => '2026-11-21'], $audit->new_values['period']);
        $this->assertTrue($audit->new_values['by_system']);
    }

    public function test_ac38_weekly_late_window(): void
    {
        $w1 = $this->createW1();
        $this->dispatchAt('2026-11-23 06:00');
        $this->dispatchAt('2026-11-23 20:00');
        $this->assertSame(0, ReportScheduleRun::where('status', 'missed')->count());
        $run = ReportScheduleRun::where('status', 'queued')->sole();
        $this->assertSame('2026-11-23 07:00', $run->planned_at->setTimezone('Asia/Colombo')->format('Y-m-d H:i'));
        $this->assertSame(['2026-11-16', '2026-11-22'], [$run->periodFrom(), $run->periodTo()]);
        $this->work();
        $this->sendMails();
        $mail = $this->emailsTo('sa2@gold.example')[0];
        $this->assertStringContainsString('Sent late: this email was planned for 23 Nov 2026 07:00 (Asia/Colombo).', self::text($mail));
        $this->assertSame('branch-comparison_ALL_2026-11-16_2026-11-22.pdf', $mail->getAttachments()[0]->getFilename());

        $this->assertSame(1, $w1->runs()->count());
    }

    public function test_ac38_weekly_gap_over_24_hours_is_missed(): void
    {
        // A gap until Tue 08:00 is more than 24 h: a missed run instead, and the owner is alerted.
        $w2 = $this->createW1();
        $this->dispatchAt('2026-11-23 06:00');
        $this->dispatchAt('2026-11-24 08:00');
        $missed = ReportScheduleRun::where('schedule_id', $w2->id)->sole();
        $this->assertSame('missed', $missed->status);
        $this->assertSame(1, $missed->missed_count);
        $this->assertSame(['2026-11-16', '2026-11-22'], [$missed->periodFrom(), $missed->periodTo()]);
        $this->assertCount(1, $this->bell($this->admin, InAppNotification::SCHEDULE_MISSED));
        $this->assertSame('2026-11-30 07:00', $w2->fresh()->next_run_at->setTimezone('Asia/Colombo')->format('Y-m-d H:i'));
        Queue::assertNotPushed(RunScheduledReport::class);
    }

    public function test_ac43_weekly_all_branches_comparison_reconciles(): void
    {
        $w1 = $this->createW1();
        $this->dispatchAt('2026-11-23 07:00');
        $this->work();
        $run = $this->latestRun($w1);
        $this->assertSame('done', $run->status);
        $this->assertNull($run->branch_id);
        $this->assertSame(3, $run->rows);
        $text = implode(' ', $this->pdfPages($this->storedPdf($run)));
        $this->assertStringContainsString('Branches: All branches', $text);

        // The figures the PDF was built from (audit control totals) and the report itself, recomputed in All mode.
        $report = $this->actingAs($this->admin)->withHeader('X-Branch-Id', 'all')
            ->getJson('/api/v1/reports/branch-comparison?from=2026-11-16&to=2026-11-22')->assertOk()->json();
        $c = fn ($v) => Money::toCents((string) $v);
        $sum = array_fill_keys(ReportService::COMPARISON_KEYS, 0);
        foreach ($report['rows'] as $row) {
            $this->assertSame($c($row['closing']), $c($row['opening']) + $c($row['capital']) + $c($row['funds_received']) + $c($row['income'])
                - $c($row['expenses']) + $c($row['transfers_in']) - $c($row['transfers_out']));
            foreach ($sum as $k => $_) {
                $sum[$k] += $c($row[$k]);
            }
            // Every row figure is printed in the scheduled PDF.
            foreach (ReportService::COMPARISON_KEYS as $k) {
                $this->assertStringContainsString(Money::format($row[$k]), $text);
            }
        }
        foreach ($sum as $k => $v) {
            $this->assertSame($v, $c($report['total'][$k]), "total {$k}");
        }
        $this->assertSame(0, $c($report['total']['funds_received']) + $c($report['total']['transfers_in']) - $c($report['total']['transfers_out']));
        $this->assertSame(500000, $c($report['total']['funds_received'])); // the KDY top-up of 2026-11-17
        $totals = AuditLog::where('event', 'report_schedule.run_built')->sole()->new_values['totals'];
        $this->assertEquals(['opening' => $report['total']['opening'], 'closing' => $report['total']['closing']], $totals);

        // Equal to a synchronous PDF export by SA in All mode for the same period (same lines, apart from "Printed").
        $sync = $this->actingAs($this->admin)->withHeader('X-Branch-Id', 'all')
            ->post('/api/v1/reports/branch-comparison/export', ['from' => '2026-11-16', 'to' => '2026-11-22', 'format' => 'pdf'])->assertOk();
        $strip = fn (string $t) => preg_replace(['/Printed by .*?\(Asia\/Colombo\)/', '/Scheduled report .*?Requested [0-9: -]+\./'], '', $t);
        $this->assertSame($strip(implode(' ', $this->pdfPages($sync->getContent()))), $strip($text));
    }

    public function test_ac33_nothing_posted_and_ac37_schedule_list(): void
    {
        $before = $this->books();
        $d1 = $this->createD1();
        $w1 = $this->createW1();
        $this->dispatchAt('2026-11-23 07:00');
        while ($id = ReportScheduleRun::where('status', 'queued')->value('id')) {
            $this->work($id);
        }
        $this->sendMails();
        $this->artisan('exports:prune')->assertExitCode(0);
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$w1->id}/archive")->assertOk();
        $this->assertEquals($before, $this->books());

        $this->artisan('schedule:list')->expectsOutputToContain('reports:dispatch-scheduled')->assertExitCode(0);
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'reports:dispatch-scheduled'));
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_problem_mail_text(): void
    {
        $d1 = $this->createD1();
        $n = new ReportScheduleProblemMail($d1->id, 'Scheduled report KDY daily I&E could not be sent', 'The report could not be built.');
        $mail = $n->toMail($this->admin);
        $this->assertSame('Scheduled report KDY daily I&E could not be sent', $mail->subject);
        $this->assertSame("https://gold.example/scheduled-reports/{$d1->id}", $mail->actionUrl);
    }
}
