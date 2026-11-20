<?php

namespace App\Console\Commands;

use App\Services\DoubleEntryBackfill;
use App\Support\DoubleEntryPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * docs/specs/double-entry.md R6 / F9. `--dry-run` prints the counts and a sample of the lines that would be added and
 * writes nothing; it also works before `migrate` (the accountant signs this output). Without it, the command does what
 * the migration does (for a re-run): preflight, chart, back-fill, one `ledger.double_entry_backfilled` audit row.
 */
class LedgerBackfillDoubleEntry extends Command
{
    protected $signature = 'ledger:backfill-double-entry
        {--dry-run : Print what would be added; write nothing}
        {--force : Run although the application is not in maintenance mode}';

    protected $description = 'Add the missing double-entry lines to existing transactions (idempotent)';

    public function handle(DoubleEntryBackfill $backfill): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! Schema::hasColumn('ledger_entries', 'origin')) {
            $this->error('The double-entry schema is not migrated yet. Run php artisan migrate (it runs the back-fill), or use --dry-run.');

            return self::FAILURE;
        }

        // Review R5: entries posted while the back-fill runs would trip its "cash balances unchanged" safety check.
        $live = ! app()->isDownForMaintenance();
        if (! $dry && $live && ! $this->option('force')) {
            $this->error('The application is up. Run php artisan down first (or pass --force if nothing else is posting).');

            return self::FAILURE;
        }

        $problems = DoubleEntryPreflight::problems();
        foreach ($problems as $p) {
            $this->error("Preflight: {$p}");
        }
        if ($problems && ! $dry) {
            $this->error('Back-fill stopped before any change.');

            return self::FAILURE;
        }

        try {
            if (! $dry) {
                $chart = $backfill->setupChart();
                $this->line('Chart accounts created: '.($chart ? json_encode($chart) : 'none'));
            }
            $sum = $backfill->run($dry, concurrent: ! $dry && $live);
        } catch (Throwable $e) {
            $this->error('Back-fill stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? 'DRY RUN (nothing written). ' : '')."Transactions to complete: {$sum['transactions']}; lines ".($dry ? 'to add' : 'added').": {$sum['lines']}");
        $this->line("Legacy voids skipped (no lines, identified only): {$sum['skipped_legacy_voids']}");
        $this->table(['Type', 'Lines'], collect($sum['by_type'])->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->table(['Branch', 'Lines'], collect($sum['by_branch'])->map(fn ($n, $b) => [$b, $n])->values()->all());
        if ($sum['locked_periods']) {
            $this->warn('Lines dated in locked months (back-fill exemption, R9):');
            $this->table(['Branch', 'Period', 'Lines'], array_map(fn ($l) => [$l['branch'], $l['period'], $l['lines']], $sum['locked_periods']));
        }
        $this->line('Cash, bank and wallet balances unchanged: '.($sum['money_balances_unchanged'] ? 'yes' : 'NO'));
        if ($dry) {
            $est = DoubleEntryBackfill::estimateSeconds($sum['transactions']);
            $this->line('Estimated time for the back-fill: about '.($est < 120 ? "{$est} seconds" : ceil($est / 60).' minutes')
                .' (about '.DoubleEntryBackfill::MS_PER_TRANSACTION.' ms per transaction on MySQL; the server may be slower). Plan the maintenance window with it.');
        } else {
            $this->line("Back-fill took {$sum['seconds']} seconds.");
        }

        if ($sum['sample']) {
            $this->line('Sample (first '.count($sum['sample']).' transactions):');
            foreach ($sum['sample'] as $s) {
                $this->line("  {$s['ref_no']} ({$s['type']}, {$s['status']})");
                foreach ($s['lines'] as $l) {
                    $side = $l['debit'] !== '0.00' ? "Dr {$l['debit']}" : "Cr {$l['credit']}";
                    $this->line("    {$l['date']}  {$side}  {$l['account']}".($l['contra'] ? '  (void contra)' : ''));
                }
            }
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
