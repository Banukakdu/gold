<?php

namespace Tests\Feature\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** Section 7 "Off-server" (AC 16-18) and review F3 (AC 44). */
class OffsiteTest extends BackupsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['backup.offsite_disk' => 'backups_offsite']);
    }

    public function test_ac16_first_backup_of_the_month_goes_to_daily_and_monthly(): void
    {
        [$code, $out] = $this->backupRun();
        $this->assertSame(0, $code, $out);
        $off = Storage::disk('backups_offsite');
        $off->assertExists(['daily/'.self::FIRST, 'daily/'.self::FIRST.'.sha256', 'daily/'.self::FIRST.'.manifest.json', 'monthly/'.self::FIRST,
            'monthly/'.self::FIRST.'.sha256']);
        $b = Backup::sole();
        $this->assertSame('copied', $b->offsite_status);
        $this->assertSame('monthly', $b->kept_as);
        $this->assertSame(Storage::disk('backups')->get(self::FIRST), $off->get('daily/'.self::FIRST));

        $this->freeze('2026-11-19 01:30:00');
        $this->assertSame(0, $this->backupRun()[0]);
        $second = Backup::where('filename', 'ag-backup-20261119-013000.zip')->sole();
        $this->assertSame('daily', $second->kept_as);
        $off->assertExists('daily/ag-backup-20261119-013000.zip');
        $off->assertMissing('monthly/ag-backup-20261119-013000.zip');
        $this->assertSame([], $off->directories('yearly'));
    }

    public function test_ac17_offsite_failure_keeps_the_backup_and_alerts(): void
    {
        file_put_contents("{$this->work}/not-a-dir", 'x');
        config(['filesystems.disks.broken' => ['driver' => 'local', 'root' => "{$this->work}/not-a-dir/sub", 'throw' => true],
            'backup.offsite_disk' => 'broken']);
        [$code] = $this->backupRun();
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('succeeded', $b->status);
        $this->assertSame('failed', $b->offsite_status);
        $this->assertNotEmpty($b->offsite_error);
        $this->assertSame(1, AuditLog::where('event', 'backup.offsite_failed')->count());
        Notification::assertSentTo([$this->sa, $this->sa2], BackupAlertNotification::class, fn ($n) => $n->stage === 'offsite');
        Notification::assertNotSentTo($this->sa3, BackupAlertNotification::class);
        Storage::disk('backups')->assertExists(self::FIRST);
    }

    public function test_ac18_not_configured(): void
    {
        config(['backup.offsite_disk' => '']);
        $this->backupRun();
        $this->assertSame('not_configured', Backup::sole()->offsite_status);
        $this->assertSame([], Storage::disk('backups_offsite')->allFiles());
        $s = $this->sa()->getJson('/api/v1/backups')->assertOk()->json('summary');
        $this->assertFalse($s['offsite_configured']);
        $this->assertFalse($s['offsite_required']);
    }

    public function test_ac44_existing_offsite_object_is_never_overwritten(): void
    {
        $off = Storage::disk('backups_offsite');
        $off->put('daily/'.self::FIRST, 'an earlier object');
        [$code] = $this->backupRun();
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('succeeded', $b->status);
        $this->assertSame('failed', $b->offsite_status);
        $this->assertSame('Off-server object already exists.', $b->offsite_error);
        $this->assertSame('an earlier object', $off->get('daily/'.self::FIRST));
        $this->assertSame(['daily/'.self::FIRST], $off->allFiles(), 'nothing else was written');
    }

    public function test_ac44_production_without_offsite_is_required(): void
    {
        config(['backup.offsite_disk' => '']);
        $this->app['env'] = 'production';
        $s = $this->sa()->getJson('/api/v1/backups')->assertOk()->json('summary');
        $this->app['env'] = 'testing';
        $this->assertFalse($s['offsite_configured']);
        $this->assertTrue($s['offsite_required']); // S1 shows the banner red
    }
}
