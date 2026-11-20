<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Throwable;

/** R2 SQLite (development): `VACUUM INTO` writes a consistent copy; loading copies the file. */
class SqliteDumper implements DatabaseDumper
{
    public function driver(): string
    {
        return 'sqlite';
    }

    public function entryName(): string
    {
        return 'db.sqlite';
    }

    public function nonTransactionalTables(): array
    {
        return [];
    }

    public function dump(string $path): void
    {
        try {
            $db = DB::connection();
            $db->statement('VACUUM INTO '.$db->getPdo()->quote($path));
        } catch (Throwable $e) {
            throw new BackupException('SQLite copy failed: '.mb_substr($e->getMessage(), 0, 500), 'dump');
        }
        if (! is_file($path) || filesize($path) === 0) {
            throw new BackupException('SQLite copy failed: the copy is empty.', 'dump');
        }
    }

    public function wipe(string $connection): void
    {
        DB::purge($connection);
        $file = (string) config("database.connections.{$connection}.database");
        if ($file !== '' && $file !== ':memory:' && is_file($file)) {
            unlink($file);
        }
    }

    public function load(string $path, string $connection): void
    {
        $file = (string) config("database.connections.{$connection}.database");
        if ($file === '' || $file === ':memory:') {
            throw new BackupException('The verify connection needs a SQLite file (BACKUP_VERIFY_DB_DATABASE).', 'load');
        }
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        DB::purge($connection);
        if (! copy($path, $file)) {
            throw new BackupException('Could not copy the SQLite database to the verify connection.', 'load');
        }
        chmod($file, 0600);
    }
}
