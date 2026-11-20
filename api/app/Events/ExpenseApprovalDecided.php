<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-064 R11: an expense request was approved, rejected, cancelled or expired. Dispatched after commit, once per
 * decision. App\Listeners\SendInAppNotifications tells the requester (not on cancel), closes the approvers' rows (R6)
 * and flags an approval by the user who brought the requester in (R4b).
 */
class ExpenseApprovalDecided
{
    use Dispatchable;

    /** @param  int|null  $userId  who decided; null for the expiry job */
    public function __construct(
        public readonly int $transactionId,
        public readonly string $state,
        public readonly ?int $userId,
    ) {}
}
