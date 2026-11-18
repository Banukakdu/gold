<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupRestoredNotification;
use App\Services\Backups\BackupAlerts;
use App\Services\Backups\OffsiteStore;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * AG-093 F5 / R10 step 9 + review F5: record that the database was restored from a backup. Revokes every API token,
 * completes the restored backup's own row (it was `running` at dump time), re-creates rows for newer archives still on
 * the local disk, writes `backup.restored` and emails the Super Admins.
 */
class BackupRestored extends Command
{
    protected $signature = 'backup:restored {filename : The archive restored} {--reason= : Why the restore was needed (required)}';

    protected $description = 'Record a restore from a backup: audit row, sign everyone out, re-list newer local backups, alert Super Admins';

    public function handle(OffsiteStore $offsite): int
    {
        $filename = (string) $this->argument('filename');
        $reason = trim((string) $this->option('reason'));
        if ($reason === '' || mb_strlen($reason) > 500) {
            $this->error('Give --reason="..." (1 to 500 characters).');

            return self::FAILURE;
        }
        $local = Storage::disk((string) config('backup.local_disk'));
        $row = Backup::where('filename', $filename)->first();
        $manifest = $this->manifest($local, $filename) ?? $row?->manifest;
        if (! $row && ! $manifest) {
            $this->error("Unknown backup {$filename}: no row and no manifest on this server.");

            return self::FAILURE;
        }

        $recreated = [];
        foreach (collect($local->files())->filter(fn ($f) => preg_match('/^ag-backup-\d{8}-\d{6}\.zip$/', $f))->sort()->values() as $zip) {
            if (strcmp($zip, $filename) < 0) {
                continue;
            }
            $m = $this->manifest($local, $zip);
            $existing = Backup::where('filename', $zip)->first();
            if ($m === null || ($existing && $existing->status !== Backup::RUNNING)) {
                continue;
            }
            $values = $this->rowFrom($local, $offsite, $zip, $m);
            $existing ? $existing->update($values) : Backup::create($values + ['filename' => $zip]);
            $recreated[] = $zip;
        }
        $row ??= Backup::where('filename', $filename)->first();

        $tokens = DB::table('personal_access_tokens')->count();
        DB::table('personal_access_tokens')->delete();

        $startedAt = $row?->started_at?->toIso8601String() ?? (string) ($manifest['created_at'] ?? '');
        $operator = (string) (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '')
            ?: (string) get_current_user();
        AuditLog::record('backup.restored', $row, null, [
            'filename' => $filename, 'sha256' => $row?->sha256 ?? $this->sidecarSha($local, $filename), 'reason' => $reason,
            'restored_at' => now()->toIso8601String(), 'operator' => $operator, 'backup_started_at' => $startedAt,
            'tokens_revoked' => $tokens, 'rows_recreated' => $recreated,
        ], null, ['ip' => null, 'user_agent' => 'artisan backup:restored']);

        $local_time = $startedAt !== '' ? CarbonImmutable::parse($startedAt)->setTimezone(BusinessDate::timezone())->format('Y-m-d H:i') : '?';
        $sent = BackupAlerts::send(new BackupRestoredNotification($filename, $local_time, $reason, $operator));

        $this->info("Restore from {$filename} recorded. {$tokens} API token(s) revoked: everyone signs in again.");
        if ($recreated) {
            $this->info('Backup rows completed or re-created: '.implode(', ', $recreated));
        }
        $this->warn("Before `php artisan up`: re-apply every user deactivation, role change, period lock and year-end close made after {$local_time} (Asia/Colombo).");
        if (! $sent) {
            $this->error('The Super Admin email could not be sent; see the log.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function manifest($disk, string $zip): ?array
    {
        $m = $disk->exists("{$zip}.manifest.json") ? json_decode((string) $disk->get("{$zip}.manifest.json"), true) : null;

        return is_array($m) ? $m : null;
    }

    private function sidecarSha($disk, string $zip): ?string
    {
        return $disk->exists("{$zip}.sha256") && preg_match('/^([0-9a-f]{64})/', (string) $disk->get("{$zip}.sha256"), $x) ? $x[1] : null;
    }

    /** A row rebuilt from the archive's sidecars and manifest (R10 step 9). */
    private function rowFrom($local, OffsiteStore $offsite, string $zip, array $m): array
    {
        $offsiteStatus = Backup::NOT_CONFIGURED;
        if ($offsite->configured()) {
            try {
                $offsiteStatus = $offsite->disk()->exists("daily/{$zip}") ? Backup::COPIED : Backup::OFFSITE_FAILED;
            } catch (\Throwable $e) {
                report($e);
                $offsiteStatus = Backup::OFFSITE_FAILED;
            }
        }
        $ye = $m['year_end'] ?? null;
        $failed = ! empty($m['continuity_failed']);
        $start = CarbonImmutable::parse((string) $m['created_at'])->utc();
        $monthStart = $start->setTimezone(BusinessDate::timezone())->startOfMonth()->utc();
        $first = ! Backup::succeeded()->where('filename', '<>', $zip)->where('started_at', '>=', $monthStart)->where('started_at', '<', $start)->exists();

        return [
            'status' => $failed ? Backup::FAILED : Backup::SUCCEEDED,
            'failed_stage' => $failed ? 'continuity' : null,
            'error' => $failed ? 'Database rolled back without a recorded restore (re-created from the manifest).' : null,
            'triggered_by' => 'console',
            'started_at' => $start,
            'finished_at' => CarbonImmutable::createFromTimestampUTC($local->lastModified($zip)),
            'size_bytes' => $local->size($zip),
            'sha256' => $this->sidecarSha($local, $zip),
            'db_driver' => (string) ($m['db_driver'] ?? ''),
            'encrypted' => (string) config('backup.password') !== '',
            'files_count' => (int) ($m['files_count'] ?? 0),
            'kept_as' => $ye ? Backup::YEAR_END : ($first ? Backup::MONTHLY : Backup::DAILY),
            'fiscal_year' => $ye['fiscal_year'] ?? null,
            'year_end_close_id' => isset($ye['close_id']) && DB::table('year_end_closes')->where('id', $ye['close_id'])->exists() ? $ye['close_id'] : null,
            'offsite_status' => $offsiteStatus,
            'manifest' => $m,
        ];
    }
}
