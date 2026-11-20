<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Checks run at the start of the controls migration, before any DDL (reviewer R2, R3).
 * MySQL commits every DDL statement on its own, so a failure half-way would leave a half-migrated schema.
 */
final class MigrationPreflight
{
    /**
     * R2: the new unique index allows one active lock per (branch or global, period). The old unique key did not
     * stop duplicate GLOBAL rows (MySQL unique indexes allow several NULL branch_ids), so check before migrating.
     */
    public static function assertNoDuplicatePeriodLocks(): void
    {
        if (! Schema::hasTable('period_locks')) {
            return;
        }
        $dupes = DB::table('period_locks')
            ->selectRaw('COALESCE(branch_id, 0) as scope, period, COUNT(*) as n')
            ->groupByRaw('COALESCE(branch_id, 0), period')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($dupes->isNotEmpty()) {
            $list = $dupes->map(fn ($d) => ((int) $d->scope === 0 ? 'all branches' : "branch #{$d->scope}")." {$d->period} ({$d->n} rows)")->implode(', ');

            throw new RuntimeException("Migration stopped before any change: period_locks has duplicate locks for {$list}. "
                .'Keep one row per branch and month (they are identical locks), then run the migration again.');
        }
    }

    /** R3: on MySQL with binary logging, CREATE TRIGGER needs SUPER or log_bin_trust_function_creators = 1. */
    public static function assertCanCreateTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $vars = DB::selectOne('SELECT @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust');
        $grants = array_map(fn ($row) => (string) array_values((array) $row)[0], DB::select('SHOW GRANTS'));

        if ($problem = self::triggerPrivilegeProblem((bool) $vars->log_bin, (bool) $vars->trust, $grants)) {
            throw new RuntimeException("Migration stopped before any change: {$problem}");
        }
    }

    /** Pure decision, unit-tested. Null when triggers can be created. */
    public static function triggerPrivilegeProblem(bool $logBin, bool $trustCreators, array $grants): ?string
    {
        if (! $logBin || $trustCreators) {
            return null;
        }
        foreach ($grants as $g) {
            if (preg_match('/^GRANT\s+(.*)\s+ON\s+\*\.\*\s+TO/i', $g, $m)
                && preg_match('/\b(ALL PRIVILEGES|SUPER|SET_ANY_DEFINER|SET_USER_ID)\b/i', $m[1])) {
                return null;
            }
        }

        return 'binary logging is on and this database user cannot create triggers. '
            .'Set log_bin_trust_function_creators = 1 on the MySQL server (or run the migration as a user with SUPER), '
            .'then run the migration again. See README "Deploying the controls release".';
    }
}
