<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Services\ExpenseApprovals;
use App\Services\InAppNotifications;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AG-064 R11: "Expense <ref> needs your approval", to an approver whose approval_request_email is on (the same
 * preference as transfers). Queued, mail only. The link opens the recipient's own in-app row (/notifications/<uuid>).
 *
 * Content as AG-094 accounting review 3: branch code, account name, category path, amount, requester and dates only.
 * No balance or "available" figure, no payee, reference, description or file. When the queued mail is sent the
 * recipient is checked again (active, still an approver of that branch or a Super Admin, email still on; a request
 * that only a Super Admin may decide goes to Super Admins only) and the request must still be pending.
 */
class ExpenseApprovalRequestedMail extends Notification implements ShouldQueue
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

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $user = $notifiable->fresh();
        if ($user === null || ! ($user->approval_request_email ?? true)) {
            return false;
        }
        $t = Transaction::withoutGlobalScopes()->find($this->transactionId);
        if (! $t || $t->type !== Transaction::EXPENSE || $t->approval_status !== ExpenseApprovals::PENDING) {
            return false;
        }
        if (! app(InAppNotifications::class)->isExpenseApprover($user, (int) $t->branch_id)) {
            return false;
        }

        return $user->is_super_admin || ! app(ExpenseApprovals::class)->superAdminOnly($t);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = Transaction::withoutGlobalScopes()->with(['branch:id,code', 'account:id,name', 'category.parent', 'creator:id,name'])
            ->findOrFail($this->transactionId);
        $requestedOn = $t->requested_on?->format('Y-m-d');

        return (new MailMessage)
            ->subject("Expense {$t->ref_no} needs your approval")
            ->greeting("Hello {$notifiable->name},")
            ->line("Expense {$t->ref_no} needs your approval.")
            ->line("Branch {$t->branch?->code}, paid from {$t->account?->name}.")
            ->line('Category: '.ExpenseApprovals::categoryPath($t->category).'.')
            ->line('Amount: '.Money::format($t->amount).'.')
            ->line('Requested by '.($t->creator?->name ?? 'another user').'.')
            ->line('Requested for '.$t->requested_date?->format('Y-m-d').'.')
            ->line('Expires on '.ExpenseApprovals::expiresOn($requestedOn).' if not decided.')
            ->action('Open the expense', rtrim((string) config('app.frontend_url', config('app.url')), '/')."/notifications/{$this->notificationId}");
    }
}
