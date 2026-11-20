<?php

namespace Tests\Feature\ScheduledReports;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleRecipient;
use App\Models\ReportScheduleRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** docs/specs/scheduled-reports.md ACs 1-6, 16 (resume), 26, 28-30 and the S2/section 5 rules. */
class ScheduleManagementTest extends ScheduledReportsTestCase
{
    public function test_ac1_create_d1(): void
    {
        $r = $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->d1Body())->assertCreated();
        $r->assertJsonPath('status', 'active')
            ->assertJsonPath('next_run_at', '2026-11-21T01:30:00Z')
            ->assertJsonPath('next_period', ['from' => '2026-11-20', 'to' => '2026-11-20'])
            ->assertJsonPath('owner.id', $this->admin->id)
            ->assertJsonPath('title', 'Income & Expense by Category')
            ->assertJsonPath('branches_label', 'KDY')
            ->assertJsonPath('params_label', 'Type: All');
        $this->assertCount(2, $r->json('recipients'));
        $this->assertTrue(collect($r->json('recipients'))->every(fn ($x) => $x['eligible']));

        $rows = AuditLog::where('event', 'report_schedule.created')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->kdy->id, $rows[0]->branch_id);
        $this->assertSame([$this->admin->id, $this->ak->id], $rows[0]->new_values['recipient_ids']);
        $this->assertSame('KDY daily I&E', $rows[0]->new_values['name']);
        $this->assertSame([$this->kdy->id], $rows[0]->new_values['branch_ids']);
        $this->assertSame('10.0.0.5', $rows[0]->ip);
    }

    public function test_ac2_create_w1_and_preview_time(): void
    {
        $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->w1Body())->assertCreated()
            ->assertJsonPath('next_run_at', '2026-11-23T01:30:00Z')
            ->assertJsonPath('next_period', ['from' => '2026-11-16', 'to' => '2026-11-22'])
            ->assertJsonPath('branches', [])
            ->assertJsonPath('branches_label', 'All branches');
        $this->assertNull(AuditLog::where('event', 'report_schedule.created')->first()->branch_id);

        $this->as($this->admin)->getJson('/api/v1/report-schedules/preview-time?frequency=weekly&weekday=7&send_time=07:00')->assertOk()
            ->assertExactJson(['next_run_at' => '2026-11-22T01:30:00Z', 'period_from' => '2026-11-09', 'period_to' => '2026-11-15']);
        $this->as($this->admin)->getJson('/api/v1/report-schedules/preview-time?frequency=daily&send_time=07:00')->assertOk()
            ->assertExactJson(['next_run_at' => '2026-11-21T01:30:00Z', 'period_from' => '2026-11-20', 'period_to' => '2026-11-20']);
        $this->as($this->admin)->getJson('/api/v1/report-schedules/preview-time?frequency=daily&send_time=07:15')->assertStatus(422)
            ->assertJsonPath('errors.send_time.0', 'Choose a time on the hour or half hour.');
    }

    public function test_ac3_validation(): void
    {
        $this->createD1();
        $cases = [
            [['name' => 'kdy DAILY i&e'], 'name', 'A schedule with this name already exists.'],
            [['name' => 'Other', 'send_time' => '07:15'], 'send_time', 'Choose a time on the hour or half hour.'],
            [['name' => 'Other', 'frequency' => 'weekly', 'weekday' => null], 'weekday', 'Choose the day of the week.'],
            [['name' => 'Other', 'report' => 'trial-balance'], 'report', 'Choose one of the listed reports.'],
            [['name' => 'Other', 'report' => 'cash-book', 'branches' => []], 'branches', 'Select at least one branch.'],
            [['name' => 'Other', 'recipient_ids' => range(1000, 1020)], 'recipient_ids', 'Choose at most 20 recipients.'],
            [['name' => 'Other', 'recipient_ids' => []], 'recipient_ids', 'Choose at least one recipient.'],
            [['name' => 'Other', 'branches' => [9999]], 'branches', 'Unknown branch.'],
            [['name' => 'ab'], 'name', 'The name must be 3 to 100 characters.'],
        ];
        foreach ($cases as [$over, $field, $message]) {
            $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->d1Body($over))->assertStatus(422)
                ->assertJsonPath("errors.{$field}.0", $message);
        }
        $this->assertSame(1, ReportSchedule::count());
        $this->assertSame(2, ReportScheduleRecipient::count());
        $this->assertSame(1, AuditLog::where('event', 'like', 'report_schedule.%')->count());
    }

    public function test_ac4_recipient_check_at_save_and_options(): void
    {
        $d1 = $this->createD1();
        $put = fn (array $ids) => $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['recipient_ids' => $ids]));
        $put([$this->admin->id, $this->ak->id, $this->kv->id])->assertStatus(422)
            ->assertJsonPath('errors.recipient_ids.0', 'Kasun Viewer cannot receive this report: No View reports or Export permission in KDY.');
        $put([$this->admin->id, $this->ak->id, $this->ga->id])->assertStatus(422)
            ->assertJsonPath('errors.recipient_ids.0', 'Gamini Accountant cannot receive this report: Not a member of KDY.');
        $put([$this->admin->id, $this->ak->id, $this->ax->id])->assertStatus(422)
            ->assertJsonPath('errors.recipient_ids.0', 'Ajith Inactive cannot receive this report: Inactive user.');

        $w1 = $this->createW1();
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$w1->id}", $this->w1Body(['recipient_ids' => [$this->admin->id, $this->sa2->id, $this->ak->id]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.recipient_ids.0', 'Anula Kumari cannot receive this report: Only Super Admins can receive reports covering more than one branch.');
        // A Branch Admin of KDY can receive KDY's single-branch report.
        $put([$this->admin->id, $this->ak->id, $this->kba->id])->assertOk();

        $opts = collect($this->as($this->admin)->getJson("/api/v1/report-schedules/recipient-options?branches[]={$this->kdy->id}")->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($opts[$this->kv->id]['eligible']);
        $this->assertSame('No View reports or Export permission in KDY', $opts[$this->kv->id]['reason']);
        $this->assertFalse($opts[$this->ga->id]['eligible']);
        $this->assertSame('Not a member of KDY', $opts[$this->ga->id]['reason']);
        $this->assertFalse($opts[$this->ax->id]['eligible']);
        $this->assertSame('Inactive user', $opts[$this->ax->id]['reason']);
        $this->assertTrue($opts[$this->ak->id]['eligible']);
        $this->assertNull($opts[$this->ak->id]['reason']);
        $this->assertTrue($opts[$this->sa2->id]['eligible']);
        $names = array_column($this->as($this->admin)->getJson('/api/v1/report-schedules/recipient-options')->json('data'), 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        // Multi-branch: only Super Admins.
        $multi = collect($this->as($this->admin)->getJson("/api/v1/report-schedules/recipient-options?branches[]={$this->kdy->id}&branches[]={$this->gal->id}")->json('data'))->keyBy('id');
        $this->assertSame([$this->admin->id, $this->sa2->id], $multi->where('eligible', true)->keys()->sort()->values()->all());
        $this->assertSame('Only Super Admins can receive reports covering more than one branch', $multi[$this->ak->id]['reason']);
    }

    public function test_ac5_super_admin_only(): void
    {
        $d1 = $this->createD1();
        $routes = [
            ['getJson', '/api/v1/report-schedules'], ['postJson', '/api/v1/report-schedules'],
            ['getJson', "/api/v1/report-schedules/{$d1->id}"], ['putJson', "/api/v1/report-schedules/{$d1->id}"],
            ['getJson', "/api/v1/report-schedules/{$d1->id}/runs"], ['postJson', "/api/v1/report-schedules/{$d1->id}/pause"],
            ['postJson', "/api/v1/report-schedules/{$d1->id}/resume"], ['postJson', "/api/v1/report-schedules/{$d1->id}/archive"],
            ['postJson', "/api/v1/report-schedules/{$d1->id}/restore"], ['getJson', '/api/v1/report-schedules/recipient-options'],
            ['getJson', '/api/v1/report-schedules/preview-time'],
        ];
        foreach ([$this->kba, $this->ak] as $u) {
            foreach ($routes as [$method, $url]) {
                $this->as($u)->{$method}($url, ...($method === 'getJson' ? [] : [$this->d1Body()]))->assertStatus(403)->assertJsonPath('message', 'Super Admin only.');
            }
        }
        // Super Admin in branch mode works the same.
        $this->actingAs($this->admin)->withHeader('X-Branch-Id', (string) $this->gal->id)->getJson('/api/v1/report-schedules')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('active', $d1->fresh()->status);
    }

    public function test_ac6_no_delete_and_immutable_rows(): void
    {
        $d1 = $this->createD1();
        $this->as($this->admin)->deleteJson("/api/v1/report-schedules/{$d1->id}")->assertStatus(405);
        $this->dispatchAt('2026-11-21 07:00');
        $this->work();
        $run = $this->latestRun($d1);
        $this->assertNotNull($run->sha256);

        foreach ([['report_schedules', 'id', $d1->id], ['report_schedule_recipients', 'schedule_id', $d1->id], ['report_schedule_runs', 'id', $run->id],
            ['report_schedule_deliveries', 'run_id', $run->id]] as [$table, $col, $val]) {
            $this->assertRefused(fn () => DB::table($table)->where($col, $val)->delete());
        }
        $this->assertRefused(fn () => DB::table('report_schedule_runs')->where('id', $run->id)->update(['params' => json_encode(['type' => 'income'])]));
        $this->assertRefused(fn () => DB::table('report_schedule_runs')->where('id', $run->id)->update(['period_from' => '2026-11-19']));
        $this->assertRefused(fn () => DB::table('report_schedule_runs')->where('id', $run->id)->update(['sha256' => str_repeat('0', 64)]));
        $this->assertRefused(fn () => DB::table('report_schedule_deliveries')->where('run_id', $run->id)->update(['email' => 'x@evil.example']));
        // Status columns may change (that is how a run moves on).
        DB::table('report_schedule_runs')->where('id', $run->id)->update(['error' => null]);

        foreach ([$d1->fresh(), $run, $run->deliveries()->first(), ReportScheduleRecipient::first()] as $model) {
            try {
                $model->delete();
                $this->fail('delete() was allowed on '.$model::class);
            } catch (ImmutableRecordException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, ReportSchedule::count());
    }

    private function assertRefused(callable $fn): void
    {
        try {
            DB::transaction(fn () => $fn());
            $this->fail('The database allowed the change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }
    }

    public function test_ac26_pause(): void
    {
        $d1 = $this->createD1();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk()
            ->assertJsonPath('status', 'paused')->assertJsonPath('paused_reason', 'manual')->assertJsonPath('next_run_at', null);
        $this->assertNull($d1->fresh()->next_run_at);
        $this->dispatchAt('2026-11-21 07:00');
        $this->assertSame(0, ReportScheduleRun::count());
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertStatus(422)->assertJsonPath('message', 'This schedule is not active.');
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.paused')->count());
    }

    public function test_resume_from_next_future_time_and_recipient_check(): void
    {
        $d1 = $this->createD1();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/resume")->assertStatus(422)->assertJsonPath('message', 'This schedule is not paused.');
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 08:00', 'Asia/Colombo'));
        // F7: paused periods are not sent afterwards.
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/resume")->assertOk()
            ->assertJsonPath('status', 'active')->assertJsonPath('next_run_at', '2026-11-24T01:30:00Z')->assertJsonPath('owner.id', $this->admin->id);
        $this->assertSame(0, AuditLog::where('event', 'report_schedule.owner_changed')->count());
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.resumed')->count());

        // Nobody on the list can receive now: resume is refused.
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertOk();
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['recipient_ids' => [$this->ak->id]]))->assertOk();
        $this->ak->update(['status' => 'inactive']);
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/resume")->assertStatus(422)
            ->assertJsonPath('message', 'None of the recipients can receive this report. Change the recipients first.');
        $this->assertSame('paused', $d1->fresh()->status);
    }

    public function test_ac28_archive_and_restore(): void
    {
        $d1 = $this->createD1();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/archive", ['reason' => 'Branch closed'])->assertOk()
            ->assertJsonPath('status', 'archived')->assertJsonPath('archive_reason', 'Branch closed')->assertJsonPath('next_run_at', null);
        $this->as($this->admin)->getJson('/api/v1/report-schedules')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->admin)->getJson('/api/v1/report-schedules?status=all')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/v1/report-schedules?status=archived')->assertOk()->assertJsonPath('data.0.id', $d1->id);
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '08:00']))->assertStatus(422)
            ->assertJsonPath('message', 'Restore this schedule before editing it.');
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/archive")->assertStatus(422)->assertJsonPath('message', 'This schedule is already archived.');
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/pause")->assertStatus(422);
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/resume")->assertStatus(422);
        $this->assertSame('Branch closed', AuditLog::where('event', 'report_schedule.archived')->first()->new_values['reason']);

        // Another schedule took the name meanwhile: restore is refused.
        $other = $this->createD1(['branches' => [$this->gal->id], 'recipient_ids' => [$this->admin->id]]);
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/restore")->assertStatus(422)
            ->assertJsonPath('message', 'A schedule with this name already exists.');
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$other->id}/archive")->assertOk();
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$d1->id}/restore")->assertOk()->assertJsonPath('status', 'paused');
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.restored')->count());
    }

    public function test_ac29_update_audit_and_recipient_history(): void
    {
        $d1 = $this->createD1();
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '06:30']))->assertOk()
            ->assertJsonPath('next_run_at', '2026-11-21T01:00:00Z');
        $rows = AuditLog::where('event', 'report_schedule.updated')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['send_time' => '07:00'], $rows[0]->old_values);
        $this->assertSame(['send_time' => '06:30'], $rows[0]->new_values);
        $this->assertSame($this->kdy->id, $rows[0]->branch_id);

        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '06:30']))->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'report_schedule.updated')->count());

        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '06:30', 'recipient_ids' => [$this->admin->id]]))->assertOk();
        $last = AuditLog::where('event', 'report_schedule.updated')->latest('id')->first();
        $this->assertSame(['recipient_ids' => [$this->admin->id, $this->ak->id]], $last->old_values);
        $this->assertSame(['recipient_ids' => [$this->admin->id]], $last->new_values);
        $akRow = ReportScheduleRecipient::where('user_id', $this->ak->id)->first();
        $this->assertNotNull($akRow->removed_at);
        $this->assertSame($this->admin->id, $akRow->removed_by);

        // Adding AK back creates a new row; the old one is kept.
        $this->as($this->admin)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '06:30']))->assertOk();
        $this->assertSame(2, ReportScheduleRecipient::where('user_id', $this->ak->id)->count());
        $this->assertSame(1, ReportScheduleRecipient::where('user_id', $this->ak->id)->whereNull('removed_at')->count());

        // Editing by another Super Admin does not change the owner (R3).
        $this->as($this->sa2)->putJson("/api/v1/report-schedules/{$d1->id}", $this->d1Body(['send_time' => '07:30']))->assertOk()
            ->assertJsonPath('owner.id', $this->admin->id)->assertJsonPath('updated_by.id', $this->sa2->id);
    }

    public function test_ac30_active_limit(): void
    {
        config(['scheduled_reports.max_active' => 3]);
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->createD1(['name' => "Schedule {$i}"])->id;
        }
        $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->d1Body(['name' => 'One more']))->assertStatus(422)
            ->assertJsonPath('message', 'You can have at most 3 active schedules.');
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$ids[0]}/pause")->assertOk();
        $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->d1Body(['name' => 'One more']))->assertCreated();
        // Resuming would pass the limit.
        $this->as($this->admin)->postJson("/api/v1/report-schedules/{$ids[0]}/resume")->assertStatus(422);
        $this->assertSame(3, ReportSchedule::where('status', 'active')->count());
    }

    public function test_daily_ignores_weekday_and_params_are_normalised(): void
    {
        $s = $this->createD1(['weekday' => 3, 'params' => ['type' => 'expense']]);
        $this->assertNull($s->weekday);
        $this->assertSame(['type' => 'expense'], $s->params);
        $t = $this->createD1(['name' => 'Transfers KDY', 'report' => 'transfers', 'params' => ['types' => ['fund_return', 'fund_allocation']]]);
        $this->assertSame(['types' => ['fund_allocation', 'fund_return']], $t->params);
        $this->as($this->admin)->getJson("/api/v1/report-schedules/{$t->id}")->assertOk()->assertJsonPath('params_label', 'Types: Top-up, Fund return');
        $this->as($this->admin)->getJson('/api/v1/report-schedules/99999')->assertStatus(404)->assertJsonPath('message', 'Schedule not found.');
        $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->d1Body(['name' => 'Bad types', 'report' => 'transfers', 'params' => ['types' => ['income']]]))
            ->assertStatus(422);
    }

    public function test_list_flags_recipients_who_cannot_receive(): void
    {
        $d1 = $this->createD1();
        $this->branchRole($this->kdy, 'Accountant')->revokePermissionTo('report.export');
        $row = $this->as($this->admin)->getJson('/api/v1/report-schedules')->assertOk()->json('data.0');
        $this->assertSame(1, $row['cannot_receive']);
        $ak = collect($row['recipients'])->firstWhere('id', $this->ak->id);
        $this->assertFalse($ak['eligible']);
        $this->assertSame('No View reports or Export permission in KDY', $ak['reason']);
        $this->assertNull($row['last_run']);
        $this->assertSame($d1->id, $row['id']);
        $this->assertInstanceOf(User::class, User::find($this->ak->id));
    }
}
