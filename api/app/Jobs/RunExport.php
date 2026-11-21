<?php

namespace App\Jobs;

use App\Services\BackgroundExports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * AG-087 R9: builds one background export, on the `exports` connection and queue (its own worker, so emails are never
 * stuck behind an export). An unexpected exception (deadlock, lost connection) is retried once; access_revoked,
 * over_limit, too_large, incomplete and invalid fail at once (BackgroundExports::run calls $this->fail()); a timeout
 * fails at once. The job is idempotent: a finished export is never built again.
 */
class RunExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;

    public $backoff = 60;

    public $timeout = 600;

    public $failOnTimeout = true;

    public function __construct(public string $exportId)
    {
        $this->onConnection('exports')->onQueue('exports');
    }

    public function handle(BackgroundExports $exports): void
    {
        $exports->run($this);
    }

    public function failed(?Throwable $e): void
    {
        app(BackgroundExports::class)->jobFailed($this->exportId, $e);
    }
}
