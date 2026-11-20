<?php

namespace App\Services\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * R6 off-server copies. This class only checks, writes new keys and reads: it never deletes and never writes to a
 * key that exists (review F3: a PutObject on an existing key would replace the backup). Retention is a bucket rule.
 */
class OffsiteStore
{
    public function configured(): bool
    {
        return (string) config('backup.offsite_disk') !== '';
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('backup.offsite_disk'));
    }

    /**
     * Writes every [local file, key] pair, after checking that none of the keys is taken (nothing is written then).
     *
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    public function putNew(array $pairs): void
    {
        $disk = $this->disk();
        foreach ($pairs as [, $key]) {
            if ($disk->exists($key)) {
                throw new BackupException('Off-server object already exists.', 'offsite');
            }
        }
        foreach ($pairs as [$local, $key]) {
            $in = fopen($local, 'rb');
            try {
                if ($disk->writeStream($key, $in) === false) {
                    throw new BackupException('Off-server write failed.', 'offsite');
                }
            } finally {
                is_resource($in) && fclose($in);
            }
            if ((int) $disk->size($key) !== (int) filesize($local)) {
                throw new BackupException('Off-server copy has a different size than the local file.', 'offsite');
            }
        }
    }

    /** The key of an archive under daily/, monthly/ or yearly/FY.../, or null. */
    public function find(string $filename): ?string
    {
        $disk = $this->disk();
        foreach (["daily/{$filename}", "monthly/{$filename}"] as $key) {
            if ($disk->exists($key)) {
                return $key;
            }
        }
        foreach ($disk->directories('yearly') as $dir) {
            if ($disk->exists("{$dir}/{$filename}")) {
                return "{$dir}/{$filename}";
            }
        }

        return null;
    }

    /** F3a / review F7: the oldest archive under yearly/, else under monthly/. */
    public function oldest(): ?string
    {
        $disk = $this->disk();
        foreach (['yearly', 'monthly'] as $prefix) {
            $zips = collect($disk->allFiles($prefix))->filter(fn ($k) => str_ends_with($k, '.zip'))
                ->sortBy(fn ($k) => basename($k))->values();
            if ($zips->isNotEmpty()) {
                return $zips->first();
            }
        }

        return null;
    }

    /** R13: manifests from daily/, newest first. @return array<int, array> */
    public function manifests(): array
    {
        $disk = $this->disk();

        return collect($disk->files('daily'))->filter(fn ($k) => str_ends_with($k, '.manifest.json'))->sortDesc()->values()
            ->map(fn ($k) => json_decode((string) $disk->get($k), true))->filter(fn ($m) => is_array($m))->values()->all();
    }

    public function download(string $key, string $to): void
    {
        $in = $this->disk()->readStream($key);
        $out = fopen($to, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);
    }

    /** The sha256 in a `sha256sum`-format sidecar next to $key, or null. */
    public function sidecarSha(string $key): ?string
    {
        $disk = $this->disk();

        return $disk->exists("{$key}.sha256") && preg_match('/^([0-9a-f]{64})/', (string) $disk->get("{$key}.sha256"), $m) ? $m[1] : null;
    }
}
