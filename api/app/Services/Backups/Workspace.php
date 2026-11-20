<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * R1 step 6 / R11 step 2: a fresh 0700 folder under storage/app/backup-temp, removed in `finally`. Review M2: a
 * killed run leaves its folder (plain dump, credentials file); sweep() removes folders older than the stale limit.
 */
final class Workspace
{
    public static function create(): string
    {
        $root = self::root();
        $dir = $root.'/'.Str::uuid();
        mkdir($dir, 0700);
        chmod($dir, 0700);

        return $dir;
    }

    public static function root(): string
    {
        $root = (string) config('backup.temp_path');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        @chmod($root, 0700);

        return $root;
    }

    public static function remove(?string $dir): void
    {
        if ($dir !== null && is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }

    /** @return string[] the stale folders removed (older than backup.stale_hours) */
    public static function sweep(): array
    {
        $limit = time() - (int) config('backup.stale_hours', 6) * 3600;
        $removed = [];
        foreach (glob(self::root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $limit) {
                File::deleteDirectory($dir);
                $removed[] = basename($dir);
            }
        }

        return $removed;
    }
}
