<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** R1 step 6 / R11 step 2: a fresh 0700 folder under storage/app/backup-temp, removed in `finally`. */
final class Workspace
{
    public static function create(): string
    {
        $root = (string) config('backup.temp_path');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $dir = $root.'/'.Str::uuid();
        mkdir($dir, 0700);
        chmod($dir, 0700);

        return $dir;
    }

    public static function remove(?string $dir): void
    {
        if ($dir !== null && is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
}
