<?php

namespace App\Notifications;

use App\Models\Export;
use App\Services\BackgroundExports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AG-087 S1 "Your export could not be prepared": the export, the user-safe reason, no figures. */
class ExportFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return (new MailMessage)
            ->subject('Your export could not be prepared')
            ->greeting("Hello {$notifiable->name},")
            ->line(BackgroundExports::summaryLine($this->export))
            ->line((string) $this->export->error)
            ->line('Narrow the filters and run it again.')
            ->action('Open Exports', BackgroundExports::pageUrl($this->export));
    }
}
