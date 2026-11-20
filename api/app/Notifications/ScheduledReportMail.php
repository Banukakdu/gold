<?php

namespace App\Notifications;

use App\Services\ScheduledReportRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * AG-088 S4: one scheduled report to one recipient, queued on the normal queue (like every other email). It is sent
 * on demand to `deliveries.email`, the address checked at build, never to an address read later (accounting review A2).
 *
 * shouldSend() re-checks everything when the queued job runs (R5 "At send", R8): the delivery is still queued, the
 * schedule still active, the user can still receive and still has that address, and the attached file is still the
 * one built (sha256). Otherwise the delivery is skipped or failed (with its audit row) and nothing is sent. The
 * delivery becomes `sent` when the mailer returns (NotificationSent, AppServiceProvider); the last failed try marks it
 * `failed` (failed()).
 */
class ScheduledReportMail extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [60, 300, 900];

    /** The verified PDF bytes for this try (set by shouldSend() in the job; null whenever the notification is queued). */
    private ?string $pdf = null;

    public function __construct(public int $deliveryId, public bool $provisional = false)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ok = app(ScheduledReportRuns::class)->prepareSend($this->deliveryId);
        $this->pdf = $ok['pdf'] ?? null;

        return $ok !== null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(ScheduledReportRuns::class)->mail($this->deliveryId, $this->provisional, $this->pdf);
    }

    /** R9: the last try failed. */
    public function failed(Throwable $e): void
    {
        app(ScheduledReportRuns::class)->deliveryFailed($this->deliveryId, 'mail_error');
    }
}
