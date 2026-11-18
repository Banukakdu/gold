<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-092 review finding 5 (settings spec R11b): every active Super Admin, the actor included, is told when a fiscal
 * year is closed or reopened. Separate from AccessAlerts, which skips Super Admin actors. Queued after commit.
 */
class YearEndNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $event, // closed | reopened
        public string $year,
        public string $actor,
        public string $at,
        public string $totalResult,
        public ?string $reason = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->year} was {$this->event}")
            ->line("{$this->actor} {$this->event} {$this->year} at {$this->at} (Asia/Colombo).")
            ->line("Total result: {$this->totalResult}");

        return $this->reason !== null ? $mail->line("Reason: {$this->reason}") : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event, 'year' => $this->year, 'actor' => $this->actor, 'at' => $this->at,
            'total_result' => $this->totalResult, 'reason' => $this->reason];
    }
}
