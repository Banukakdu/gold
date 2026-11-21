<?php

namespace App\Notifications;

use App\Models\ReportScheduleDelivery;
use App\Notifications\Channels\ScheduledReportChannel;
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
 * It goes through ScheduledReportChannel, which re-checks everything when the queued job runs (R5 "At send", R8) and
 * claims the delivery (`queued -> sending`) before the mailer is called, so a retry never sends it twice (review S1).
 * The last failed try marks it `failed` (failed()).
 */
class ScheduledReportMail extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [60, 300, 900];

    /** The verified PDF bytes for this try (set by the channel in the job; null whenever the notification is queued). */
    public ?string $pdf = null;

    public function __construct(public int $deliveryId, public bool $provisional = false)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return [ScheduledReportChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(ScheduledReportRuns::class)->mail($this->deliveryId, $this->provisional, $this->pdf);
    }

    /** R9: the last try failed (the claim was released each time, so the delivery is `queued` again). */
    public function failed(Throwable $e): void
    {
        app(ScheduledReportRuns::class)->deliveryFailed($this->deliveryId, 'mail_error', [ReportScheduleDelivery::QUEUED]);
    }
}
