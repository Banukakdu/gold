<?php

namespace App\Notifications;

use App\Models\Export;
use App\Services\BackgroundExports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-087 S1 "Your export is ready". Links to the Exports page, never to the file; no figures, no attachment, and the
 * extraction time is not called "as at" (accounting review findings 1 and 10).
 */
class ExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KEEP_NOTE = 'Save the file if you need to keep it. A file run again later may differ if entries in an unlocked period were voided or changed.';

    public function __construct(public Export $export)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $e = $this->export;
        $expires = $e->expires_at?->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i');

        return (new MailMessage)
            ->subject('Your export is ready')
            ->greeting("Hello {$notifiable->name},")
            ->line(BackgroundExports::summaryLine($e))
            ->line("Download it from Exports before {$expires} (Asia/Colombo).")
            ->line(self::KEEP_NOTE)
            ->action('Open Exports', BackgroundExports::pageUrl($e));
    }
}
