<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AG-020: invitation link to set a password (password broker token, 60 minutes). */
class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(object $notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/set-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('You are invited to '.config('app.name'))
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you. Set your password to start.')
            ->action('Set password', $this->url($notifiable))
            ->line("This link expires in {$minutes} minutes. If it has expired, ask your administrator to resend the invitation.");
    }
}
