<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AG-092 R3 storage of the company logo on the private `branding` disk: `logo/{uuid}.{ext}`, read-only (0440).
 * Files are never deleted (a replaced or removed logo stays on disk), except discard() of a file whose upload failed.
 */
final class LogoStore
{
    /** Write the checked file and return the `company_logo` setting value (no URL, no data: URI). */
    public static function put(string $sourcePath, array $info, string $originalName, ?int $userId): array
    {
        $disk = Storage::disk(Settings::DISK);
        $path = 'logo/'.Str::uuid()->toString().'.'.$info['ext'];
        $disk->put($path, (string) file_get_contents($sourcePath));
        @chmod($disk->path($path), 0440);

        return [
            'path' => $path,
            'original_name' => $originalName,
            'mime' => $info['mime'],
            'size_bytes' => $info['size'],
            'width' => $info['width'],
            'height' => $info['height'],
            'sha256' => hash_file('sha256', $sourcePath),
            'uploaded_by' => $userId,
            'uploaded_at' => now()->toIso8601String(),
        ];
    }

    /** Roll back a file that was written but never recorded (not a record deletion). */
    public static function discard(string $path): void
    {
        $disk = Storage::disk(Settings::DISK);
        @chmod($disk->path($path), 0640);
        $disk->delete($path);
    }
}
