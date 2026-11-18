<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-093 R8: a backup, off-server copy, prune or restore test failed. Sent with sendNow, never queued: the queue is
 * the database, and a backup failure is often a database or disk problem.
 */
class BackupAlertNotification extends Notification
{
    public function __construct(
        public string $kind, // backup | verify
        public string $stage,
        public ?string $filename,
        public string $at,
        public string $error,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function subject(): string
    {
        return $this->kind === 'verify' ? 'Account-gold restore test FAILED' : "Account-gold backup FAILED: {$this->stage}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line('Backup: '.YearEndNotification::plain($this->filename ?? '-'))
            ->line("Time: {$this->at} (Asia/Colombo)")
            ->line('Stage: '.YearEndNotification::plain($this->stage))
            ->line('Error: '.YearEndNotification::plain(mb_substr($this->error, 0, 500)))
            ->line('See Backups in Account-gold or the server log.');
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'stage' => $this->stage, 'filename' => $this->filename, 'at' => $this->at, 'error' => $this->error];
    }
}
