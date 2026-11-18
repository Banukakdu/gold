<?php

namespace App\Services\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use App\Support\BusinessDate;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * R11 restore test (`backup:verify`). Restores an archive into the scratch `backup_verify` database and a temp folder,
 * then checks row counts, control.json (review F2), `ledger:verify` and `attachments:verify` against them. It never
 * writes to the live database except the backup row and the audit row.
 */
class BackupVerifier
{
    private const SWITCHED_DISKS = ['attachments', 'branding'];

    public function __construct(private BackupArchive $archive, private OffsiteStore $offsite) {}

    /**
     * @param  string|null  $key  read this off-server key instead of looking for the archive
     * @param  Closure(string, string): void  $say
     */
    public function verify(string $filename, ?Backup $backup, Closure $say, string $agent = 'artisan backup:verify', ?string $key = null): bool
    {
        $client = ['ip' => null, 'user_agent' => $agent];
        $started = microtime(true);
        $live = DB::getDefaultConnection();
        $verifyConn = (string) config('backup.verify_connection');
        $tmp = null;
        $dumper = null;
        $wiped = false;
        $disks = null;
        $stage = 'fetch';
        $counts = null;
        try {
            $tmp = Workspace::create();
            [$zip, $expected] = $this->fetch($filename, $backup, $tmp, $key);
            $stage = 'checksum';
            $actual = hash_file('sha256', $zip);
            if ($expected === null || ! hash_equals($expected, $actual)) {
                throw new BackupException("sha256 mismatch: the archive has {$actual}, expected ".($expected ?? 'unknown').'.', 'checksum');
            }

            $stage = 'extract';
            $dir = $tmp.'/restore';
            mkdir($dir, 0700);
            $passwords = array_values(array_filter([(string) config('backup.password'), ...(array) config('backup.old_passwords', [])], fn ($p) => $p !== ''));
            if (! $this->archive->extract($zip, $dir, $passwords)) {
                throw new BackupException('The archive does not open with the current or any old password (BACKUP_ARCHIVE_OLD_PASSWORDS).', 'extract');
            }
            $manifest = json_decode((string) @file_get_contents($dir.'/manifest.json'), true);
            $controlRaw = (string) @file_get_contents($dir.'/control.json');
            $control = json_decode($controlRaw, true);
            if (! is_array($manifest) || ! is_array($control)) {
                throw new BackupException('The archive has no readable manifest.json or control.json.', 'extract');
            }
            $dbFile = $dir.'/'.($manifest['db_entry'] ?? ($manifest['db_driver'] === 'mysql' ? 'db.sql' : 'db.sqlite'));
            if (! is_file($dbFile) || ! hash_equals((string) ($manifest['db_sha256'] ?? ''), hash_file('sha256', $dbFile))
                || ! hash_equals((string) ($manifest['control_sha256'] ?? ''), hash('sha256', $controlRaw))) {
                throw new BackupException('The database dump or control.json does not match the manifest.', 'extract');
            }

            $stage = 'safety';
            if ($this->sameDatabase($live, $verifyConn)) {
                throw new BackupException('The verify database must not be the live database.', 'safety');
            }
            $dumper = app(DatabaseDumper::class);
            if ($dumper->driver() !== ($manifest['db_driver'] ?? '')) {
                throw new BackupException("The archive holds a {$manifest['db_driver']} dump; this server runs {$dumper->driver()}.", 'safety');
            }

            $stage = 'load';
            $say('info', 'Loading the dump into the verify database.');
            $wiped = true;
            $dumper->wipe($verifyConn);
            $dumper->load($dbFile, $verifyConn);

            $disks = $this->switchTo($verifyConn, $dir);
            $maxIds = (array) ($manifest['max_ids'] ?? []);

            $stage = 'counts';
            $counts = ControlTotals::counts($maxIds);
            foreach ((array) ($manifest['counts'] ?? []) as $table => $n) {
                if ((int) ($counts[$table] ?? -1) !== (int) $n) {
                    throw new BackupException("Row count of {$table}: manifest {$n}, restored ".($counts[$table] ?? 'none').'.', 'counts');
                }
            }

            $stage = 'control';
            $diff = ControlTotals::differences($control, ControlTotals::compute($maxIds));
            if ($diff) {
                throw new BackupException('control.json differs from the restored ledger: '.implode(' ', array_slice($diff, 0, 5)), 'control');
            }

            foreach (['ledger' => 'ledger:verify', 'attachments' => 'attachments:verify'] as $s => $command) {
                $stage = $s;
                $say('info', "Running {$command} on the restored copy.");
                [$code, $first] = $this->call($command);
                if ($code !== 0) {
                    throw new BackupException("{$command} failed: {$first}", $s);
                }
            }

            $this->restore($live, $disks);
            $disks = null;
            $seconds = (int) round(microtime(true) - $started);
            $backup?->update(['verify_status' => 'passed', 'verified_at' => now(), 'verify_error' => null]);
            AuditLog::record('backup.verified', $backup, null, ['filename' => $filename, 'duration_seconds' => $seconds, 'counts' => $counts], null, $client);
            $say('info', "Restore test of {$filename} passed ({$seconds} s).");

            return true;
        } catch (Throwable $e) {
            if ($disks !== null) {
                $this->restore($live, $disks);
                $disks = null;
            }
            if (! $e instanceof BackupException) {
                report($e);
            }
            $stage = $e instanceof BackupException ? $e->stage : $stage;
            $msg = mb_substr(trim(strtok($e->getMessage(), "\n") ?: $e::class), 0, 1000);
            $backup?->update(['verify_status' => 'failed', 'verified_at' => now(), 'verify_error' => $msg]);
            AuditLog::record('backup.verify_failed', $backup, null, ['filename' => $filename, 'stage' => $stage, 'error' => $msg], null, $client);
            $say('error', "Restore test of {$filename} FAILED at {$stage}: {$msg}");
            if (! BackupAlerts::send(new BackupAlertNotification('verify', $stage, $filename, BusinessDate::now()->format('Y-m-d H:i'), $msg))) {
                $say('error', 'The alert email could not be sent; see the log.');
            }

            return false;
        } finally {
            if ($disks !== null) {
                $this->restore($live, $disks);
            }
            if ($wiped && $dumper) {
                try {
                    $dumper->wipe($verifyConn);
                } catch (Throwable $e) {
                    report($e);
                }
            }
            DB::purge($verifyConn);
            Workspace::remove($tmp);
        }
    }

