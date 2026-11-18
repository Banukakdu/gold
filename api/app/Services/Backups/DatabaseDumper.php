<?php

namespace App\Services\Backups;

/**
 * R2: how one database driver is dumped and loaded. Real implementations: MySqlDumper (mysqldump / mysql client)
 * and SqliteDumper (VACUUM INTO). Tests bind a fake, so no MySQL-only path runs in the SQLite test suite.
 */
interface DatabaseDumper
{
    /** `mysql` or `sqlite`. */
    public function driver(): string;

    /** The dump's name inside the archive: `db.sql` or `db.sqlite`. */
    public function entryName(): string;

    /** Review F6: base tables of the default connection that are not transactional (MySQL: engine is not InnoDB). */
    public function nonTransactionalTables(): array;

    /**
     * Writes a consistent dump of the default connection to $path.
     *
     * @throws BackupException stage `dump`
     */
    public function dump(string $path): void;

    /** R11 step 4/8: empties the scratch database of $connection (never the live one; the caller checks that). */
    public function wipe(string $connection): void;

    /**
     * Loads a dump made by dump() into the (empty) database of $connection.
     *
     * @throws BackupException stage `load`
     */
    public function load(string $path, string $connection): void;
}
