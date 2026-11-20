<?php

namespace Tests\Feature\Backups;

use App\Console\Commands\BackupVerify;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use App\Services\Backups\BackupAlerts;
use App\Services\Backups\BackupArchive;
use App\Services\Backups\BackupVerifier;
use App\Services\Backups\OffsiteStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

/** AG-093 QA and review fixes (M1-M5, B2, B5 and the minors). */
class ReviewFixesTest extends BackupsTestCase
{
    /** Copies the db.sqlite of an archive over the live database: a rollback nobody recorded. */
    private function rollBackTo(string $file): void
    {
        $dir = "{$this->work}/rollback";
        @mkdir($dir, 0700);
        $z = $this->openZip($this->localPath($file));
        $this->assertTrue($z->extractTo($dir, 'db.sqlite'));
        $z->close();
        DB::purge('sqlite');
        copy("{$dir}/db.sqlite", "{$this->work}/live.sqlite");
        DB::purge('sqlite');
    }

    private function failedRow(string $name, string $stage): Backup
    {
        foreach (['', '.sha256', '.manifest.json', '.audit.json'] as $s) {
            Storage::disk('backups')->put($name.$s, 'x');
        }

        return Backup::create(['filename' => $name, 'status' => 'failed', 'failed_stage' => $stage, 'error' => 'x', 'triggered_by' => 'scheduler',
            'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2), 'db_driver' => 'sqlite', 'encrypted' => true,
            'kept_as' => 'daily', 'size_bytes' => 1, 'sha256' => str_repeat('b', 64), 'files_count' => 0, 'offsite_status' => 'not_configured']);
    }

    public function test_m1_database_down_still_alerts_the_fallback_addresses(): void
    {
        config(['backup.alert_emails' => ['ops@example.test'], 'database.connections.sqlite.database' => "{$this->work}/missing/live.sqlite"]);
        DB::purge('sqlite');
        [$code, $out] = $this->backupRun();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAILED', $out);
        Notification::assertSentOnDemand(BackupAlertNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === ['ops@example.test'] && $n->stage === 'run');
    }

    public function test_m1_fallback_when_no_super_admin_can_be_reached_and_none_otherwise(): void
    {
        $this->assertTrue(BackupAlerts::send(new BackupAlertNotification('backup', 'dump', null, '2026-11-18 01:30', 'x')));
        Notification::assertSentTo($this->sa, BackupAlertNotification::class);
        Notification::assertSentOnDemandTimes(BackupAlertNotification::class, 0);
    }

    public function test_m2_stale_workspaces_are_swept_by_run_prune_and_verify(): void
    {
        foreach (['backup:run' => [], 'backup:prune' => [], 'backup:verify' => ['--latest' => true]] as $command => $params) {
            if ($command === 'backup:verify') {
                $this->backupRun();
            }
            $old = "{$this->work}/temp/".bin2hex(random_bytes(4));
            $new = "{$this->work}/temp/".bin2hex(random_bytes(4));
            mkdir($old, 0700);
            mkdir($new, 0700);
            file_put_contents("{$old}/db.sqlite", 'plain dump');
            touch($old, time() - 7 * 3600);
            [, $out] = $this->artisan_($command, $params);
            $this->assertDirectoryDoesNotExist($old, $command);
            $this->assertDirectoryExists($new, $command);
            if ($command !== 'backup:verify') { // its inner Artisan::call() replaces the captured output
                $this->assertStringContainsString('Removed a stale temp folder', $out, $command);
            }
            rmdir($new);
        }
    }

    public function test_m2_workspace_is_private(): void
    {
        $this->backupRun();
        $this->assertSame('700', substr(sprintf('%o', fileperms("{$this->work}/temp")), -3));
    }

    public function test_m3_store_failure_leaves_no_file_on_the_backups_disk(): void
    {
        $disk = Mockery::mock(Storage::disk('backups'))->makePartial();
        $disk->shouldReceive('move')->andThrow(new RuntimeException('No space left on device'));
        Storage::set('backups', $disk);
        [$code] = $this->backupRun();
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('store', $b->failed_stage);
        $this->assertStringContainsString('No space left on device', $b->error);
        $this->assertSame([], Storage::disk('backups')->files());
        $this->assertSame([], glob("{$this->work}/temp/*"));
    }

