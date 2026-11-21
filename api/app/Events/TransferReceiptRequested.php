<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-052 R11: a between-branch transfer was posted as "Awaiting receipt". Dispatched after commit. No listener in
 * this item; AG-094 adds notifications.
 */
class TransferReceiptRequested
{
    use Dispatchable;

    /** @param  int|null  $userId  who posted or approved it (null from a console job); the in-app listener never notifies this user (AG-094 R2) */
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $userId = null,
    ) {}
}
