<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AG-087 R3 (accounting review findings 2 and 12): every read of one export file runs inside a single read-only
 * transaction with a consistent snapshot, so the count, the rows, the totals, the settings and the period locks in
 * the header all come from one moment. Writes (the exports row, audit rows) happen outside it.
 *
 *  - MySQL: SET TRANSACTION ISOLATION LEVEL REPEATABLE READ (never the server default: under READ COMMITTED the
 *    consistent-snapshot clause does nothing), then START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY.
 *  - SQLite: one deferred read transaction with PRAGMA query_only. Its snapshot starts at the first read, not at
 *    BEGIN, so callers make the count their first statement.
 *  - Already inside a transaction (a test wrapped in one, or a caller's own): no snapshot can be opened, and the reads
 *    run in that transaction as they are.
 *
 * No locking read (lockForUpdate, sharedLock, PeriodLock::lockedQuery(..., share: true)) may run inside: InnoDB
 * answers a locking read from the latest committed data, not from the snapshot. Nothing inside may open a
 * transaction either (MySQL would commit the snapshot implicitly; SQLite refuses).
 */
final class ReadSnapshot
{
    /**
     * Test hook (ACs 7, 32, 37): called with a point name. 'counted' runs right after the first statement (the count);
     * 'chunk' runs before each chunk of a chunked build with the chunk number, and returning false skips that chunk.
     *
     * @var (Closure(string, mixed...): mixed)|null
     */
    public static ?Closure $hook = null;

    /** True while a snapshot opened by run() is open (on this connection). */
    private static bool $open = false;

    /**
     * Run $fn inside the snapshot. $fn receives the extraction time: taken immediately before the snapshot statement,
     * so "recorded before this time" is always true (finding 1). Whole seconds, rounded down (the audit log shows
     * seconds; rounding down keeps the statement true).
     *
     * @template T
     *
     * @param  Closure(CarbonImmutable): T  $fn
     * @return T
     */
    public static function run(Closure $fn): mixed
    {
        $c = DB::connection();
        $driver = $c->getDriverName();
        $at = CarbonImmutable::now()->startOfSecond();
        if (self::$open || $c->transactionLevel() > 0 || ! in_array($driver, ['mysql', 'sqlite'], true)) {
            return $fn($at);
        }

        if ($driver === 'mysql') {
            $c->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $c->unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        } else {
            $c->unprepared('BEGIN DEFERRED');
            $c->unprepared('PRAGMA query_only = ON');
        }
        self::$open = true;
        try {
            return $fn($at);
        } finally {
            self::abandon();
        }
    }

    /**
     * End the snapshot if one is open: read only, so nothing is kept (ROLLBACK). Also called by RunExport::failed(),
     * which a timeout can fire while the build still holds the snapshot, before it writes the exports row.
     */
    public static function abandon(): void
    {
        if (! self::$open) {
            return;
        }
        self::$open = false;
        $c = DB::connection();
        try {
            if ($c->getDriverName() === 'sqlite') {
                $c->unprepared('PRAGMA query_only = OFF');
            }
            $c->unprepared('ROLLBACK');
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function isOpen(): bool
    {
        return self::$open;
    }

    /** Call the test hook, if any (see $hook). */
    public static function hook(string $point, mixed ...$args): mixed
    {
        return self::$hook ? (self::$hook)($point, ...$args) : null;
    }
}
