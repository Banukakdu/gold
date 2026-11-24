<?php

namespace Tests\Feature\ReconciliationReminders;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AG-033f (docs/specs/reconciliation-overdue-reminder.md section 7): AC 1 to 6, 17 and 19 (who, what, when, dedupe).
 */
class RemindTest extends RemindersTestCase
{
    public function test_ac1_one_reminder_per_overdue_account(): void
    {
        [$code, $out] = $this->remind();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Sent 4 reminder(s), skipped 0 already sent, 0 failed.', $out);
        $this->assertSame([
            [$this->mainAcc->id, '2026-11-30', 4, 1],
            [$this->kdyBank->id, '2026-11-30', 1, 1],
            [$this->bank2->id, '2026-11-30', 1, 1],
            [$this->bank5->id, '2026-11-30', 3, 1],
        ], $this->brief());
        $last = $this->reminders()->mapWithKeys(fn ($r) => [(int) $r->account_id => $r->last_statement_date === null ? null : substr((string) $r->last_statement_date, 0, 10)]);
        $this->assertNull($last[$this->mainAcc->id]);
        $this->assertNull($last[$this->bank2->id]);
        $this->assertSame('2026-10-31', $last[$this->kdyBank->id]);
        $this->assertSame('2026-09-15', $last[$this->bank5->id]);
        foreach ([$this->bank3, $this->kdyCash, $this->galBank] as $none) {
            $this->assertCount(0, $this->reminders($none));
        }
    }

