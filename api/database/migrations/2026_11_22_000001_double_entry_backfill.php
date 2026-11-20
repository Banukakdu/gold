<?php

use App\Models\Account;
use App\Services\DoubleEntryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Full double entry, data part (docs/specs/double-entry.md R1 and R6): chart of accounts and back-fill of existing
 * history, with the same code as `php artisan ledger:backfill-double-entry`. Lines are only inserted (origin backfill);
 * no line is changed or deleted and no cash / bank / wallet balance changes. Deploy steps: README "Deploying the
 * double-entry release" (maintenance mode, dry run signed by the accountant, ledger:verify before and after).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(DoubleEntryBackfill::class)->migrate();
    }

    public function down(): void
    {
        // Ledger lines are immutable: once any line sits on a chart account, rolling back would leave lines on
        // accounts that no longer mean anything. Refuse before any change, like the attachments migration.
        $lines = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->whereNotIn('a.type', Account::MONEY_TYPES)->count();
        if ($lines > 0) {
            throw new RuntimeException("Rollback stopped before any change: {$lines} ledger line(s) are on chart-of-accounts accounts "
                .'(double-entry postings and back-fill), and ledger lines cannot be deleted. Restore a backup taken before this migration instead.');
        }
        DB::table('accounts')->whereNotIn('type', Account::MONEY_TYPES)->delete();
        DB::table('categories')->update(['ledger_code' => null]);
        DB::table('accounts')->update(['code' => null]);
    }
};
