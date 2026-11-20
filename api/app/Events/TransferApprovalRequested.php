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

    public function __construct(public readonly int $transactionId) {}
}
