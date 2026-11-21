<?php

namespace App\Listeners;

use App\Events\ExportFinished;
use App\Models\Export;
use App\Notifications\ExportFailedNotification;
use App\Notifications\ExportReadyNotification;

/** AG-087 S1 email: "ready" or "could not be prepared", when `exports.email` is on, never to an inactive user (R13). */
class SendExportEmail
{
    public function handle(ExportFinished $event): void
    {
        $export = $event->export;
        $user = $export->user()->first();
        if (! config('exports.email') || ! $user || $user->status !== 'active') {
            return;
        }
        $user->notify($export->status === Export::READY ? new ExportReadyNotification($export) : new ExportFailedNotification($export));
    }
}
