<?php

namespace Tests\Feature\Exports;

use App\Events\ExportFinished;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Export;
use App\Notifications\ExportFailedNotification;
use App\Notifications\ExportReadyNotification;
use App\Support\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** docs/specs/background-exports.md ACs 24, 25, 30, 34 and 36 (S1 email, R11, R12, accounting review findings 7 and 11). */
class NotificationsAuditTest extends ExportsTestCase
{
    public function test_ac24_ready_email(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://gold.example']);
        $this->requestEight();
        $this->work();
        $e = $this->latest();
        Notification::assertSentToTimes($this->ak, ExportReadyNotification::class, 1);
        Notification::assertSentTo($this->ak, ExportReadyNotification::class, function (ExportReadyNotification $n) use ($e) {
            $mail = $n->toMail($this->ak);
            $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);
            $expires = $e->expires_at->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i');

            return $mail->subject === 'Your export is ready'
                && $mail->actionUrl === "https://gold.example/exports?id={$e->id}"
                && str_contains($text, 'Transactions (Excel), KDY, 1 Oct 2026 to 31 Oct 2026, 8 rows.')
                && str_contains($text, "Download it from Exports before {$expires} (Asia/Colombo).")
                && str_contains($text, ExportReadyNotification::KEEP_NOTE)
                // No amounts (the incomes are 100.00 .. 800.00), no "as at", no attachment.
                && ! preg_match('/\d\.\d\d/', $text) && ! str_contains($text, 'as at') && $mail->rawAttachments === [] && $mail->attachments === [];
        });
    }

    public function test_ac24_email_switched_off(): void
    {
        Notification::fake();
        config(['exports.email' => false]);
        $this->requestEight();
        $this->work();
        $this->assertSame(Export::READY, $this->latest()->status);
        Notification::assertNothingSent();
    }

    public function test_failed_email_and_no_email_to_an_inactive_user(): void
    {
        Notification::fake();
        $this->requestEight();
        $this->branchRole($this->kdy, 'Accountant')->revokePermissionTo('report.export');
        $this->work();
        Notification::assertSentTo($this->ak, ExportFailedNotification::class, function (ExportFailedNotification $n) {
            $mail = $n->toMail($this->ak);

            return $mail->subject === 'Your export could not be prepared'
                && in_array('Your access changed after this export was requested.', $mail->introLines, true)
                && in_array('Narrow the filters and run it again.', $mail->introLines, true);
        });

        $this->branchRole($this->kdy, 'Accountant')->givePermissionTo('report.export');
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'expense'])->assertStatus(202);
        $this->ak->update(['status' => 'inactive']);
        $this->work();
        $this->assertSame('access_revoked', $this->latest()->failure_code);
        Notification::assertSentToTimes($this->ak, ExportFailedNotification::class, 1);
    }

    public function test_ac25_export_finished_event(): void
    {
        $seen = [];
        Event::listen(ExportFinished::class, function (ExportFinished $e) use (&$seen) {
            $seen[] = [$e->export->id, $e->export->status];
        });
        $this->requestEight();
        $this->work();
        $ready = $this->latest();
        config(['exports.max_bytes' => 10]);
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'expense']);
        $this->work();
        $failed = $this->latest();
        $this->assertSame([[$ready->id, Export::READY], [$failed->id, Export::FAILED]], $seen);
    }

    public function test_ac30_ac34_audit_screen(): void
    {
        $this->requestEight();
        $this->work();
        $e = $this->latest();
        $this->download($this->ak, $e->id)->assertOk();
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'expense']);
        config(['exports.max_bytes' => 10]);
        $this->work();
        $this->travel(25)->hours();
        $this->artisan('exports:prune');

        $q = http_build_query(['event_group' => 'reports', 'from' => '2026-11-19', 'to' => BusinessDate::today()]);
        $rows = collect($this->asAdmin()->getJson("/api/v1/audit-logs?{$q}")->assertOk()->json('data'));
        $labels = $rows->pluck('event_label')->unique()->values()->all();
        foreach (['Export requested', 'Export ready', 'Export downloaded', 'Export failed', 'Export expired'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertTrue($rows->every(fn ($r) => $r['subject'] === 'Transactions'));
        // Finding 7: system events read "System"; the user's own ones do not.
        $by = $rows->keyBy('event');
        $this->assertTrue($by['export.ready']['system']);
        $this->assertSame($this->ak->id, $by['export.ready']['user']['id']);
        $this->assertTrue($by['export.failed']['system']);
        $this->assertTrue($by['export.expired']['system']);
        $this->assertNull($by['export.expired']['user']);
        $this->assertSame($this->ak->id, $by['export.expired']['new_values']['requested_by']);
        $this->assertFalse($by['export.requested']['system']);
        $this->assertFalse($by['export.downloaded']['system']);
        $this->assertSame([$this->kdy->id], $by['export.requested']['new_values']['branch_ids']);

        // A KDY user with audit.view sees KDY rows only; an All-mode export (branch null) is not among them.
        $this->asAdmin()->postJson('/api/v1/transactions/export', ['type' => 'income'])->assertStatus(202);
        $kdyRows = collect($this->actingAs($this->ak)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson("/api/v1/audit-logs?{$q}")->assertOk()->json('data'));
        $this->assertNotEmpty($kdyRows);
        $this->assertTrue($kdyRows->every(fn ($r) => ($r['branch']['id'] ?? null) === $this->kdy->id));
        $this->assertSame(1, AuditLog::where('event', 'export.requested')->whereNull('branch_id')->count());
    }

    public function test_ac36_rows_are_protected_by_the_database(): void
    {
        $this->requestEight();
        $this->work();
        $e = $this->latest();
        $refused = function (callable $sql, string $what) {
            try {
                $sql();
                $this->fail("{$what} was not refused");
            } catch (QueryException $ex) {
                $this->assertStringContainsString('exports rows are evidence', $ex->getMessage(), $what);
            }
        };
        $refused(fn () => DB::table('exports')->where('id', $e->id)->delete(), 'delete');
        $refused(fn () => DB::table('exports')->where('id', $e->id)->update(['params' => json_encode(['from' => '2026-01-01'])]), 'params');
        $refused(fn () => DB::table('exports')->where('id', $e->id)->update(['user_id' => $this->admin->id]), 'user_id');
        $refused(fn () => DB::table('exports')->where('id', $e->id)->update(['sha256' => str_repeat('0', 64)]), 'sha256');
        $refused(fn () => DB::table('exports')->where('id', $e->id)->update(['requested_at' => now()->subDay()]), 'requested_at');
        $this->expectException(ImmutableRecordException::class);
        // Status, file and times may change (prune and download do this).
        DB::table('exports')->where('id', $e->id)->update(['status' => 'expired', 'file_path' => null, 'download_count' => 3, 'updated_at' => now()]);
        $this->assertSame('expired', DB::table('exports')->where('id', $e->id)->value('status'));
        Storage::disk(Export::DISK)->delete($e->file_path);
        $e->delete();
    }
}
