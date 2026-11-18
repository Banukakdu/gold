<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupPruner;
use Illuminate\Console\Command;

/** AG-093 R7 local retention. Also run at the end of every backup:run. Never touches the off-server disk. */
class BackupPrune extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Delete local backup archives past their retention (30 daily, 12 first-of-month, year-end 13 months once off-server), keeping the newest 7';

    public function handle(BackupPruner $pruner): int
    {
        return $pruner->prune(fn (string $level, string $m) => $level === 'error' ? $this->error($m) : $this->info($m));
    }
}
