<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-002: the "Reset password" link (password broker token, 60 minutes, single use). Spec R14: the link is built only
 * from config('app.frontend_url'), never from the request's Host header.
 */
class PasswordResetLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(object $notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->greeting("Hello {$notifiable->name},")
            ->line('We received a request to reset the password of your account.')
            ->action('Reset password', $this->url($notifiable))
            ->line("This link expires in {$minutes} minutes and can be used once. If you did not ask for this, ignore this email; your password stays the same.");
    }
}
