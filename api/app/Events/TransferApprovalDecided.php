<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-051 R11: a request was approved, rejected, cancelled or expired. Dispatched after commit, once per decision.
 * App\Listeners\SendTransferApprovalEmail tells the requester; AG-094 may add in-app notifications.
 */
class TransferApprovalDecided
{
    use Dispatchable;

    /** @param  int|null  $userId  who decided; null for the expiry job */
    public function __construct(
        public readonly int $transactionId,
        public readonly string $state,
        public readonly ?int $userId,
    ) {}
}
