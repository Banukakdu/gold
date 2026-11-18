<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Accounting review finding 9 / AC 48: tell Super Admins when a non-Super Admin changes access. */
class AccessChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $event,
        public string $actor,
        public string $branch,
        public string $target,
        public ?array $old,
        public ?array $new,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Access change in {$this->branch}: {$this->event}")
            ->line("{$this->actor} made an access change in {$this->branch}.")
            ->line("Event: {$this->event}")
            ->line("Target: {$this->target}")
            ->line('Before: '.self::describe($this->old))
            ->line('After: '.self::describe($this->new));
    }

    public static function describe(?array $v): string
    {
        if ($v === null) {
            return '(none)';
        }

        return collect($v)->map(fn ($x, $k) => $k.': '.(is_array($x) ? implode(', ', $x) : (string) $x))->implode('; ');
    }
}
