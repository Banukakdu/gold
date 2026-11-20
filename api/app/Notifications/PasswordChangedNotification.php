<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Spec R13a: tells the user their password was changed. It holds no link and no token. */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $when  date time in the business timezone; $ip null means the server console (N6) */
    public function __construct(public string $when, public ?string $ip)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function text(): string
    {
        $from = $this->ip === null ? 'from the server console' : "from IP {$this->ip}";

        return 'Your '.config('app.name')." password was changed on {$this->when} {$from}. You have been signed out on all devices. "
            .'If this was not you, contact your administrator at once.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' password was changed')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->text());
    }
}
