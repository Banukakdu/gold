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
        return self::mail($this->subject(), [
            'Backup: '.($this->filename ?? '-'),
            "Time: {$this->at} (Asia/Colombo)",
            "Stage: {$this->stage}",
            'Error: '.mb_substr($this->error, 0, 500),
            'See Backups in Account-gold or the server log.',
        ]);
    }

    /**
     * QA B5: an HTML and a plain-text view (resources/views/mail/backup*.blade.php), not Markdown, so the text part
     * carries no escape backslashes. Shared by the backup emails.
     *
     * @param  string[]  $lines
     */
    public static function mail(string $subject, array $lines): MailMessage
    {
        $lines = array_map(fn ($l) => trim(preg_replace('/[\r\n]+/u', ' ', (string) $l) ?? ''), $lines);

        return (new MailMessage)->subject($subject)->view(['mail.backup', 'mail.backup-text'], ['subject' => $subject, 'lines' => $lines]);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'stage' => $this->stage, 'filename' => $this->filename, 'at' => $this->at, 'error' => $this->error];
    }
}
