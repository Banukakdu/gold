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

    public function __construct(public readonly int $transactionId) {}
}
