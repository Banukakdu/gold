<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Services\TransferApprovals;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AG-051 R11: "Transfer <ref> approved | rejected | expired", to the requester. Queued. */
class TransferApprovalDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $transactionId, public string $state)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Review m5: the comment is typed by a user; Markdown and HTML in it are shown as text, never rendered. */
    public static function plain(string $text): string
    {
        return addcslashes(e($text, false), '\\`*_{}[]()#+-.!|~>');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = Transaction::withoutGlobalScopes()->with(['fromAccount.branch:id,code', 'toAccount.branch:id,code', 'decidedBy:id,name'])->findOrFail($this->transactionId);
        $side = fn ($a) => $a ? trim(($a->branch?->code ?? '').' '.$a->name) : '';
        $mail = (new MailMessage)
            ->subject("Transfer {$t->ref_no} {$this->state}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your transfer request {$t->ref_no} was ".match ($this->state) {
                TransferApprovals::APPROVED => 'approved and posted.',
                TransferApprovals::REJECTED => 'rejected.',
                default => 'not decided within '.TransferApprovals::EXPIRY_DAYS.' days and has expired. Request it again if it is still needed.',
            })
            ->line("From {$side($t->fromAccount)} to {$side($t->toAccount)}, ".Money::format($t->amount).'.')
            ->line('Requested for '.$t->requested_date?->format('Y-m-d').'.');
        if ($this->state !== TransferApprovals::EXPIRED) {
            $mail->line('Decided by '.($t->decidedBy?->name ?? 'another user').'.');
        }
        if ($this->state === TransferApprovals::APPROVED) {
            $mail->line('Posting date: '.$t->txn_date->format('Y-m-d').'.');
        }
        if ($t->approval_note) {
            $mail->line('Comment: '.self::plain($t->approval_note));
        }

        return $mail->action('Open the transfer', rtrim((string) config('app.frontend_url', config('app.url')), '/')."/transactions/{$t->id}");
    }
}
