<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\BackupArchive;

/** AC 14: the self-check finds a mismatch. */
class MismatchArchive extends BackupArchive
{
    public function selfCheck(string $zipPath, ?string $password, int $expectedEntries, array $hashes): ?string
    {
        return 'db.sqlite in the archive does not match its sha256.';
    }
}
