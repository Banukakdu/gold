<?php

namespace App\Jobs;

use App\Services\ScheduledReportRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * AG-088 R6 / R9: builds one scheduled report run on the AG-087 `exports` connection and queue (the one exports
 * worker, retry_after 900). An unexpected exception (deadlock, lost connection) is retried once; owner_lost_access and
 * invalid fail at once (ScheduledReportRuns::build calls $this->fail()); a timeout fails at once. Idempotent: only a
 * `queued` run is claimed, so a finished run is never built again.
 */
class RunScheduledReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;

    public $backoff = 60;

    public $timeout = 600;

    public $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        $this->onConnection('exports')->onQueue('exports');
    }

    public function handle(ScheduledReportRuns $runs): void
    {
        $runs->build($this);
    }

    public function failed(?Throwable $e): void
    {
        app(ScheduledReportRuns::class)->jobFailed($this->runId, $e);
    }
}
