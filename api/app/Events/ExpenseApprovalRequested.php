<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-064 R11: an expense was sent for approval. Dispatched after commit. App\Listeners\SendInAppNotifications writes
 * the approvers' in-app rows and queues the optional approval-request email.
 */
class ExpenseApprovalRequested
{
    use Dispatchable;

    /** @param  int|null  $userId  who requested it; never notified (AG-094 R2) */
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $userId = null,
    ) {}
}
