<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-005 R12: alert to the other active Super Admins (AccessAlerts::twoFactor). Email only. No secret, code or link.
 * Kinds: disabled (by an admin), reset, locked (admin, or extended for anyone), setting (turned on or off),
 * re_enrolled (the first set-up after a reset, with the IP).
 */
class TwoFactorAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $target  "name <email>" of the user concerned (empty for the setting)
     * @param  ?string  $actor  "name <email>" of who did it, null for the console or the user themselves
     * @param  array{reason?: string, ip?: ?string, level?: string, until?: string, enabled?: bool, when?: string}  $details
     */
    public function __construct(public string $kind, public string $target, public ?string $actor, public array $details = [])
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
            'disabled' => "Two-factor sign-in turned off: {$this->target}",
            'reset' => "Two-factor sign-in reset: {$this->target}",
            'locked' => "Two-factor sign-in locked: {$this->target}",
            'setting' => 'Two-factor sign-in for admins turned '.(($this->details['enabled'] ?? false) ? 'on' : 'off'),
            're_enrolled' => "Two-factor sign-in set up again after a reset: {$this->target}",
        };
    }

    public function text(): string
    {
        $ip = $this->details['ip'] ?? null;

        return match ($this->kind) {
            'disabled' => "{$this->target} turned off their two-factor sign-in".($ip ? " from IP {$ip}" : '').'.',
            'reset' => "The two-factor sign-in of {$this->target} was reset "
                .($this->actor === null ? 'with the server console command' : "by {$this->actor}")
                .'. Reason: '.($this->details['reason'] ?? '').'. All of their sessions were signed out.',
            'locked' => "A wrong password or code was entered for {$this->target}"
                .(($this->details['level'] ?? 'short') === 'extended' ? ' 30 times in 24 hours. Two-factor sign-in is locked for 24 hours' : ' 10 times in 15 minutes. Two-factor sign-in is locked for 15 minutes')
                .(isset($this->details['until']) ? ' (until '.$this->details['until'].')' : '').'.',
            'setting' => "{$this->actor} turned ".(($this->details['enabled'] ?? false) ? 'on' : 'off')
                .' "Require two-factor sign-in for admins".',
            're_enrolled' => "{$this->target} set up two-factor sign-in again after a reset, from IP ".($ip ?? 'unknown').'.',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->text())
            ->line('If this was not expected, check the audit log (Logins) at once.');
    }
}
