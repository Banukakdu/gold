<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-052 R11: a receipt moved to received, disputed or cancelled. Dispatched after commit. No listener in this item;
 * AG-094 adds them (e.g. tell the sender about a discrepancy).
 */
class TransferReceiptChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $transactionId,
        public readonly string $oldState,
        public readonly string $newState,
        public readonly ?int $userId,
    ) {}
}
