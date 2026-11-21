<?php

namespace App\Events;

use App\Models\Export;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * AG-087 R12: a background export became ready or failed. App\Listeners\SendExportEmail sends the email; AG-094 adds
 * an in-app (database notification) listener. There is no other coupling.
 */
class ExportFinished
{
    use Dispatchable;

    public function __construct(public readonly Export $export) {}
}
