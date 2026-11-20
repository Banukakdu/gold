<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-051 R11: a transfer was sent for approval. Dispatched after commit. No listener in this item; AG-094 adds the
 * approvers' notification.
 */
class TransferApprovalRequested
{
    use Dispatchable;

    public function __construct(public readonly int $transactionId) {}
}
