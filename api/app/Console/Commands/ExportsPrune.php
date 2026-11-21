<?php

namespace App\Console\Commands;

use App\Services\BackgroundExports;
use Illuminate\Console\Command;

/** AG-087 R10 (hourly): expire ready exports, fail stale ones, delete orphan files. The only code that deletes here. */
class ExportsPrune extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Expire background export files after 24 h, fail stale exports and remove orphan files';

    public function handle(BackgroundExports $exports): int
    {
        $r = $exports->prune();
        $this->info("Expired {$r['expired']}, failed as stale {$r['stale']}, orphan files deleted {$r['orphans']}, scheduled report files removed {$r['scheduled']}.");

        return self::SUCCESS;
    }
}
