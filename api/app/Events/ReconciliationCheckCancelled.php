<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** AG-033d: an undo cancelled a check. Dispatched after commit; App\Listeners\SendInAppNotifications tells the checker (R10). */
class ReconciliationCheckCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly int $checkId,
        public readonly ?int $userId,
    ) {}
}
