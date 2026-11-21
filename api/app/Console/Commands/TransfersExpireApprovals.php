<?php

namespace App\Console\Commands;

use App\Services\TransferApprovals;
use Illuminate\Console\Command;

/** AG-051 R8: requests still pending 30 days after their business date (requested_on) expire. Posts nothing. */
class TransfersExpireApprovals extends Command
{
    protected $signature = 'transfers:expire-approvals';

    protected $description = 'Expire transfer approval requests pending for '.TransferApprovals::EXPIRY_DAYS.' days';

    public function handle(TransferApprovals $approvals): int
    {
        $n = $approvals->expire();
        $this->info("Expired {$n} transfer request(s).");

        return self::SUCCESS;
    }
}
