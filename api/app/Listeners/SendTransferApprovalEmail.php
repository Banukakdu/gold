<?php

namespace App\Listeners;

use App\Events\TransferApprovalDecided;
use App\Models\Transaction;
use App\Notifications\TransferApprovalDecidedNotification;
use App\Services\TransferApprovals;

/** AG-051 R11: the requester learns of approved, rejected and expired requests (not cancelled), never when inactive. */
class SendTransferApprovalEmail
{
    public function handle(TransferApprovalDecided $event): void
    {
        if (! in_array($event->state, [TransferApprovals::APPROVED, TransferApprovals::REJECTED, TransferApprovals::EXPIRED], true)) {
            return;
        }
        $t = Transaction::withoutGlobalScopes()->find($event->transactionId);
        $user = $t?->creator()->first();
        if (! $t || ! $user || $user->status !== 'active') {
            return;
        }
        // Review m4: the decision is committed; with the sync queue a mail failure must not turn it into a 500.
        try {
            $user->notify(new TransferApprovalDecidedNotification($t->id, $event->state));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
