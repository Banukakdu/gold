<?php

namespace Tests\Feature\ReconciliationReminders;

use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AG-033f (docs/specs/reconciliation-overdue-reminder.md section 7): AC 7 to 12 and 14 (reconciled, catch-up, undo,
 * fallback, Super Admin as holder, redaction).
 */
class LifecycleTest extends RemindersTestCase
{
    private function completeKdyNov(string $colombo, User $by): BankStatement
    {
        Carbon::setTestNow(Carbon::parse($colombo, 'Asia/Colombo'));

        return $this->statement($this->complete('2026-11-30', '469500.00', $by));
    }

    /** AC 7 steps: AC 1's run, then ACC completes KDY Bank to 2026-11-30 on 2026-12-12. */
    private function reconciledKdy(): BankStatement
    {
        $this->remind();
        Carbon::setTestNow(Carbon::parse('2026-12-12 09:00', 'Asia/Colombo'));
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();

        return $this->completeKdyNov('2026-12-12 09:05', $this->acc);
    }

    public function test_ac7_completing_to_e_reads_the_reminder_for_everyone(): void
    {
        $this->remind();
        Carbon::setTestNow(Carbon::parse('2026-12-12 09:00', 'Asia/Colombo'));
        $this->tick([[$this->i2, '2026-11-02'], [$this->e2, '2026-11-03']])->assertOk();
        $auditMax = (int) AuditLog::max('id');
        $this->completeKdyNov('2026-12-12 09:05', $this->acc);

        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertNotNull($this->rows($u, $this->kdyBank)->sole()->read_at);
            $this->assertNull($this->rows($u, $this->bank5)->sole()->read_at);
            $this->assertNull($this->rows($u, $this->bank2)->sole()->read_at);
        }
        $this->assertSame(['reconciliation.completed'], AuditLog::where('id', '>', $auditMax)->pluck('event')->all());

