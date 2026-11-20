<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\MySqlDumper;

/** The real MySqlDumper (Process, defaults file) with a stand-in mysqldump script; no information_schema on SQLite. */
class ScriptedMySqlDumper extends MySqlDumper
{
    public array $tables = [];

    public function nonTransactionalTables(): array
    {
        return $this->tables;
    }
}
