<?php

namespace Tests\Feature\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** Section 7 "Alerts and audit" (AC 24-26). */
class AlertsAuditTest extends BackupsTestCase
{
    public function test_ac24_alerts_are_sent_now_to_active_super_admins_only(): void
    {
        Queue::fake();
        config(['backup.password' => '']);
        $this->app['env'] = 'production';
        $this->backupRun();
        $this->app['env'] = 'testing';
        Notification::assertSentTo([$this->sa, $this->sa2], BackupAlertNotification::class,
            fn ($n) => $n->subject() === 'Account-gold backup FAILED: precheck' && $n->filename === self::FIRST);
        Notification::assertNotSentTo([$this->sa3, $this->ba, $this->acc], BackupAlertNotification::class);
        $this->assertNotContains(ShouldQueue::class, class_implements(BackupAlertNotification::class));
        Queue::assertNothingPushed();
    }

    public function test_ac25_mail_failure_still_records_and_exits_1(): void
    {
        Exceptions::fake();
        $boom = new class($this->app) extends ChannelManager
        {
            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new RuntimeException('SMTP down');
            }
        };
        Notification::swap($boom);
        config(['backup.password' => '']);
        $this->app['env'] = 'production';
        [$code, $out] = $this->backupRun();
        $this->app['env'] = 'testing';
        $this->assertSame(1, $code);
        $this->assertSame('failed', Backup::sole()->status);
        $this->assertSame(1, AuditLog::where('event', 'backup.failed')->count());
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'SMTP down');
        $this->assertStringContainsString('could not be sent', $out);
    }

    public function test_ac26_audit_log_group_backups(): void
    {
        $this->backupRun();
        $this->artisan_('backup:verify', ['--latest' => true]);
        $rows = $this->sa()->getJson('/api/v1/audit-logs?'.http_build_query(['from' => '2026-11-18', 'to' => '2026-11-18', 'event_group' => 'backups']))
            ->assertOk()->json('data');
        $byEvent = collect($rows)->keyBy('event');
        $this->assertSame(['backup.created', 'backup.verified'], $byEvent->keys()->sort()->values()->all());
        $this->assertSame('Backup created', $byEvent['backup.created']['event_label']);
        $this->assertSame('Restore test passed', $byEvent['backup.verified']['event_label']);
        $this->assertNull($byEvent['backup.created']['user']); // shown as "System"
        $this->assertNull($byEvent['backup.created']['branch']);
        $this->assertSame(self::FIRST, $byEvent['backup.created']['subject']);

        // A branch user with audit.view sees none of them.
        $ba = $this->flushHeaders()->actingAs($this->ba)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/audit-logs?'.http_build_query(['from' => '2026-11-18', 'to' => '2026-11-18', 'event_group' => 'backups']));
        $this->assertSame([], $ba->assertOk()->json('data'));
    }
}
