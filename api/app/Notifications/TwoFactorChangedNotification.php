<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-005 R12: tells the user about a change to their two-factor sign-in. Email only (notifications spec Q4). It holds
 * no secret, no code and no link with a token: only what happened, when (Asia/Colombo) and from which IP.
 */
class TwoFactorChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KINDS = ['enabled', 'moved', 'recovery_regenerated', 'recovery_used', 'disabled', 'reset', 'locked'];

    /**
     * @param  string  $when  date time in the business timezone
     * @param  ?string  $ip  null = the server console
     * @param  array{remaining?: int, context?: string, by?: ?string, level?: string}  $details
     */
    public function __construct(public string $kind, public string $when, public ?string $ip, public array $details = [])
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function subject(): string
    {
        return match ($this->kind) {
            'enabled' => 'Two-factor sign-in turned on',
            'moved' => 'Two-factor sign-in moved to a new phone',
            'recovery_regenerated' => 'New recovery codes created',
            'recovery_used' => 'A recovery code was used',
            'disabled' => 'Two-factor sign-in turned off',
            'reset' => 'Your two-factor sign-in was reset',
            'locked' => 'Someone entered your password but not the code',
        };
    }

    public function text(): string
    {
        $left = (int) ($this->details['remaining'] ?? 0);
        $what = match ($this->kind) {
            'enabled' => 'Two-factor sign-in was turned on for your account.',
            'moved' => 'Two-factor sign-in was moved to a new phone. Codes from the old app no longer work.',
            'recovery_regenerated' => 'New recovery codes were created. Your old codes no longer work.',
            'recovery_used' => match ($this->details['context'] ?? 'sign_in') {
                'move' => "A recovery code was used to move two-factor sign-in to a new phone. {$left} left.",
                'disable' => 'A recovery code was used to turn off two-factor sign-in.',
                default => "A recovery code was used to sign in. {$left} left.",
            },
            'disabled' => 'Two-factor sign-in was turned off for your account. Your recovery codes no longer work.',
            'reset' => 'Your two-factor sign-in was reset '.(($this->details['by'] ?? null) === null ? 'by the server console' : 'by '.$this->details['by'])
                .'. You have been signed out on all devices. Your recovery codes no longer work.',
            'locked' => 'Someone entered your password but not the code. If this was not you, reset your password now.'
                .(($this->details['level'] ?? 'short') === 'extended' ? ' Two-factor sign-in is locked for 24 hours.' : ' Two-factor sign-in is locked for 15 minutes.'),
        };
        $from = $this->ip === null ? 'from the server console' : "from IP {$this->ip}";

        return "{$what} Time: {$this->when}, {$from}. If this was not you, contact your administrator at once.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->text());
    }
}