    public function test_m3_prune_removes_partial_files_and_failed_runs_and_keeps_evidence(): void
    {
        $disk = Storage::disk('backups');
        $disk->put('ag-backup-20261110-013000.zip.part', 'half');
        touch($disk->path('ag-backup-20261110-013000.zip.part'), time() - 7 * 3600);
        $disk->put('ag-backup-20261118-013000.zip.part', 'being written');
        $gone = $this->failedRow('ag-backup-20261115-013000.zip', 'offsite');
        $this->failedRow('ag-backup-20261116-013000.zip', 'continuity');
        $this->failedRow('ag-backup-20261114-013000.zip', 'interrupted');
        $disk->put('ag-backup-20261113-013000.zip', 'no row');

        [$code, $out] = $this->artisan_('backup:prune');
        $this->assertSame(0, $code, $out);
        $disk->assertMissing('ag-backup-20261110-013000.zip.part');
        $disk->assertExists('ag-backup-20261118-013000.zip.part');
        foreach (['', '.sha256', '.manifest.json', '.audit.json'] as $s) {
            $disk->assertMissing('ag-backup-20261115-013000.zip'.$s);
            $disk->assertExists('ag-backup-20261116-013000.zip'.$s);
            $disk->assertExists('ag-backup-20261114-013000.zip'.$s);
        }
        $disk->assertExists('ag-backup-20261113-013000.zip');
        $this->assertStringContainsString('ag-backup-20261113-013000.zip has no backup row; it is kept.', $out);
        $this->assertNotNull($gone->fresh()->local_pruned_at);
        $this->assertSame('failed_run', AuditLog::where('event', 'backup.pruned')->sole()->new_values['rule']);
    }

    public function test_m3_prune_also_removes_the_audit_sidecar(): void
    {
        $this->freeze('2026-09-01 01:30:00');
        $this->backupRun();
        $name = 'ag-backup-20260901-013000.zip';
        Storage::disk('backups')->assertExists("{$name}.audit.json");
        DB::table('backups')->update(['kept_as' => 'daily']);
        $this->freeze('2026-11-18 01:30:00');
        for ($i = 0; $i < 7; $i++) {
            Backup::create(['filename' => "ag-backup-2026111{$i}-013000.zip", 'status' => 'succeeded', 'triggered_by' => 'scheduler',
                'started_at' => now()->subDays(8 - $i), 'finished_at' => now()->subDays(8 - $i), 'db_driver' => 'sqlite', 'encrypted' => true,
                'kept_as' => 'daily', 'size_bytes' => 1, 'sha256' => str_repeat('c', 64), 'files_count' => 0, 'offsite_status' => 'not_configured']);
        }
        $this->artisan_('backup:prune');
        Storage::disk('backups')->assertMissing("{$name}.audit.json");
        Storage::disk('backups')->assertMissing($name);
    }

