<?php

namespace App\Services\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * R5 / review M3: puts a finished archive and its sidecars on the local `backups` disk. Each file is written as
 * `{name}.part` and only renamed once all are written, so a full disk never leaves a file that looks like a backup.
 * On failure it removes the files of THIS run only (their names are unique per run); it is not a retention delete.
 */
class LocalStore
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('backup.local_disk'));
    }

    /** @param  array<int, array{0: string, 1: string}>  $pairs  [local file, name on the backups disk] */
    public function put(array $pairs): void
    {
        $disk = $this->disk();
        try {
            foreach ($pairs as [$from, $name]) {
                $in = fopen($from, 'rb');
                try {
                    if ($disk->writeStream("{$name}.part", $in) === false) {
                        throw new BackupException("Could not write {$name}.part.", 'store');
                    }
                } finally {
                    is_resource($in) && fclose($in);
                }
            }
            foreach ($pairs as [, $name]) {
                if ($disk->move("{$name}.part", $name) === false) {
                    throw new BackupException("Could not rename {$name}.part.", 'store');
                }
            }
        } catch (Throwable $e) {
            foreach ($pairs as [, $name]) {
                try {
                    $disk->delete(["{$name}.part", $name]);
                } catch (Throwable) {
                    // best effort: prune removes a leftover .part later
                }
            }
            throw new BackupException('Could not store the archive on this server: '.mb_substr($e->getMessage(), 0, 500), 'store', $e);
        }
    }
}
