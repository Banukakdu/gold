<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-093 review F8: the hash of a year-end snapshot, held by the business outside the server. The accountant files it
 * with the year-end pack. Sent with sendNow.
 */
class YearEndSnapshotNotification extends Notification
{
    public function __construct(public string $label, public string $filename, public string $sha256, public string $total) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return BackupAlertNotification::mail("Account-gold year-end snapshot {$this->label} taken", [
            "Year-end snapshot {$this->label} taken: {$this->filename}",
            "SHA-256 {$this->sha256}",
            "Debits = credits = {$this->total}",
            'File this email with the year-end pack. An archive that no longer matches this hash has been changed.',
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return ['label' => $this->label, 'filename' => $this->filename, 'sha256' => $this->sha256, 'total' => $this->total];
    }
}
