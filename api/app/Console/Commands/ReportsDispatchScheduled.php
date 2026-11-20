<?php

namespace App\Console\Commands;

use App\Services\ScheduledReportRuns;
use Illuminate\Console\Command;

/**
 * AG-088 R4 (every minute): queue the due scheduled report runs, record missed ones, and fail stale runs and stale
 * deliveries. Posts nothing.
 */
class ReportsDispatchScheduled extends Command
{
    protected $signature = 'reports:dispatch-scheduled';

    protected $description = 'Queue due scheduled email reports, record missed runs and fail stale runs and deliveries';

    public function handle(ScheduledReportRuns $runs): int
    {
        $r = $runs->dispatch();
        $this->info("Queued {$r['queued']}, missed {$r['missed']}, stale runs {$r['stale_runs']}, stale deliveries {$r['stale_deliveries']}.");

        return self::SUCCESS;
    }
}
