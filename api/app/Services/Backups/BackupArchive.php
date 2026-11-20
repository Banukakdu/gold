<?php

namespace App\Services\Backups;

use ZipArchive;

/**
 * R4 / R5: the AES-256 zip. The database dump, manifest and control file are deflated; attachment and logo files are
 * stored (already compressed). Every entry is encrypted when a password is set. Bound in the container so a test can
 * replace the self-check (AC 14).
 */
class BackupArchive
{
    /** @param  array<int, array{0: string, 1: string, 2: bool}>  $entries  [name in zip, source file, deflate?] */
    public function build(string $zipPath, array $entries, #[\SensitiveParameter] ?string $password): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new BackupException('Could not create the archive.', 'archive');
        }
        foreach ($entries as [$name, $source, $deflate]) {
            if (! $zip->addFile($source, $name)) {
                throw new BackupException("Could not add {$name} to the archive.", 'archive');
            }
            $zip->setCompressionName($name, $deflate ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE);
            if ($password !== null && $password !== '' && ! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) {
                throw new BackupException('Could not encrypt the archive (AES-256 not available in libzip).', 'archive');
            }
        }
        if (! $zip->close()) {
            throw new BackupException('Could not write the archive: '.$zip->getStatusString(), 'archive');
        }
        chmod($zipPath, 0600);
    }

    /**
     * R5 self-check: reopen with CHECKCONS and the password; entry count and the hashes of the named entries.
     *
     * @param  array<string, string>  $hashes  entry name => expected sha256
     * @return string|null the problem, or null when the archive is good
     */
    public function selfCheck(string $zipPath, #[\SensitiveParameter] ?string $password, int $expectedEntries, array $hashes): ?string
    {
        $zip = new ZipArchive;
        $open = $zip->open($zipPath, ZipArchive::CHECKCONS);
        if ($open !== true) {
            return "The archive does not open (zip error {$open}).";
        }
        try {
            if ($password) {
                $zip->setPassword($password);
            }
            if ($zip->numFiles !== $expectedEntries) {
                return "The archive holds {$zip->numFiles} entries, expected {$expectedEntries}.";
            }
            foreach ($hashes as $name => $expected) {
                $actual = self::hashEntry($zip, $name);
                if ($actual === null || ! hash_equals($expected, $actual)) {
                    return "{$name} in the archive does not match its sha256.";
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts with the first password that opens it (current, then older ones; R12). Returns false when none does.
     *
     * @param  string[]  $passwords
     */
    public function extract(string $zipPath, string $dir, #[\SensitiveParameter] array $passwords): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new BackupException('The archive does not open.', 'extract');
        }
        try {
            foreach ($passwords === [] ? [''] : $passwords as $p) {
                $zip->setPassword($p);
                if ($zip->getFromName('manifest.json') !== false) {
                    if (! $zip->extractTo($dir)) {
                        throw new BackupException('Could not extract the archive: '.$zip->getStatusString(), 'extract');
                    }

                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    public static function hashEntry(ZipArchive $zip, string $name): ?string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return null;
        }
        $ctx = hash_init('sha256');
        while (! feof($stream)) {
            $chunk = fread($stream, 1 << 20);
            if ($chunk === false) {
                fclose($stream);

                return null;
            }
            hash_update($ctx, $chunk);
        }
        fclose($stream);

        return hash_final($ctx);
    }
}
