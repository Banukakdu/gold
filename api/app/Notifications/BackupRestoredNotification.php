<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AG-093 review F5: the database was restored from a backup (`backup:restored`). Sent with sendNow. */
class BackupRestoredNotification extends Notification
{
    public function __construct(public string $filename, public string $backupTime, public string $reason, public string $operator) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Account-gold database restored from a backup')
            ->line('Restored from: '.YearEndNotification::plain($this->filename))
            ->line("Backup time: {$this->backupTime} (Asia/Colombo). Entries and changes made after this time are not in the system.")
            ->line('Reason: '.YearEndNotification::plain($this->reason))
            ->line('Operator: '.YearEndNotification::plain($this->operator))
            ->line('Everyone has been signed out. Re-apply every user deactivation, role change, period lock and year-end close made '
                .'after the backup time, using the access-alert and year-end emails received since then, and record them in the restore note.');
    }

    public function toArray(object $notifiable): array
    {
        return ['filename' => $this->filename, 'backup_time' => $this->backupTime, 'reason' => $this->reason, 'operator' => $this->operator];
    }
}
