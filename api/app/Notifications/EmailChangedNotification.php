<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Password reset spec E4 / accounting review finding 5: sent on demand to the OLD address when an admin changes it. */
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $newEmail, public string $admin)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function text(): string
    {
        return 'Your '.config('app.name')." sign-in email was changed to {$this->newEmail} by {$this->admin}. "
            .'If you did not expect this, contact your administrator at once.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' sign-in email was changed')
            ->line($this->text());
    }
}
