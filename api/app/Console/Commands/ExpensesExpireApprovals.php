<?php

namespace App\Console\Commands;

use App\Services\ExpenseApprovals;
use Illuminate\Console\Command;

/** AG-064 R8: expense requests still pending 30 days after their business date (requested_on) expire. Posts nothing. */
class ExpensesExpireApprovals extends Command
{
    protected $signature = 'expenses:expire-approvals';

    protected $description = 'Expire expense approval requests pending for '.ExpenseApprovals::EXPIRY_DAYS.' days';

    public function handle(ExpenseApprovals $approvals): int
    {
        $n = $approvals->expire();
        $this->info("Expired {$n} expense request(s).");

        return self::SUCCESS;
    }
}
