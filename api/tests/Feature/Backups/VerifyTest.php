<?php

namespace Tests\Feature\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Section 7 "Restore test" (AC 27-31), AC 43 (control), AC 47 (attachment sha256) and F3a/AC 48 (oldest off-server). */
class VerifyTest extends BackupsTestCase
{
    private function liveCounts(): array
    {
        return collect(['transactions', 'ledger_entries', 'accounts', 'attachments', 'audit_logs', 'users', 'branches', 'backups'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function verify(array $params = ['--latest' => true]): array
    {
        return $this->artisan_('backup:verify', $params);
    }

    public function test_ac27_latest_passes(): void
    {
        $this->assertSame(0, $this->backupRun()[0]);
        $before = $this->liveCounts();
        $this->freeze('2026-11-22 03:00:00');
        [$code, $out] = $this->verify();
        $this->assertSame(0, $code, $out);
        $b = Backup::sole();
        $this->assertSame('passed', $b->verify_status);
        $this->assertTrue($b->verified_at->equalTo(now()));
        $this->assertNull($b->verify_error);
        $a = AuditLog::where('event', 'backup.verified')->sole();
        $this->assertSame(self::FIRST, $a->new_values['filename']);
        $this->assertSame(3, $a->new_values['counts']['attachments']);
        $this->assertSame('artisan backup:verify', $a->user_agent);
        $after = $this->liveCounts();
        $after['audit_logs']--; // the backup.verified row
        $this->assertSame($before, $after);
        $this->assertSame([], glob("{$this->work}/temp/*"));
        $this->assertFileDoesNotExist("{$this->work}/verify.sqlite", 'verify tables are dropped');
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame("{$this->work}/attachments", config('filesystems.disks.attachments.root'));
        Notification::assertNothingSent();
    }

    public function test_ac28_changed_archive_fails_on_sha256(): void
    {
        $this->backupRun();
        $path = $this->localPath();
        $bytes = file_get_contents($path);
        $bytes[100] = chr(ord($bytes[100]) ^ 1);
        file_put_contents($path, $bytes);
        [$code, $out] = $this->verify(['filename' => self::FIRST]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('sha256 mismatch', $out);
        $b = Backup::sole();
        $this->assertSame('failed', $b->verify_status);
        $this->assertStringContainsString('sha256 mismatch', $b->verify_error);
        $this->assertSame('checksum', AuditLog::where('event', 'backup.verify_failed')->sole()->new_values['stage']);
        Notification::assertSentTo([$this->sa, $this->sa2], BackupAlertNotification::class, fn ($n) => $n->subject() === 'Account-gold restore test FAILED');
        Notification::assertNotSentTo($this->sa3, BackupAlertNotification::class);
    }

    public function test_ac29_cached_balance_difference_fails_ledger_verify(): void
    {
        DB::table('accounts')->where('is_main_account', true)->update(['current_balance' => '1.00']);
        $this->backupRun();
        DB::table('accounts')->where('is_main_account', true)->update(['current_balance' => '12500.50']);
        [$code] = $this->verify();
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('failed', $b->verify_status);
        $this->assertStringContainsString('ledger:verify failed', $b->verify_error);
        $this->assertStringContainsString('current_balance', $b->verify_error);
        $this->assertSame('ledger', AuditLog::where('event', 'backup.verify_failed')->sole()->new_values['stage']);
    }

    public function test_ac30_refuses_the_live_database(): void
    {
        $this->backupRun();
        config(['database.connections.backup_verify.database' => "{$this->work}/live.sqlite"]);
        [$code, $out] = $this->verify();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The verify database must not be the live database.', $out);
        $this->assertTrue(Schema::hasTable('ledger_entries'));
        $this->assertSame(1, Backup::count());
        $this->assertSame('safety', AuditLog::where('event', 'backup.verify_failed')->sole()->new_values['stage']);
    }

    public function test_ac31_pruned_locally_reads_the_off_server_copy(): void
    {
        config(['backup.offsite_disk' => 'backups_offsite']);
        $this->backupRun();
        Storage::disk('backups')->delete([self::FIRST, self::FIRST.'.sha256']);
        Backup::sole()->update(['local_pruned_at' => now()]);
        [$code, $out] = $this->verify(['filename' => self::FIRST]);
        $this->assertSame(0, $code, $out);
        $this->assertSame('passed', Backup::sole()->verify_status);
    }

    public function test_ac43_changed_ledger_line_fails_at_control_naming_the_account(): void
    {
        $this->backupRun();
        $line = DB::table('ledger_entries')->where('debit', '>', 0)->orderBy('id')->first();
        $code = DB::table('accounts')->where('id', $line->account_id)->value('code');
        $this->tamper(self::FIRST, function (string $dir) use ($line) {
            $pdo = self::fixtureDb("{$dir}/db.sqlite", 'ledger_entries');
            $pdo->exec("UPDATE ledger_entries SET debit = '1.00' WHERE id = {$line->id}");
            $pdo = null;
            $m = json_decode(file_get_contents("{$dir}/manifest.json"), true);
            $m['db_sha256'] = hash_file('sha256', "{$dir}/db.sqlite");
            file_put_contents("{$dir}/manifest.json", json_encode($m));
        });
        [$exit, $out] = $this->verify();
        $this->assertSame(1, $exit, $out);
        $b = Backup::sole();
        $this->assertSame('control', AuditLog::where('event', 'backup.verify_failed')->sole()->new_values['stage']);
        $this->assertStringContainsString("Account #{$line->account_id} {$code}", $b->verify_error);
    }

    public function test_ac47_changed_attachment_file_fails_attachments_verify(): void
    {
        $this->backupRun();
        $this->tamper(self::FIRST, function (string $dir) {
            $f = "{$dir}/files/attachments/1/2026/11/a-two.pdf";
            file_put_contents($f, strtoupper(file_get_contents($f)));
        });
        [$exit] = $this->verify();
        $this->assertSame(1, $exit);
        $b = Backup::sole();
        $this->assertSame('attachments', AuditLog::where('event', 'backup.verify_failed')->sole()->new_values['stage']);
        $this->assertStringContainsString('sha256 mismatch', $b->verify_error);
    }

    public function test_old_password_still_opens(): void
    {
        $this->backupRun();
        config(['backup.password' => 'a-new-password-for-2027-xx', 'backup.old_passwords' => ['another-old-one-000000000', self::PASSWORD]]);
        [$code, $out] = $this->verify();
        $this->assertSame(0, $code, $out);
        config(['backup.old_passwords' => []]);
        $this->assertSame(1, $this->verify()[0]);
        $this->assertSame('extract', AuditLog::where('event', 'backup.verify_failed')->sole()->new_values['stage']);
    }

    public function test_ac48_oldest_offsite_yearly_object_without_a_row(): void
    {
        config(['backup.offsite_disk' => 'backups_offsite']);
        $this->backupRun();
        $off = Storage::disk('backups_offsite');
        // Only a yearly/ object (and its sidecar) of an archive this database has no row for.
        $name = 'ag-backup-20250411-013000.zip';
        $off->put("yearly/FY2024-25/{$name}", Storage::disk('backups')->get(self::FIRST));
        $off->put("yearly/FY2024-25/{$name}.sha256", Backup::sole()->sha256."  {$name}\n");
        [$code, $out] = $this->verify(['--oldest-offsite' => true]);
        $this->assertSame(0, $code, $out);
        $a = AuditLog::where('event', 'backup.verified')->sole();
        $this->assertSame($name, $a->new_values['filename']);
        $this->assertNull($a->auditable_id);
    }
}
