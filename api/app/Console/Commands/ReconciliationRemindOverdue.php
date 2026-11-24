<?php

namespace App\Console\Commands;

use App\Services\ReconciliationReminders;
use Illuminate\Console\Command;

/**
 * AG-033f (daily 07:15 Asia/Colombo): bell reminder for each bank account that the S7 status report shows as Overdue
 * (docs/specs/reconciliation-overdue-reminder.md section 5). Once per account per overdue month-end, plus once per undo
 * (R6). Posts nothing and needs no queue worker. Exit 1 when any account failed (R8).
 */
class ReconciliationRemindOverdue extends Command
{
    protected $signature = 'reconciliation:remind-overdue {--dry-run : Show what would be sent and write nothing}';

    protected $description = 'Bell reminder for overdue bank reconciliations';

    public function handle(ReconciliationReminders $reminders): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $reminders->run($dry, $this->output->isVerbose());
        $this->line("As at {$r['today']} (business date), overdue through {$r['overdue_through']}".($dry ? ' (dry run: nothing is written)' : '').'.');
        foreach ($r['lines'] as $line) {
            $this->line($line);
        }
        if ($dry) {
            $this->info("Would send {$r['would']} reminder(s), skipped {$r['skipped']} already sent, {$r['failed']} failed.");
        } else {
            $this->info("Sent {$r['sent']} reminder(s), skipped {$r['skipped']} already sent, {$r['failed']} failed.");
        }

        return $r['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
