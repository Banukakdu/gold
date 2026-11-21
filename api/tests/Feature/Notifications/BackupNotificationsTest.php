<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\BackupAlertNotification;
use App\Notifications\BackupRestoredNotification;
use App\Notifications\InAppNotification as N;
use App\Services\Backups\BackupAlerts;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Backups\BackupsTestCase;

/**
 * docs/specs/notifications.md AC 12 (R1, R4): a backup failure also lands in each active Super Admin's bell, after the
 * unchanged email, and never when the database is down. Two active Super Admins (sa, sa2) and an inactive one (sa3).
 */
class BackupNotificationsTest extends BackupsTestCase
{
    public function test_ac12_failure_emails_as_before_and_one_row_per_active_super_admin(): void
    {
        $sent = BackupAlerts::send(new BackupAlertNotification('backup', 'upload', 'ag-backup-20261118-013000.zip', '2026-11-18 01:31',
            'SFTP refused: password for backup@10.0.0.5 is wrong'));
        $this->assertTrue($sent);
        Notification::assertSentTo([$this->sa, $this->sa2], BackupAlertNotification::class);
        Notification::assertNotSentTo($this->sa3, BackupAlertNotification::class);
        Notification::assertSentOnDemandTimes(BackupAlertNotification::class, 0);

        foreach ([$this->sa, $this->sa2] as $u) {
            $n = DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $u->id)->sole();
            $this->assertSame(N::BACKUP_FAILED, $n->type);
            $this->assertSame('/backups', $n->data['url']);
            $this->assertSame('Account-gold backup FAILED: upload', $n->data['title']);
            $this->assertSame('Stage: upload, 2026-11-18 01:31', $n->data['body']);
            $this->assertNull($n->data['branch_id']);
            $this->assertStringNotContainsString('SFTP', json_encode($n->data));
            $this->assertStringNotContainsString('password', json_encode($n->data));
        }
        $this->assertSame(2, DatabaseNotification::count());
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $this->sa3->id)->count());

        // A restore-test failure too; other backup emails (restored, year-end snapshot) stay email only (Q4).
        BackupAlerts::send(new BackupAlertNotification('verify', 'restore', 'x.zip', '2026-11-18 03:00', 'checksum'));
        $this->assertSame('Account-gold restore test FAILED', DatabaseNotification::where('notifiable_id', $this->sa->id)
            ->orderByDesc('created_at')->get()->firstWhere('data.body', 'Stage: restore, 2026-11-18 03:00')?->data['title']);
        BackupAlerts::send(new BackupRestoredNotification('x.zip', '2026-11-18 01:30', 'Disk failure on the server', 'ops'));
        $this->assertSame(4, DatabaseNotification::count());
    }

    public function test_ac12_a_failing_in_app_write_does_not_make_the_mail_look_failed(): void
    {
        config(['backup.alert_emails' => ['ops@example.test']]);
        DB::statement('DROP TABLE notifications');
        $this->assertTrue(BackupAlerts::send(new BackupAlertNotification('backup', 'upload', null, '2026-11-18 01:31', 'x')));
        Notification::assertSentTo($this->sa, BackupAlertNotification::class);
        Notification::assertSentOnDemandTimes(BackupAlertNotification::class, 0);
    }

    public function test_ac12_database_unreachable_sends_the_fallback_once_and_nothing_escapes(): void
    {
        config(['backup.alert_emails' => ['ops@example.test'], 'database.connections.sqlite.database' => "{$this->work}/missing/live.sqlite"]);
        DB::purge('sqlite');
        [$code, $out] = $this->backupRun();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAILED', $out);
        Notification::assertSentOnDemandTimes(BackupAlertNotification::class, 1);
        Notification::assertSentOnDemand(BackupAlertNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === ['ops@example.test']);
    }
}
