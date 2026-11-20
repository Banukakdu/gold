<?php

namespace Tests\Feature\ScheduledReports;

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Models\AuditLog;
use App\Models\Export;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** docs/specs/scheduled-reports.md ACs 31 (audit screen) and 32 (file removal, R12). */
class AuditPruneTest extends ScheduledReportsTestCase
{
    private function auditList($request, array $q = []): array
    {
        return $request->getJson('/api/v1/audit-logs?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-30', 'event_group' => 'reports'] + $q))
            ->assertOk()->json('data');
    }

    public function test_ac31_reports_group_labels_system_rows_and_branch_scope(): void
    {
        $d1 = $this->createD1();
        $w1 = $this->createW1();
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '06:30']))->assertOk();
        $this->dispatchAt('2026-11-21 06:30');
        $this->work();
        $this->sendMails();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$w1->id}/archive", ['reason' => 'Not needed'])->assertOk();

        $rows = collect($this->auditList($this->actingAs($this->admin)->withHeader('X-Branch-Id', 'all')));
        $labels = $rows->pluck('event_label', 'event')->all();
        foreach ([
            'report_schedule.created' => 'Report schedule created', 'report_schedule.updated' => 'Report schedule changed',
            'report_schedule.paused' => 'Report schedule paused', 'report_schedule.archived' => 'Report schedule archived',
            'report_schedule.run_built' => 'Scheduled report built', 'report_schedule.delivered' => 'Scheduled report emailed',
        ] as $event => $label) {
            $this->assertSame($label, $labels[$event] ?? null, $event);
        }
        $this->assertSame('Report schedule owner changed', AuditLogController::label('report_schedule.owner_changed'));
        $this->assertSame('Scheduled report files removed', AuditLogController::label('report_schedule.files_removed'));
        foreach (['run_failed' => 'Scheduled report failed', 'run_missed' => 'Scheduled report missed', 'delivery_skipped' => 'Scheduled report not sent',
            'delivery_failed' => 'Scheduled report email failed', 'resumed' => 'Report schedule resumed', 'restored' => 'Report schedule restored'] as $k => $label) {
            $this->assertSame($label, AuditLogController::label("report_schedule.{$k}"));
        }
        // System rows show "System"; user actions do not.
        $built = $rows->firstWhere('event', 'report_schedule.run_built');
        $this->assertTrue($built['system']);
        $this->assertSame('KDY daily I&E', $built['subject']);
        $this->assertFalse($rows->firstWhere('event', 'report_schedule.created')['system']);

        // A KDY user with audit.view sees D1's rows (branch KDY), not W1's (branch null).
        $kdyRows = collect($this->auditList($this->actingAs($this->ak)->withHeader('X-Branch-Id', (string) $this->kdy->id)));
        $subjects = $kdyRows->where('auditable_type', 'ReportSchedule')->pluck('auditable_id')->unique()->values()->all();
        $this->assertSame([$d1->id], $subjects);
        $this->assertTrue($kdyRows->contains('event', 'report_schedule.delivered'));
        $this->assertSame(0, AuditLog::where('event', 'like', 'report_schedule.%')->where('auditable_id', $w1->id)->whereNotNull('branch_id')->count());
    }

    public function test_ac32_files_removed_after_keep_days(): void
    {
        $d1 = $this->createD1();
        $this->dispatchAt('2026-11-21 07:00');
        $this->work();
        $this->sendMails();
        $old = $this->latestRun($d1);
        $this->dispatchAt('2026-11-23 07:00');
        $this->work();
        $this->sendMails();
        $recent = $this->latestRun($d1);
        $disk = Storage::disk(Export::DISK);
        // Older than 25 h: the orphan step must keep a scheduled PDF that is still within its 7 days.
        touch($disk->path($old->file_path), now()->subDays(2)->getTimestamp());

        $this->travelTo(CarbonImmutable::parse('2026-11-23 07:00', 'Asia/Colombo'));
        $this->artisan('exports:prune')->assertExitCode(0);
        $this->assertTrue($disk->exists($old->file_path));
        $this->assertSame(0, AuditLog::where('event', 'report_schedule.files_removed')->count());

        // 8 days after the first run finished; the second is 6 days old and is kept.
        $before = (array) DB::table('report_schedule_runs')->where('id', $old->id)->first();
        $this->travelTo(CarbonImmutable::parse('2026-11-29 07:30', 'Asia/Colombo'));
        $this->artisan('exports:prune')->assertExitCode(0);
        $this->assertFalse($disk->exists($old->file_path));
        $old->refresh();
        $this->assertNull($old->file_path);
        $this->assertNotNull($old->file_removed_at);
        $after = (array) DB::table('report_schedule_runs')->where('id', $old->id)->first();
        $this->assertSame(array_diff_key($before, array_flip(['file_path', 'file_removed_at', 'updated_at'])),
            array_diff_key($after, array_flip(['file_path', 'file_removed_at', 'updated_at'])));
        $this->assertTrue($disk->exists($recent->file_path));
        $row = AuditLog::where('event', 'report_schedule.files_removed')->sole();
        $this->assertSame([$old->id], $row->new_values['run_ids']);
        $this->assertTrue($row->new_values['by_system']);

        // A run with a delivery still queued keeps its file, whatever its age.
        $id = DB::table('report_schedule_deliveries')->where('run_id', $recent->id)->value('id');
        DB::table('report_schedule_deliveries')->where('id', $id)->update(['status' => 'queued', 'sent_at' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-12-09 07:00', 'Asia/Colombo'));
        $this->artisan('exports:prune')->assertExitCode(0);
        $this->assertTrue($disk->exists($recent->file_path));
        $this->assertNull($recent->fresh()->file_removed_at);
    }
}