        $before = $this->counts();
        [, $out] = $this->remindAtColombo('2026-12-13 07:15');
        $this->assertSame($before, $this->counts());
        $this->assertStringContainsString('Sent 0 reminder(s), skipped 3 already sent, 0 failed.', $out);
    }

    public function test_ac8_partial_catch_up_closes_nothing_and_january_counts_from_it(): void
    {
        $this->remind();
        Carbon::setTestNow(Carbon::parse('2026-12-11 10:00', 'Asia/Colombo'));
        $this->statement($this->complete('2026-10-31', '8000.00', $this->acc, $this->bank5));
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertNull($this->rows($u, $this->bank5)->sole()->read_at);
        }
        foreach (['2026-12-12', '2026-12-20', '2026-12-31'] as $day) {
            $this->remindAtColombo("{$day} 07:15");
        }
        $this->assertCount(1, $this->reminders($this->bank5));

        $this->remindAtColombo('2027-01-11 07:15');
        $this->assertSame([[$this->bank5->id, '2026-11-30', 3, 1], [$this->bank5->id, '2026-12-31', 2, 1]], $this->brief($this->reminders($this->bank5)));
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            [$old, $new] = $this->rows($u, $this->bank5)->all();
            $this->assertNotNull($old->fresh()->read_at);
            $this->assertNull($new->read_at);
            $this->assertSame('Not reconciled to 31 Dec 2026. Last statement 31 Oct 2026 · overdue 2 months.', $new->data['body']);
        }
    }

    public function test_ac9_overdue_mid_month_after_an_undo(): void
    {
        $this->remind();
        $before = $this->counts();
        Carbon::setTestNow(Carbon::parse('2026-12-15 10:00', 'Asia/Colombo'));
        $this->undoOn($this->galBank, $this->galNov, $this->gacc);
        $this->remindAtColombo('2026-12-16 07:15');

        $n = $this->rows($this->gacc)->sole();
        $this->assertSame('GAL', $n->data['branch_code']);
        $this->assertSame('Not reconciled to 30 Nov 2026. Last statement 31 Oct 2026 · overdue 1 month.', $n->data['body']);
        foreach ([$this->acc, $this->vw, $this->ba, $this->admin, $this->sa2] as $u) {
            $this->assertCount(0, $this->rows($u, $this->galBank));
        }
        $after = $this->counts();
        $this->assertSame($before['reminders'] + 1, $after['reminders']);
        $this->assertSame($before['audit'] + 1, $after['audit']);
        $this->assertFalse((bool) $this->reminders($this->galBank)->sole()->super_admin_fallback);
    }

    public function test_ac10_undone_after_the_reminder_sends_a_new_round(): void
    {
        $nov = $this->reconciledKdy();
        // BA undoes the 30 Nov statement at 01:30 on 14 Dec in Colombo (still 13 Dec in UTC).
        Carbon::setTestNow(Carbon::parse('2026-12-13 20:00:00', 'UTC'));
        $this->undoOn($this->kdyBank, $nov, $this->ba);
        $row = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/reports/reconciliation-status')->assertOk()->json('data'))
            ->firstWhere('account.id', $this->kdyBank->id);
        $this->assertSame(['overdue', 1], [$row['status'], $row['months_overdue']]);

        $this->remindAtColombo('2026-12-15 07:15');
        $this->assertSame([[$this->kdyBank->id, '2026-11-30', 1, 1], [$this->kdyBank->id, '2026-11-30', 1, 2]], $this->brief($this->reminders($this->kdyBank)));
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $n = $this->unread($u, $this->kdyBank)->sole();
            $this->assertSame('Not reconciled to 30 Nov 2026. Last statement 31 Oct 2026 · overdue 1 month. The statement to 30 Nov 2026 was undone on 14 Dec 2026.',
                $n->data['body']);
        }
        $a = AuditLog::where('event', 'reconciliation.overdue_reminder_sent')->where('auditable_id', $this->kdyBank->id)->orderByDesc('id')->first();
        $this->assertSame(2, $a->new_values['round']);
        $this->assertSame($nov->id, $a->new_values['undone_statement_id']);
        $this->assertSame($this->ids($this->acc, $this->vw, $this->ba), $a->new_values['recipient_ids']);

        foreach (['2026-12-16', '2026-12-17', '2026-12-18', '2026-12-19'] as $day) {
            $this->remindAtColombo("{$day} 07:15");
        }
        $this->assertCount(2, $this->reminders($this->kdyBank));

        // Complete and undo once more on 20 Dec: round 3, and the round 2 rows are read.
        $again = $this->completeKdyNov('2026-12-20 09:00', $this->acc);
        Carbon::setTestNow(Carbon::parse('2026-12-20 11:00', 'Asia/Colombo'));
        $this->undoOn($this->kdyBank, $again, $this->ba);
        $this->remindAtColombo('2026-12-21 07:15');
        $this->assertSame([1, 2, 3], $this->reminders($this->kdyBank)->pluck('round')->map(fn ($v) => (int) $v)->all());
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $rows = $this->rows($u, $this->kdyBank);
            $this->assertCount(3, $rows);
            $this->assertSame(1, $rows->whereNull('read_at')->count());
            $this->assertStringEndsWith('The statement to 30 Nov 2026 was undone on 20 Dec 2026.', $rows->whereNull('read_at')->first()->data['body']);
        }
        foreach (['2026-12-22', '2026-12-31'] as $day) {
            $this->remindAtColombo("{$day} 07:15");
        }
        $this->assertCount(3, $this->reminders($this->kdyBank));

        $this->remindAtColombo('2027-01-11 07:15');
        $jan = $this->reminders($this->kdyBank)->last();
        $this->assertSame([$this->kdyBank->id, '2026-12-31', 2, 1], $this->brief(collect([$jan]))[0]);
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertSame(['2026-12-31'], $this->unread($u, $this->kdyBank)->pluck('data.overdue_through')->all());
        }
    }

    public function test_ac10_undo_of_a_statement_before_e_sends_nothing_more(): void
    {
        $this->remind();
        Carbon::setTestNow(Carbon::parse('2026-12-11 10:00', 'Asia/Colombo'));
        $oct = $this->statement($this->complete('2026-10-31', '8000.00', $this->acc, $this->bank5));
        Carbon::setTestNow(Carbon::parse('2026-12-12 10:00', 'Asia/Colombo'));
        $this->undoOn($this->bank5, $oct, $this->ba);
        foreach (['2026-12-13', '2026-12-20', '2026-12-31'] as $day) {
            $this->remindAtColombo("{$day} 07:15");
        }
        $this->assertCount(1, $this->reminders($this->bank5));
    }

    public function test_ac11_super_admin_fallback_when_nobody_can_receive_it(): void
    {
        $this->remind();
        Carbon::setTestNow(Carbon::parse('2026-12-15 10:00', 'Asia/Colombo'));
        $this->undoOn($this->galBank, $this->galNov, $this->gacc);
        $this->gacc->update(['status' => 'inactive']);
        $this->remindAtColombo('2026-12-16 07:15');

        foreach ([$this->admin, $this->sa2] as $sa) {
            $n = $this->rows($sa, $this->galBank)->sole();
            $this->assertSame('GAL', $n->data['branch_code']);
            $this->assertStringEndsWith('overdue 1 month. No one in GAL can reconcile this account.', $n->data['body']);
        }
        $this->assertCount(0, $this->rows($this->sa3));
        $this->assertCount(0, $this->rows($this->gacc));
        $r = $this->reminders($this->galBank)->sole();
        $this->assertTrue((bool) $r->super_admin_fallback);
        $this->assertSame($this->ids($this->admin, $this->sa2), json_decode($r->recipient_ids, true));
        $a = AuditLog::where('event', 'reconciliation.overdue_reminder_sent')->where('auditable_id', $this->galBank->id)->sole();
        $this->assertTrue($a->new_values['super_admin_fallback']);
    }

    public function test_ac11_finding3_a_branch_with_only_a_checker_also_falls_back(): void
    {
        $this->remind();
        $gvw = $this->userIn($this->gal, 'Viewer');
        Carbon::setTestNow(Carbon::parse('2026-12-15 10:00', 'Asia/Colombo'));
        $this->undoOn($this->galBank, $this->galNov, $this->gacc);
        $this->gacc->update(['status' => 'inactive']);
        $this->remindAtColombo('2026-12-16 07:15');

        foreach ([$gvw, $this->admin, $this->sa2] as $u) {
            $this->assertStringEndsWith(' No one in GAL can reconcile this account.', $this->rows($u, $this->galBank)->sole()->data['body']);
        }
        $this->assertCount(0, $this->rows($this->sa3));
        $r = $this->reminders($this->galBank)->sole();
        $this->assertTrue((bool) $r->super_admin_fallback);
        $this->assertSame($this->ids($gvw, $this->admin, $this->sa2), json_decode($r->recipient_ids, true));
    }

    public function test_ac11_a_preparer_in_the_branch_means_no_fallback(): void
    {
        $this->remind();
        $gvw = $this->userIn($this->gal, 'Viewer');
        Carbon::setTestNow(Carbon::parse('2026-12-15 10:00', 'Asia/Colombo'));
        $this->undoOn($this->galBank, $this->galNov, $this->gacc);
        $this->remindAtColombo('2026-12-16 07:15');

        $this->assertSame($this->ids($this->gacc, $gvw), json_decode($this->reminders($this->galBank)->sole()->recipient_ids, true));
        $this->assertSame('Not reconciled to 30 Nov 2026. Last statement 31 Oct 2026 · overdue 1 month.', $this->rows($gvw, $this->galBank)->sole()->data['body']);
        $this->assertCount(0, $this->rows($this->admin, $this->galBank));
        $this->assertCount(0, $this->rows($this->sa2, $this->galBank));
    }

    public function test_ac12_super_admin_holding_a_branch_role_is_a_holder(): void
    {
        $this->admin->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $this->admin->assignRole($this->branchRole($this->kdy, 'Branch Admin'));
        $this->remind();

        $rows = $this->rows($this->admin);
        $this->assertCount(4, $rows);
        $this->assertSame(['KDY' => 3, 'MAIN' => 1], $rows->countBy(fn ($n) => $n->data['branch_code'])->sortKeys()->all());
        $this->assertCount(0, $this->rows($this->sa2)->where('data.branch_code', 'KDY'));
    }

    public function test_ac14_removed_user_sees_redacted_rows_and_gets_no_new_one(): void
    {
        $this->remind();
        $stored = DB::table('notifications')->where('notifiable_id', $this->vw->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->sa($this->kdy)->postJson("/api/v1/users/{$this->vw->id}/remove-from-branch")->assertOk();

        $data = $this->flushHeaders()->actingAs($this->vw->fresh())->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertCount(3, $data);
        foreach ($data as $row) {
            $this->assertSame('Notification for KDY (no longer available)', $row['title']);
            $this->assertNull($row['body']);
        }
        $this->assertSame($stored, DB::table('notifications')->where('notifiable_id', $this->vw->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());

        $this->remindAtColombo('2027-01-11 07:15');
        $this->assertCount(3, $this->rows($this->vw));
        $this->assertCount(6, $this->rows($this->acc));
        foreach ($this->reminders()->filter(fn ($r) => substr((string) $r->overdue_through, 0, 10) === '2026-12-31') as $r) {
            $this->assertNotContains($this->vw->id, json_decode($r->recipient_ids, true));
        }
    }
}
