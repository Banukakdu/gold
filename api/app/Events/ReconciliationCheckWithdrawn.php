<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** AG-033d: a check was withdrawn. Dispatched after commit; App\Listeners\SendInAppNotifications tells the preparer, and the checker when a Super Admin withdrew it (R10). */
class ReconciliationCheckWithdrawn
{
    use Dispatchable;

    public function __construct(
        public readonly int $checkId,
        public readonly ?int $userId,
    ) {}
}
