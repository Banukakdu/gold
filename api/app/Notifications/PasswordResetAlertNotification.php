<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Password reset spec R13b/R13c: alert to the other active Super Admins. No link. */
class PasswordResetAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $kind  'reset' (R13b) or 'refused' (R13c) */
    public function __construct(public string $kind, public string $target, public ?string $via = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function subject(): string
    {
        return $this->kind === 'refused'
            ? "Password reset requested for Super Admin {$this->target}, refused"
            : "Password reset: {$this->target}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject());
        if ($this->kind === 'refused') {
            return $mail->line("Someone asked for a password reset link for the Super Admin {$this->target}.")
                ->line('Email reset is off for Super Admins, so no link was sent. If this was not the account owner, the account may be targeted.')
                ->line('See the audit log (Logins).');
        }

        return $mail->line("The password of {$this->target} was reset".($this->via === 'console' ? ' with the server console command.' : ' by email.'))
            ->line('All of their sessions were signed out. If this was not expected, check the audit log (Logins) at once.');
    }
}
