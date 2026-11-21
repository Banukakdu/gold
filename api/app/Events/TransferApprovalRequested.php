<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-051 R11: a transfer was sent for approval. Dispatched after commit. AG-094: App\Listeners\SendInAppNotifications
 * writes the approvers' in-app rows and queues the optional approval-request email.
 */
class TransferApprovalRequested
{
    use Dispatchable;

    /** @param  int|null  $userId  who requested it; the in-app listener never notifies this user (AG-094 R2) */
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $userId = null,
    ) {}
}
