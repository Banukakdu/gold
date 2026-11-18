<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backups\BackupVerifier;
use App\Services\Backups\OffsiteStore;
use Illuminate\Console\Command;

/** AG-093 F3 / F3a / R11: restore a backup into the scratch database and check it. Exit 0 on pass, 1 on fail. */
class BackupVerify extends Command
{
    protected $signature = 'backup:verify
        {filename? : The archive, e.g. ag-backup-20261118-013000.zip}
        {--latest : The newest succeeded backup}
        {--oldest-offsite : The oldest yearly/ (else monthly/) archive on the off-server disk}';

    protected $description = 'Restore a backup into the verify database and run the control, ledger:verify and attachments:verify checks';

    public function handle(BackupVerifier $verifier, OffsiteStore $offsite): int
    {
        $key = null;
        if ($this->option('oldest-offsite')) {
            if (! $offsite->configured()) {
                $this->error('No off-server disk is configured (BACKUP_OFFSITE_DISK).');

                return self::FAILURE;
            }
            $key = $offsite->oldest();
            if ($key === null) {
                $this->error('There is no yearly/ or monthly/ archive off-server.');

                return self::FAILURE;
            }
            $filename = basename($key);
        } elseif ($this->option('latest')) {
            $filename = Backup::succeeded()->orderByDesc('started_at')->orderByDesc('id')->value('filename');
            if ($filename === null) {
                $this->error('There is no succeeded backup to verify.');

                return self::FAILURE;
            }
        } else {
            $filename = (string) $this->argument('filename');
            if ($filename === '' || ! preg_match('/^ag-backup-\d{8}-\d{6}\.zip$/', $filename)) {
                $this->error('Give a backup filename (ag-backup-YYYYMMDD-HHMMSS.zip), --latest or --oldest-offsite.');

                return self::FAILURE;
            }
        }
        $backup = Backup::where('filename', $filename)->first();
        $say = fn (string $level, string $m) => $level === 'error' ? $this->error($m) : $this->info($m);

        return $verifier->verify($filename, $backup, $say, 'artisan backup:verify', $key) ? self::SUCCESS : self::FAILURE;
    }
}
