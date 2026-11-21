<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Services\InAppNotifications;
use App\Services\TransferApprovals;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-094 R7: "Transfer <ref> needs your approval", to an approver whose approval_request_email is on. Queued, mail
 * only. The link opens the recipient's own in-app row (/notifications/<uuid>, S3), so it works whatever branch is
 * active.
 *
 * Accounting review 3: amount, branch codes, account names, requester and dates only. No balance or "available"
 * figure, no bank account number and no free text (note, comment): an email cannot be withdrawn once sent.
 * Accounting review 4: when the queued mail is sent the recipient is checked again (still active, still an approver,
 * email still on) and the request must still be pending; otherwise it is dropped.
 */
class TransferApprovalRequestedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $transactionId, public string $notificationId)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Evaluated when the queued job runs (and by the notification fake when it is sent). Accounting review 4: still
     * active and still an approver. Also dropped when the request was decided meanwhile or the recipient has turned the
     * email off since it was queued.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $user = $notifiable->fresh();
        if ($user === null || ! ($user->approval_request_email ?? true)) {
            return false;
        }
        $state = Transaction::withoutGlobalScopes()->whereKey($this->transactionId)->value('approval_status');

        return $state === TransferApprovals::PENDING && app(InAppNotifications::class)->isTransferApprover($user);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = Transaction::withoutGlobalScopes()->with(['fromAccount.branch:id,code', 'toAccount.branch:id,code', 'creator:id,name'])
            ->findOrFail($this->transactionId);
        $side = fn ($a) => $a ? trim(($a->branch?->code ?? '').' '.$a->name) : '';
        $requestedOn = $t->requested_on?->format('Y-m-d');

        return (new MailMessage)
            ->subject("Transfer {$t->ref_no} needs your approval")
            ->greeting("Hello {$notifiable->name},")
            ->line("Transfer {$t->ref_no} needs your approval.")
            ->line("From {$side($t->fromAccount)} to {$side($t->toAccount)}.")
            ->line('Amount: '.Money::format($t->amount).'.')
            ->line('Requested by '.($t->creator?->name ?? 'another user').'.')
            ->line('Requested for '.$t->requested_date?->format('Y-m-d').'.')
            ->line('Expires on '.TransferApprovals::expiresOn($requestedOn).' if not decided.')
            ->action('Open the transfer', rtrim((string) config('app.frontend_url', config('app.url')), '/')."/notifications/{$this->notificationId}");
    }
}
