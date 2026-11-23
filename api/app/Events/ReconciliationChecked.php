<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** AG-033d: a check was recorded (or a re-check). Dispatched after commit; App\Listeners\SendInAppNotifications tells the preparer (R10). */
class ReconciliationChecked
{
    use Dispatchable;

    public function __construct(
        public readonly int $checkId,
        public readonly ?int $userId,
    ) {}
}
