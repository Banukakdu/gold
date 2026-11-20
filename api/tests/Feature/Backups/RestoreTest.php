<?php

namespace Tests\Feature\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use App\Notifications\BackupRestoredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** AC 32 (backup:restored), review F4 continuity (AC 45) and F5 tokens (AC 46). */
class RestoreTest extends BackupsTestCase
{
    private function restored(string $file, string $reason = 'disk failure'): array
    {
        return $this->artisan_('backup:restored', ['filename' => $file, '--reason' => $reason]);
    }

    public function test_ac32_restored_audit_row_and_rows_recreated(): void
    {
        $this->backupRun();
        $this->freeze('2026-11-19 01:30:00');
        $this->backupRun();
        $second = Backup::where('filename', 'ag-backup-20261119-013000.zip')->sole();
        // Simulate the restored database: it has no row for the newer archive, which is still on the local disk.
        DB::unprepared('DROP TRIGGER backups_no_delete');
        DB::table('backups')->where('id', $second->id)->delete();

        $this->freeze('2026-11-19 09:00:00');
        [$code, $out] = $this->restored(self::FIRST);
        $this->assertSame(0, $code, $out);
        $a = AuditLog::where('event', 'backup.restored')->sole();
        $first = Backup::where('filename', self::FIRST)->sole();
        $this->assertSame(self::FIRST, $a->new_values['filename']);
        $this->assertSame($first->sha256, $a->new_values['sha256']);
        $this->assertSame('disk failure', $a->new_values['reason']);
        $this->assertNotEmpty($a->new_values['operator']);
        $this->assertSame($first->started_at->toIso8601String(), $a->new_values['backup_started_at']);
        $this->assertSame(['ag-backup-20261119-013000.zip'], $a->new_values['rows_recreated']);

        $re = Backup::where('filename', 'ag-backup-20261119-013000.zip')->sole();
        $this->assertSame('succeeded', $re->status);
        $this->assertSame($second->sha256, $re->sha256);
        $this->assertSame($second->size_bytes, $re->size_bytes);
        $this->assertSame('daily', $re->kept_as);
        $this->assertTrue($re->started_at->equalTo($second->started_at));
    }

    public function test_restored_completes_its_own_running_row(): void
    {
        $this->backupRun();
        // In the restored database the backup's own row is still `running` (the dump was taken mid-run).
        DB::table('backups')->update(['status' => 'running', 'sha256' => null, 'finished_at' => null]);
        $this->restored(self::FIRST);
        $b = Backup::sole();
        $this->assertSame('succeeded', $b->status);
        $this->assertSame(hash_file('sha256', $this->localPath()), $b->sha256);
    }

    public function test_ac46_restored_revokes_every_token_and_tells_the_super_admins(): void
    {
        $this->backupRun();
        foreach ([$this->sa, $this->ba, $this->acc] as $u) {
            $u->createToken('web');
        }
        $this->assertSame(3, DB::table('personal_access_tokens')->count());
        $this->restored(self::FIRST);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $a = AuditLog::where('event', 'backup.restored')->sole();
        $this->assertSame(3, $a->new_values['tokens_revoked']);
        $this->assertArrayHasKey('backup_started_at', $a->new_values);
        Notification::assertSentTo([$this->sa, $this->sa2], BackupRestoredNotification::class, fn ($n) => $n->filename === self::FIRST && $n->reason === 'disk failure');
        Notification::assertNotSentTo($this->sa3, BackupRestoredNotification::class);
        $this->assertFalse(in_array(ShouldQueue::class, class_implements(BackupRestoredNotification::class), true));
    }

    public function test_restored_needs_a_reason_and_a_known_backup(): void
    {
        $this->assertSame(1, $this->artisan_('backup:restored', ['filename' => self::FIRST])[0]);
        $this->assertSame(1, $this->restored('ag-backup-20200101-000000.zip')[0]);
        $this->assertSame(0, AuditLog::where('event', 'backup.restored')->count());
    }

    public function test_ac45_rollback_without_a_recorded_restore(): void
    {
        // A: a real backup on 16 Nov. B: a newer backup's manifest (17 Nov) recording audit_logs max id 500.
        $this->freeze('2026-11-16 01:30:00');
        $this->assertSame(0, $this->backupRun()[0]);
        $a = 'ag-backup-20261116-013000.zip';
        $m = json_decode(Storage::disk('backups')->get("{$a}.manifest.json"), true);
        $m['backup'] = 'ag-backup-20261117-013000.zip';
        $m['created_at'] = '2026-11-16T20:00:00+00:00';
        $m['max_ids']['audit_logs'] = 500;
        Storage::disk('backups')->put('ag-backup-20261117-013000.zip.manifest.json', json_encode($m));
        $this->assertLessThan(500, (int) DB::table('audit_logs')->max('id'));

        $this->freeze('2026-11-18 01:30:00');
        [$code, $out] = $this->backupRun();
        $this->assertSame(1, $code);
        $c = Backup::where('filename', self::FIRST)->sole();
        $this->assertSame('failed', $c->status);
        $this->assertSame('continuity', $c->failed_stage);
        $this->assertStringContainsString('audit_logs max id', $c->error);
        Storage::disk('backups')->assertExists(self::FIRST); // the state is preserved
        $row = AuditLog::where('event', 'backup.continuity_failed')->sole();
        $this->assertSame('ag-backup-20261117-013000.zip', $row->new_values['newer_backup']);
        $this->assertSame(500, $row->new_values['backup_max_id']);
        Notification::assertSentTo([$this->sa, $this->sa2], BackupAlertNotification::class, fn ($n) => $n->stage === 'continuity');
        Notification::assertNotSentTo($this->sa3, BackupAlertNotification::class);

        // The next night still compares with B (a continuity-failed run is not a new baseline).
        $this->freeze('2026-11-19 01:30:00');
        $this->assertSame(1, $this->backupRun()[0]);
        $this->assertSame('continuity', Backup::where('filename', 'ag-backup-20261119-013000.zip')->value('failed_stage'));

        // Declaring the restore of A clears it.
        $this->freeze('2026-11-19 09:00:00');
        $this->assertSame(0, $this->restored($a, 'restored A after disk failure')[0]);
        $this->freeze('2026-11-20 01:30:00');
        [$code, $out] = $this->backupRun();
        $this->assertSame(0, $code, $out);
        $this->assertSame('succeeded', Backup::where('filename', 'ag-backup-20261120-013000.zip')->value('status'));
    }

    public function test_continuity_reads_the_off_server_manifest_when_none_is_local(): void
    {
        config(['backup.offsite_disk' => 'backups_offsite']);
        Storage::disk('backups_offsite')->put('daily/ag-backup-20261117-013000.zip.manifest.json', json_encode([
            'backup' => 'ag-backup-20261117-013000.zip', 'created_at' => '2026-11-16T20:00:00+00:00',
            'max_ids' => ['audit_logs' => 900, 'ledger_entries' => 1],
        ]));
        $this->backupRun();
        $this->assertSame('continuity', Backup::sole()->failed_stage);
    }
}
