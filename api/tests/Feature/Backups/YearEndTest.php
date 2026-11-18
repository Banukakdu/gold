<?php

namespace Tests\Feature\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\PeriodLock;
use App\Models\YearEndClose;
use App\Notifications\YearEndSnapshotNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** Review F1, F7, F8, F9 (AC 41, 48, 49). FY 2026-27 (April start) holds the setup's income of 2026-11-17. */
class YearEndTest extends BackupsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['backup.offsite_disk' => 'backups_offsite']);
    }

    private function closeFy(string $at = '2027-04-10 15:00:00'): YearEndClose
    {
        $this->freeze($at);
        if (! PeriodLock::active()->whereNull('branch_id')->where('period', '2027-03')->exists()) {
            $this->sa()->postJson('/api/v1/period-locks', ['period' => '2027-03'])->assertCreated();
        }
        $this->sa()->postJson('/api/v1/fiscal-years/2026/close', ['confirm' => '2026-27'])->assertCreated();

        return YearEndClose::active()->sole();
    }

    private function reopenFy(): void
    {
        $this->sa()->postJson('/api/v1/fiscal-years/2026/reopen', ['reason' => 'Late supplier bill for March'])->assertOk();
    }

    public function test_ac41_first_run_after_the_close_is_the_year_end_snapshot(): void
    {
        $close = $this->closeFy();
        $this->freeze('2027-04-11 01:30:00');
        [$code, $out] = $this->backupRun();
        $this->assertSame(0, $code, $out);
        $b = Backup::sole();
        $this->assertSame('year_end', $b->kept_as);
        $this->assertSame(2026, $b->fiscal_year);
        $this->assertSame($close->id, $b->year_end_close_id);
        $this->assertSame('FY 2026-27', $b->fiscalYearLabel());
        $m = $b->manifest['year_end'];
        $this->assertSame($close->id, $m['close_id']);
        $this->assertSame('FY 2026-27', $m['label']);
        $off = Storage::disk('backups_offsite');
        $off->assertExists(["yearly/FY2026-27/{$b->filename}", "yearly/FY2026-27/{$b->filename}.sha256", "daily/{$b->filename}"]);
        $this->assertSame(1, AuditLog::where('event', 'backup.year_end')->count());

        // F7: restore-tested by the same command.
        $this->assertSame('passed', $b->verify_status);
        $this->assertSame(1, AuditLog::where('event', 'backup.verified')->count());

        // F8: the hash email.
        Notification::assertSentTo([$this->sa, $this->sa2], YearEndSnapshotNotification::class,
            fn ($n) => $n->sha256 === $b->sha256 && $n->label === 'FY 2026-27' && preg_match('/^[\d,]+\.\d{2}$/', $n->total) === 1 && strlen($n->sha256) === 64);
        Notification::assertNotSentTo($this->sa3, YearEndSnapshotNotification::class);

        $this->freeze('2027-04-12 01:30:00');
        $this->backupRun();
        $this->assertSame('daily', Backup::where('filename', 'ag-backup-20270412-013000.zip')->value('kept_as'));
    }

    public function test_ac41_reopened_before_the_run_is_daily_and_a_new_close_gives_a_second_snapshot(): void
    {
        $this->closeFy();
        $this->freeze('2027-04-10 16:00:00');
        $this->reopenFy();
        $this->freeze('2027-04-11 01:30:00');
        $this->backupRun();
        $this->assertSame('monthly', Backup::sole()->kept_as); // the first of April, not a year-end snapshot
        $this->assertNull(Backup::sole()->fiscal_year);

        $first = $this->closeFy('2027-04-11 10:00:00');
        $this->freeze('2027-04-12 01:30:00');
        $this->backupRun();
        $this->assertSame('year_end', Backup::where('filename', 'ag-backup-20270412-013000.zip')->value('kept_as'));

        $this->freeze('2027-04-12 11:00:00');
        $this->reopenFy();
        $second = $this->closeFy('2027-04-12 12:00:00');
        $this->assertNotSame($first->id, $second->id);
        $this->freeze('2027-04-13 01:30:00');
        $this->backupRun();
        $this->assertSame(2, Backup::where('kept_as', 'year_end')->count(), 'the first snapshot is kept');
        $this->assertSame($second->id, Backup::where('filename', 'ag-backup-20270413-013000.zip')->value('year_end_close_id'));
    }

    public function test_year_end_option(): void
    {
        $this->freeze('2027-04-10 14:00:00');
        [$code, $out] = $this->backupRun(['--year-end' => '2026']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('FY 2026-27 is not closed.', $out);
        $this->assertSame(0, Backup::count());

        $this->closeFy();
        $this->freeze('2027-04-10 15:05:00');
        [$code, $out] = $this->artisan_('backup:run', ['--year-end' => null]);
        $this->assertSame(0, $code, $out);
        $this->assertSame('year_end', Backup::sole()->kept_as);
    }

    public function test_ac49_warning_at_the_closing_points_never_blocks(): void
    {
        $this->backupRun();
        $this->freeze('2026-11-21 09:00:00'); // last backup about 3 days ago, no restore test
        $review = $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->assertOk()->json();
        $this->assertSame('Last successful backup 3 days ago. No restore test has passed in the last 8 days. Fix backups before closing.', $review['backup_warning']);
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2026-10'])->assertCreated();

        $this->freeze('2027-04-10 15:00:00');
        $this->sa()->postJson('/api/v1/period-locks', ['period' => '2027-03'])->assertCreated();
        $preview = $this->sa()->getJson('/api/v1/fiscal-years/2026/preview')->assertOk()->json();
        $this->assertStringStartsWith('Last successful backup 143 days ago.', $preview['backup_warning']);
        $this->assertSame([], $preview['checks']);
        $this->sa()->postJson('/api/v1/fiscal-years/2026/close', ['confirm' => '2026-27'])->assertCreated();
    }

    public function test_warning_is_null_when_backups_are_healthy(): void
    {
        $this->backupRun();
        $this->artisan_('backup:verify', ['--latest' => true]);
        $this->freeze('2026-11-18 09:00:00');
        $this->assertNull($this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->json('backup_warning'));
    }
}