    public function test_m4_live_guard_compares_the_identity_the_servers_report(): void
    {
        $v = new class(app(BackupArchive::class), app(OffsiteStore::class)) extends BackupVerifier
        {
            public array $ids = [];

            protected function identity(string $connection): ?string
            {
                return $this->ids[$connection] ?? null;
            }
        };
        $v->ids = ['sqlite' => 'mysql:3f1c/account_gold', 'backup_verify' => 'mysql:3f1c/account_gold'];
        $this->assertTrue($v->sameDatabase('sqlite', 'backup_verify'), 'same server and schema under another host name');
        $v->ids['backup_verify'] = 'mysql:3f1c/account_gold_verify';
        $this->assertFalse($v->sameDatabase('sqlite', 'backup_verify'));
        $v->ids['backup_verify'] = null;
        $this->assertTrue($v->sameDatabase('sqlite', 'backup_verify'), 'cannot tell: refuse');

        $this->backupRun();
        $v->ids['backup_verify'] = 'mysql:3f1c/account_gold';
        $this->app->instance(BackupVerifier::class, $v);
        $users = DB::table('users')->count();
        [$code, $out] = $this->artisan_('backup:verify', ['--latest' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The verify database must not be the live database.', $out);
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_m4_sqlite_identity_is_the_real_path_even_for_a_missing_file(): void
    {
        $v = app(BackupVerifier::class);
        config(['database.connections.backup_verify.database' => "{$this->work}/temp/../live.sqlite"]);
        $this->assertTrue($v->sameDatabase('sqlite', 'backup_verify'));
        config(['database.connections.backup_verify.database' => "{$this->work}/temp/../verify-new.sqlite"]);
        $this->assertFalse($v->sameDatabase('sqlite', 'backup_verify'));
        config(['database.connections.sqlite.database' => "{$this->work}/verify-new.sqlite"]);
        $this->assertTrue($v->sameDatabase('sqlite', 'backup_verify'), 'both name the same missing file');
        config(['database.connections.backup_verify.database' => "{$this->work}/no-such-dir/x.sqlite"]);
        $this->assertTrue($v->sameDatabase('sqlite', 'backup_verify'), 'cannot tell: refuse');
        config(['database.connections.sqlite.database' => "{$this->work}/live.sqlite"]);
    }

    public function test_m5_a_second_verify_waits_and_gives_up_without_recording(): void
    {
        $this->backupRun();
        $lock = BackupAlerts::locks()->lock(BackupVerify::LOCK, 60);
        $this->assertTrue($lock->get());
        [$code, $out] = $this->artisan_('backup:verify', ['--latest' => true]);
        $lock->release();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Another backup:verify is still running', $out);
        $this->assertSame(0, AuditLog::whereIn('event', ['backup.verified', 'backup.verify_failed'])->count());
        $this->assertNull(Backup::sole()->verify_status);
        $this->assertSame(0, $this->artisan_('backup:verify', ['--latest' => true])[0]);
    }

    public function test_b2_the_rollback_alarm_stays_up_until_a_restore_is_recorded(): void
    {
        $this->freeze('2026-11-16 01:30:00');
        $this->assertSame(0, $this->backupRun()[0]);
        $a = 'ag-backup-20261116-013000.zip';
        $this->freeze('2026-11-17 01:30:00');
        $this->assertSame(0, $this->backupRun()[0]);
        $b = 'ag-backup-20261117-013000.zip';

        $this->rollBackTo($a);
        $this->freeze('2026-11-18 01:30:00');
        $this->assertSame(1, $this->backupRun()[0]);
        $this->assertSame('continuity', Backup::where('filename', self::FIRST)->value('failed_stage'));

        // A day of normal work pushes the ids past B's: the alarm must stay up.
        for ($i = 0; $i < 60; $i++) {
            AuditLog::record('settings.updated', null, null, ['n' => $i], null, ['ip' => null, 'user_agent' => 'test']);
        }
        $this->freeze('2026-11-19 01:30:00');
        [$code, $out] = $this->backupRun();
        $this->assertSame(1, $code, $out);
        $d = Backup::where('filename', 'ag-backup-20261119-013000.zip')->sole();
        $this->assertSame('continuity', $d->failed_stage);
        $this->assertStringContainsString('first raised by '.self::FIRST, $d->error);
        $summary = $this->sa()->getJson('/api/v1/backups')->assertOk()->json('summary.latest');
        $this->assertSame($b, $summary['continuity_backup']);

        $this->freeze('2026-11-19 09:00:00');
        $this->assertSame(0, $this->artisan_('backup:restored', ['filename' => $a, '--reason' => 'restored A after disk failure'])[0]);
        $this->freeze('2026-11-20 01:30:00');
        [$code, $out] = $this->backupRun();
        $this->assertSame(0, $code, $out);
    }

    public function test_b2_restoring_the_newest_backup_without_recording_it_is_detected(): void
    {
        $this->freeze('2026-11-17 01:30:00');
        $this->assertSame(0, $this->backupRun()[0]);
        $a = 'ag-backup-20261117-013000.zip';
        $this->rollBackTo($a);
        $this->freeze('2026-11-18 01:30:00');
        [$code, $out] = $this->backupRun();
        $this->assertSame(1, $code, $out);
        $c = Backup::where('filename', self::FIRST)->sole();
        $this->assertSame('continuity', $c->failed_stage);
        $this->assertStringContainsString("the audit row of backup {$a}", $c->error);
    }

    public function test_b5_plain_text_mail_has_no_markdown_escapes(): void
    {
        $n = new BackupAlertNotification('backup', 'precheck', 'ag-backup-20261118-013000.zip', '2026-11-18 01:30', 'BACKUP_ARCHIVE_PASSWORD is not set. <b>x</b>');
        $mail = $n->toMail($this->sa);
        $text = view($mail->view[1], $mail->viewData)->render();
        $html = view($mail->view[0], $mail->viewData)->render();
        $this->assertStringNotContainsString('\\', $text);
        $this->assertStringContainsString('Backup: ag-backup-20261118-013000.zip', $text);
        $this->assertStringContainsString('Error: BACKUP_ARCHIVE_PASSWORD is not set. <b>x</b>', $text);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertSame('Account-gold backup FAILED: precheck', $mail->subject);
    }

    public function test_the_archive_password_is_never_stored_or_printed(): void
    {
        $this->freeze('2026-11-18 01:30:00');
        [, $out] = $this->backupRun();
        [, $out2] = $this->artisan_('backup:verify', ['--latest' => true]);
        $haystacks = [$out, $out2, json_encode(DB::table('backups')->get()), json_encode(DB::table('audit_logs')->get())];
        foreach (Storage::disk('backups')->files() as $f) {
            if (! str_ends_with($f, '.zip')) {
                $haystacks[] = Storage::disk('backups')->get($f);
            }
        }
        $z = $this->openZip($this->localPath());
        $haystacks[] = $z->getFromName('manifest.json');
        foreach ($haystacks as $h) {
            $this->assertStringNotContainsString(self::PASSWORD, (string) $h);
        }
    }

    public function test_review_m7_manifest_db_entry_must_be_a_known_name(): void
    {
        $this->backupRun();
        $this->tamper(self::FIRST, function (string $dir) {
            $m = json_decode(file_get_contents("{$dir}/manifest.json"), true);
            $m['db_entry'] = '../../live.sqlite';
            file_put_contents("{$dir}/manifest.json", json_encode($m));
        });
        $this->assertSame(1, $this->artisan_('backup:verify', ['--latest' => true])[0]);
        $this->assertSame('extract', AuditLog::where('event', 'backup.verify_failed')->sole()->new_values['stage']);
        $this->assertStringContainsString('unexpected database entry', Backup::sole()->verify_error);
    }
}
