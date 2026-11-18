<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupRunner;
use Illuminate\Console\Command;

/** AG-093 F1 / F6 (docs/specs/backups.md R1-R9, R13, R14). Scheduled daily at 01:30 Asia/Colombo. */
class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--year-end= : Take the year-end snapshot now (start year of the fiscal year; empty = the last ended year)}
        {--scheduled : Set by the scheduler (triggered_by = scheduler)}';

    protected $description = 'Back up the database and the attachment and logo files into one encrypted archive, copy it off-server and prune';

    public function handle(BackupRunner $runner): int
    {
        $yearEnd = null;
        if ($this->input->hasParameterOption('--year-end')) {
            $v = (string) $this->option('year-end');
            if ($v !== '' && ! ctype_digit($v)) {
                $this->error('--year-end takes the start year of the fiscal year, e.g. --year-end=2026.');

                return self::FAILURE;
            }
            $yearEnd = $v === '' ? true : (int) $v;
        }

        return $runner->run($this->option('scheduled') ? 'scheduler' : 'console', $yearEnd, $this->say(...));
    }

    private function say(string $level, string $message): void
    {
        match ($level) {
            'error' => $this->error($message),
            'warn' => $this->warn($message),
            default => $this->info($message),
        };
    }
}
