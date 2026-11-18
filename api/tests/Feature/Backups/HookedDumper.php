<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\SqliteDumper;
use Closure;

/** SQLite dumper with a hook after the dump (AC 6). */
class HookedDumper extends SqliteDumper
{
    public function __construct(public Closure $afterDump) {}

    public function dump(string $path): void
    {
        parent::dump($path);
        ($this->afterDump)($path);
    }
}