    public function test_ac2_recipients(): void
    {
        $this->remind();
        $kdy = [$this->bank2->id, $this->bank5->id, $this->kdyBank->id];
        sort($kdy);
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $rows = $this->unread($u);
            $this->assertCount(3, $rows);
            $ids = $rows->map(fn ($n) => (int) $n->data['account_id'])->sort()->values()->all();
            $this->assertSame($kdy, $ids);
            $this->assertSame(['KDY'], $rows->pluck('data.branch_code')->unique()->values()->all());
        }
        foreach ([$this->mba, $this->admin, $this->sa2] as $u) {
            $rows = $this->unread($u);
            $this->assertCount(1, $rows);
            $this->assertSame($this->mainAcc->id, (int) $rows[0]->data['account_id']);
            $this->assertSame('MAIN', $rows[0]->data['branch_code']);
        }
        foreach ([$this->csh, $this->vw2, $this->rec, $this->gacc, $this->sa3] as $u) {
            $this->assertCount(0, $this->rows($u));
        }
    }

    public function test_ac3_texts_and_data(): void
    {
        $this->remind();
        $n = $this->rows($this->acc, $this->bank5)->sole();
        $this->assertSame('Bank reconciliation overdue: KDY Bank 5 ('.$this->bank5->code.')', $n->data['title']);
        $this->assertSame('Not reconciled to 30 Nov 2026. Last statement 15 Sep 2026 · overdue 3 months.', $n->data['body']);
        $this->assertSame('/reports/reconciliation-status?status=overdue', $n->data['url']);
        $this->assertSame($this->bank5->id, $n->data['account_id']);
        $this->assertSame('2026-11-30', $n->data['overdue_through']);
        $this->assertSame(3, $n->data['months_overdue']);
        $this->assertSame($this->kdy->id, $n->data['branch_id']);
        $this->assertSame('Not reconciled to 30 Nov 2026. Never reconciled · overdue 1 month.', $this->rows($this->acc, $this->bank2)->sole()->data['body']);
        $this->assertSame('Not reconciled to 30 Nov 2026. Never reconciled · overdue 4 months.', $this->rows($this->admin, $this->mainAcc)->sole()->data['body']);
        foreach (DatabaseNotification::where('type', self::KIND)->get() as $row) {
            $this->assertSame(['title', 'body', 'branch_id', 'branch_code', 'url', 'account_id', 'overdue_through', 'months_overdue'], array_keys($row->data));
            $this->assertStringNotContainsString('LKR', json_encode($row->data, JSON_UNESCAPED_UNICODE));
            $this->assertDoesNotMatchRegularExpression('/\d{1,3}(,\d{3})*\.\d{2}/', (string) $row->data['body']);
        }
    }

    public function test_ac4_reruns_send_nothing(): void
    {
        $this->remind();
        $before = $this->counts();
        $runs = [Carbon::parse('2026-12-11 09:00', 'Asia/Colombo')];
        for ($d = 12; $d <= 31; $d++) {
            $runs[] = Carbon::parse("2026-12-{$d} 07:15", 'Asia/Colombo');
        }
        foreach ($runs as $at) {
            [$code, $out] = $this->remind($at->utc()->format('Y-m-d H:i:s'));
            $this->assertSame(0, $code);
            $this->assertStringContainsString('Sent 0 reminder(s), skipped 4 already sent, 0 failed.', $out, $at->toIso8601String());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_ac5_business_date_decides_not_the_server_clock(): void
    {
        $this->remind('2026-12-10 18:29:59');
        $this->assertSame([], $this->remindedFor('2026-11-30'));
        $this->assertCount(0, $this->reminders($this->kdyBank));
        $this->assertCount(0, $this->reminders($this->bank2));

        $this->remind('2026-12-10 18:30:00');
        $ids = [$this->mainAcc->id, $this->kdyBank->id, $this->bank2->id, $this->bank5->id];
        sort($ids);
        $this->assertSame($ids, $this->remindedFor('2026-11-30'));
    }

    public function test_ac5_same_result_with_a_los_angeles_server_time_zone(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $this->remind('2026-12-10 18:29:59');
            $this->assertSame([], $this->remindedFor('2026-11-30'));
            $this->remind('2026-12-10 18:30:00');
            $ids = [$this->mainAcc->id, $this->kdyBank->id, $this->bank2->id, $this->bank5->id];
            sort($ids);
            $this->assertSame($ids, $this->remindedFor('2026-11-30'));
        } finally {
            date_default_timezone_set($tz);
        }
    }

    public function test_ac6_grace_then_supersede(): void
    {
        $this->remindAtColombo('2026-12-10 07:15');
        $this->assertSame([
            [$this->mainAcc->id, '2026-10-31', 3, 1],
            [$this->bank5->id, '2026-10-31', 2, 1],
        ], $this->brief());
        $oldFive = $this->rows($this->acc, $this->bank5)->sole();
        $oldMain = $this->rows($this->admin, $this->mainAcc)->sole();
        $this->assertNull($oldFive->read_at);
        $this->assertSame('Not reconciled to 31 Oct 2026. Last statement 15 Sep 2026 · overdue 2 months.', $oldFive->data['body']);

        $this->remindAtColombo('2026-12-11 07:15');
        $this->assertCount(6, $this->reminders());
        $ids = [$this->mainAcc->id, $this->kdyBank->id, $this->bank2->id, $this->bank5->id];
        sort($ids);
        $this->assertSame($ids, $this->remindedFor('2026-11-30'));
        $this->assertNotNull($oldFive->fresh()->read_at);
        $this->assertNotNull($oldMain->fresh()->read_at);
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertNotNull($this->rows($u, $this->bank5)->first()->read_at);
            $this->assertSame(['2026-11-30'], $this->unread($u, $this->bank5)->pluck('data.overdue_through')->all());
        }
    }

    public function test_ac17_same_accounts_as_s7_overdue_in_all_mode(): void
    {
        foreach (['2026-12-10', '2026-12-11', '2026-12-31'] as $day) {
            $this->remindAtColombo("{$day} 07:15");
            $r = $this->sa()->getJson('/api/v1/reports/reconciliation-status?status=overdue')->assertOk();
            $s7 = array_map(fn ($row) => (int) $row['account']['id'], $r->json('data'));
            sort($s7);
            $this->assertNotEmpty($s7);
            $this->assertSame($s7, $this->remindedFor($r->json('overdue_through')), $day);
        }
    }

    public function test_ac19_dry_run_writes_nothing(): void
    {
        $before = $this->counts();
        $audit = DB::table('audit_logs')->count();
        [$code, $out] = $this->remind(null, ['--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertSame($before, $this->counts());
        $this->assertSame($audit, DB::table('audit_logs')->count());
        $this->assertSame(4, substr_count($out, ': would send to '));
        foreach (['KDY Bank 5', 'KDY Bank 2', 'KDY Bank (', 'Main Account'] as $name) {
            $this->assertStringContainsString($name, $out);
        }
        $line = collect(explode("\n", $out))->first(fn ($l) => str_contains($l, 'KDY Bank 5'));
        foreach ([$this->acc, $this->vw, $this->ba] as $u) {
            $this->assertStringContainsString("{$u->name} (#{$u->id})", $line);
        }
        $this->assertStringNotContainsString($this->csh->name.' (#', $line);
        $this->assertStringContainsString('Would send 4 reminder(s), skipped 0 already sent, 0 failed.', $out);

        $this->remind();
        $after = $this->counts();
        [, $out] = $this->remind(null, ['--dry-run' => true]);
        $this->assertSame(4, substr_count($out, ': already sent'));
        $this->assertSame($after, $this->counts());
    }
}