    /** @return array{0: string, 1: ?string} local path of the archive, expected sha256 */
    private function fetch(string $filename, ?Backup $backup, string $tmp, ?string $key): array
    {
        $local = Storage::disk((string) config('backup.local_disk'));
        if ($key === null && $local->exists($filename)) {
            $sidecar = $local->exists($filename.'.sha256') && preg_match('/^([0-9a-f]{64})/', (string) $local->get($filename.'.sha256'), $m) ? $m[1] : null;

            return [$local->path($filename), $backup?->sha256 ?? $sidecar];
        }
        if (! $this->offsite->configured()) {
            throw new BackupException('The archive is not on this server and no off-server disk is configured.', 'fetch');
        }
        $key ??= $this->offsite->find($filename);
        if ($key === null) {
            throw new BackupException('The archive is not on this server or off-server.', 'fetch');
        }
        $to = $tmp.'/'.$filename;
        $this->offsite->download($key, $to);

        return [$to, $backup?->sha256 ?? $this->offsite->sidecarSha($key)];
    }

    /** R11 step 3: same host + port + database (MySQL), or the same file (SQLite). */
    public function sameDatabase(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $ca = (array) config("database.connections.{$a}");
        $cb = (array) config("database.connections.{$b}");
        if (($ca['driver'] ?? '') !== ($cb['driver'] ?? '')) {
            return false;
        }
        if (($ca['driver'] ?? '') === 'sqlite') {
            $fa = (string) ($ca['database'] ?? '');
            $fb = (string) ($cb['database'] ?? '');

            return (realpath($fa) ?: $fa) === (realpath($fb) ?: $fb);
        }
        $host = fn (array $c) => in_array($h = strtolower((string) ($c['host'] ?? '')), ['localhost', '::1'], true) ? '127.0.0.1' : $h;

        return $host($ca) === $host($cb) && (int) ($ca['port'] ?? 3306) === (int) ($cb['port'] ?? 3306)
            && strtolower((string) ($ca['database'] ?? '')) === strtolower((string) ($cb['database'] ?? ''));
    }

    /** R11 step 5: the rest of this process reads the restored copy. Returns what restore() needs. */
    private function switchTo(string $connection, string $dir): array
    {
        $roots = [];
        foreach (self::SWITCHED_DISKS as $disk) {
            $roots[$disk] = config("filesystems.disks.{$disk}.root");
            $path = "{$dir}/files/{$disk}";
            if (! is_dir($path)) {
                mkdir($path, 0700, true);
            }
            config(["filesystems.disks.{$disk}.root" => $path]);
        }
        Storage::forgetDisk(self::SWITCHED_DISKS);
        DB::setDefaultConnection($connection);
        app()->forgetScopedInstances();

        return $roots;
    }

    private function restore(string $live, array $roots): void
    {
        DB::setDefaultConnection($live);
        foreach ($roots as $disk => $root) {
            config(["filesystems.disks.{$disk}.root" => $root]);
        }
        Storage::forgetDisk(array_keys($roots));
        app()->forgetScopedInstances();
    }

    /** @return array{0: int, 1: string} exit code and the first problem line */
    private function call(string $command): array
    {
        $out = new class extends BufferedOutput
        {
            public array $errors = [];

            public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
            {
                foreach (is_iterable($messages) ? $messages : [$messages] as $m) {
                    if (str_starts_with((string) $m, '<error>')) {
                        $this->errors[] = trim(strip_tags((string) $m));
                    }
                }
                parent::write($messages, $newline, $options);
            }
        };
        $code = Artisan::call($command, [], $out);
        $text = $out->fetch();
        $first = $out->errors[0] ?? 'see the command output';
        // attachments:verify prints the offending files in a table before its summary line.
        if (preg_match('/^\|(.*(?:sha256 mismatch|file missing|cannot read file).*)\|\s*$/m', $text, $m)) {
            $first .= ' ('.preg_replace('/\s*\|\s*/', ' | ', trim($m[1])).')';
        }

        return [$code, $first];
    }
}
