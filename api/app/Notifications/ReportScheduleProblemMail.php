<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-088 S5: the owner's (or, for a system pause, every active Super Admin's) email about a failed run, missed runs
 * or a system pause. The same text as the bell row and a link to the schedule's history (S3). No figures.
 */
class ReportScheduleProblemMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $scheduleId, public string $title, public ?string $body = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Best effort, and only to a user who is still active (R9).
        $user = method_exists($notifiable, 'fresh') ? $notifiable->fresh() : $notifiable;

        return $user !== null && ($user->status ?? 'active') === 'active';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->greeting("Hello {$notifiable->name},")->line($this->title.'.');
        if ($this->body) {
            $mail->line($this->body);
        }

        return $mail->action('Open the schedule', rtrim((string) config('app.frontend_url', config('app.url')), '/')."/scheduled-reports/{$this->scheduleId}");
    }
}
