<?php

namespace App\Console\Commands;

use App\Services\DoubleEntryBackfill;
use App\Support\DoubleEntryPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Review R4: create any chart account that is missing (a branch or category inserted without its model events, e.g.
 * by a raw insert or Model::withoutEvents in a seeder or tinker). Idempotent; it only adds accounts and codes, and
 * writes one `ledger.chart_created` row (source "sync") when it added anything. Postings also create a missing
 * system or category account on first use (R1 safety net); this command makes the chart complete before that.
 */
class LedgerChartSync extends Command
{
    protected $signature = 'ledger:chart-sync';

    protected $description = 'Create missing chart-of-accounts accounts (idempotent)';

    public function handle(DoubleEntryBackfill $backfill): int
    {
        if (! Schema::hasColumn('accounts', 'code')) {
            $this->error('The double-entry schema is not migrated yet. Run php artisan migrate.');

            return self::FAILURE;
        }
        if ($limits = DoubleEntryPreflight::chartLimits()) {
            array_map(fn ($p) => $this->error($p), $limits);

            return self::FAILURE;
        }
        $created = $backfill->setupChart('sync');
        if (! $created) {
            $this->info('The chart of accounts is complete. Nothing was added.');

            return self::SUCCESS;
        }
        $this->info('Chart accounts added:');
        $this->table(['Branch', 'Accounts'], collect($created)->map(fn ($v, $code) => [$code, $v['accounts']])->values()->all());

        return self::SUCCESS;
    }
}
