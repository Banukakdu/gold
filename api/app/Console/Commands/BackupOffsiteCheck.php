<?php

namespace App\Console\Commands;

use App\Services\Backups\OffsiteStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * AG-093 R6 + review F3: go-live check of the off-server bucket. The app does not administer the bucket; this only
 * reads its versioning and object-lock settings where the S3 API allows it. Exit 1 when versioning is not on.
 */
class BackupOffsiteCheck extends Command
{
    protected $signature = 'backup:offsite-check';

    protected $description = 'Check that the off-server backup bucket has versioning (required) and object lock (required for yearly/)';

    public function handle(OffsiteStore $offsite): int
    {
        if (! $offsite->configured()) {
            $this->error('No off-server disk is configured (BACKUP_OFFSITE_DISK). Backups are kept only on this server.');

            return self::FAILURE;
        }
        try {
            $disk = $offsite->disk();
            $disk->files('daily');
            $this->info('The off-server disk is reachable and can be listed.');
        } catch (Throwable $e) {
            $this->error('The off-server disk cannot be listed: '.$e->getMessage());

            return self::FAILURE;
        }

        $client = method_exists($disk, 'getClient') ? $disk->getClient() : null;
        $bucket = (string) config('filesystems.disks.'.config('backup.offsite_disk').'.bucket');
        if (! $client || ! method_exists($client, 'getBucketVersioning')) {
            $this->warn('This disk is not S3, so its settings cannot be read. Confirm by hand that versioning is on, object lock (compliance) '
                .'covers yearly/, and the lifecycle expires daily/ after 31 days, monthly/ after 366 and yearly/ after 2,922.');

            return self::SUCCESS;
        }
        $ok = true;
        try {
            $status = $client->getBucketVersioning(['Bucket' => $bucket])['Status'] ?? null;
            if ($status === 'Enabled') {
                $this->info('Versioning: enabled.');
            } else {
                $ok = false;
                $this->error('Versioning is NOT enabled ('.($status ?? 'never enabled').'). It is required: an overwrite could otherwise replace a backup.');
            }
        } catch (Throwable $e) {
            $ok = false;
            $this->error('Could not read the versioning setting (the key may lack s3:GetBucketVersioning): '.$e->getMessage());
        }
        try {
            $lock = $client->getObjectLockConfiguration(['Bucket' => $bucket])['ObjectLockConfiguration'] ?? [];
            $mode = $lock['Rule']['DefaultRetention']['Mode'] ?? null;
            $this->info('Object lock: '.($lock['ObjectLockEnabled'] ?? 'not enabled').($mode ? ", default retention {$mode}" : ', no default retention')
                .'. yearly/ needs compliance mode.');
            if (($lock['ObjectLockEnabled'] ?? null) !== 'Enabled') {
                $this->warn('Object lock is not enabled. It is required for yearly/ (accounting review F3).');
            }
        } catch (Throwable $e) {
            $this->warn('Object lock is not configured or cannot be read: '.$e->getMessage());
        }
        try {
            $rules = $client->getBucketLifecycleConfiguration(['Bucket' => $bucket])['Rules'] ?? [];
            foreach ($rules as $r) {
                $this->line('Lifecycle: '.($r['Filter']['Prefix'] ?? $r['Prefix'] ?? '(all)').' expires after '.($r['Expiration']['Days'] ?? '?').' days ('.($r['Status'] ?? '?').')');
            }
        } catch (Throwable $e) {
            $this->warn('No lifecycle rules found or readable: '.$e->getMessage());
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
