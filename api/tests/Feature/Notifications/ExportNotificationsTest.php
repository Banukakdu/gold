<?php

namespace Tests\Feature\Notifications;

use App\Models\Export;
use App\Notifications\ExportFailedNotification;
use App\Notifications\ExportReadyNotification;
use App\Notifications\InAppNotification as N;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Exports\ExportsTestCase;

/** docs/specs/notifications.md AC 11 (R1 ExportFinished): the requester's row, independent of `exports.email`. */
class ExportNotificationsTest extends ExportsTestCase
{
    public function test_ac11_ready_export_gives_one_row_without_branch_even_with_email_off(): void
    {
        Notification::fake();
        config(['exports.email' => false]);
        $this->requestEight();
        $this->work();
        $e = $this->latest();
        $this->assertSame(Export::READY, $e->status);

        $n = DatabaseNotification::where('notifiable_id', $this->ak->id)->sole();
        $this->assertSame(N::EXPORT_READY, $n->type);
        $this->assertSame('Your export is ready', $n->data['title']);
        $expires = $e->expires_at->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i');
        $this->assertSame("Transactions (Excel), KDY, 1 Oct 2026 to 31 Oct 2026, 8 rows. Download before {$expires}.", $n->data['body']);
        $this->assertSame("/exports?id={$e->id}", $n->data['url']);
        $this->assertSame($e->id, $n->data['export_id']);
        $this->assertNull($n->data['branch_id']);
        Notification::assertNotSentTo($this->ak, ExportReadyNotification::class);

        $row = $this->actingAs($this->ak)->getJson('/api/v1/notifications')->assertOk()->json('data.0');
        $this->assertNull($row['branch']);
        $this->assertSame("/exports?id={$e->id}", $row['url']);
        $this->assertSame(1, DatabaseNotification::count());
    }

    public function test_ac11_failed_export_gives_an_export_failed_row_without_the_error_text(): void
    {
        Notification::fake();
        $this->requestEight();
        $this->branchRole($this->kdy, 'Accountant')->revokePermissionTo('report.export');
        $this->work();
        $e = $this->latest();
        $this->assertSame(Export::FAILED, $e->status);
        Notification::assertSentTo($this->ak, ExportFailedNotification::class);

        $n = DatabaseNotification::where('notifiable_id', $this->ak->id)->sole();
        $this->assertSame(N::EXPORT_FAILED, $n->type);
        $this->assertSame('Your export could not be prepared', $n->data['title']);
        $this->assertSame('Transactions (Excel), KDY, 1 Oct 2026 to 31 Oct 2026, 8 rows.', $n->data['body']);
        $this->assertStringNotContainsString((string) $e->error, json_encode($n->data));
        $this->assertSame("/exports?id={$e->id}", $n->data['url']);
    }
}
