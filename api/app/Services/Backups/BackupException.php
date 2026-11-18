<?php

namespace App\Services\Backups;

use RuntimeException;

/** A backup or restore-test failure with the stage it happened in (docs/specs/backups.md R1, R11). */
class BackupException extends RuntimeException
{
    public function __construct(string $message, public readonly string $stage)
    {
        parent::__construct($message);
    }
}
