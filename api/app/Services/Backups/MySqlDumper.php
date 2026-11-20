<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * R2 MySQL: `mysqldump --single-transaction ...` through Symfony Process (no shell). Credentials go in a temporary
 * `--defaults-extra-file` (0600, deleted after), never on the command line where `ps` shows them. DEFINER clauses
 * are stripped so triggers restore under any user. Loading uses the `mysql` client the same way (R11).
 */
class MySqlDumper implements DatabaseDumper
{
    public const DUMP_OPTIONS = ['--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob',
        '--no-tablespaces', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4'];

    public function driver(): string
    {
        return 'mysql';
    }

    public function entryName(): string
    {
        return 'db.sql';
    }

    public function nonTransactionalTables(): array
    {
        return array_map(fn ($r) => (string) $r->name, DB::select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             AND (ENGINE IS NULL OR ENGINE <> 'InnoDB') ORDER BY TABLE_NAME"));
    }

    /** The mysqldump argument list (AC 12). --defaults-extra-file must come first. No password in it. */
    public function dumpArguments(string $defaultsFile, string $database): array
    {
        return [(string) config('backup.mysqldump_path'), '--defaults-extra-file='.$defaultsFile, ...self::DUMP_OPTIONS, $database];
    }

    /**
     * Review M3: mysqldump writes to a pipe and each line has its DEFINER stripped on the way to $path, so the disk
     * holds one copy of the dump, not two. proc_open with an argument array runs no shell. stderr goes to a file in
     * the same 0700 workspace so a chatty stderr cannot block the pipe.
     */
    public function dump(string $path): void
    {
        $cfg = DB::connection()->getConfig();
        $dir = dirname($path);
        $defaults = self::writeDefaultsFile($dir, $cfg);
        $errFile = $dir.'/mysqldump-'.bin2hex(random_bytes(6)).'.err';
        $out = null;
        try {
            $proc = proc_open($this->dumpArguments($defaults, (string) $cfg['database']),
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
            if (! is_resource($proc)) {
                throw new BackupException('mysqldump could not be started ('.config('backup.mysqldump_path').').', 'dump');
            }
            $out = fopen($path, 'wb');
            $bytes = 0;
            $writeFailed = false;
            while (($line = fgets($pipes[1])) !== false) {
                $line = self::stripDefiners($line);
                if (fwrite($out, $line) !== strlen($line)) {
                    $writeFailed = true;
                    break;
                }
                $bytes += strlen($line);
            }
            fclose($pipes[1]);
            $code = proc_close($proc);
            fclose($out);
            $out = null;
            if ($writeFailed) {
                throw new BackupException('Could not write the dump to the temp folder (disk full?).', 'dump');
            }
            if ($code !== 0 || $bytes === 0) {
                $why = trim((string) @file_get_contents($errFile, false, null, 0, 4000)) ?: 'no output';
                throw new BackupException("mysqldump failed (exit {$code}): ".mb_substr($why, 0, 500), 'dump');
            }
        } finally {
            is_resource($out) && fclose($out);
            @unlink($defaults);
            @unlink($errFile);
        }
    }

    public function wipe(string $connection): void
    {
        Schema::connection($connection)->dropAllViews();
        Schema::connection($connection)->dropAllTables();
        DB::purge($connection);
    }

    public function load(string $path, string $connection): void
    {
        $cfg = config("database.connections.{$connection}");
        $defaults = self::writeDefaultsFile(dirname($path), $cfg);
        $in = fopen($path, 'rb');
        try {
            $p = new Process([(string) config('backup.mysql_path'), '--defaults-extra-file='.$defaults, '--default-character-set=utf8mb4',
                (string) $cfg['database']]);
            $p->setTimeout(null);
            $p->setInput($in);
            $p->run();
            if (! $p->isSuccessful()) {
                throw new BackupException('mysql client failed (exit '.$p->getExitCode().'): '.mb_substr(trim($p->getErrorOutput()), 0, 500), 'load');
            }
        } finally {
            is_resource($in) && fclose($in);
            @unlink($defaults);
        }
        DB::purge($connection);
    }

    /** A `[client]` option file with the connection's credentials, mode 0600, in $dir (itself 0700). */
    public static function writeDefaultsFile(string $dir, #[\SensitiveParameter] array $cfg): string
    {
        $q = fn ($v) => '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ''], (string) $v).'"';
        $lines = ['[client]', 'user='.$q($cfg['username'] ?? ''), 'password='.$q($cfg['password'] ?? '')];
        if (! empty($cfg['unix_socket'])) {
            $lines[] = 'socket='.$q($cfg['unix_socket']);
        } else {
            $lines[] = 'host='.$q($cfg['host'] ?? '127.0.0.1');
            $lines[] = 'port='.(int) ($cfg['port'] ?? 3306);
        }
        $file = $dir.'/my-'.bin2hex(random_bytes(8)).'.cnf';
        touch($file);
        chmod($file, 0600);
        file_put_contents($file, implode("\n", $lines)."\n");

        return $file;
    }

    /** AC 13: removes `/*!50017 DEFINER=`u`@`h`*\/` and ` DEFINER=`u`@`h`` from one line of DDL (never from data rows). */
    public static function stripDefiners(string $line): string
    {
        if (str_starts_with($line, 'INSERT INTO ') || ! str_contains($line, 'DEFINER=')) {
            return $line;
        }
        $id = '`(?:[^`]|``)*`@`(?:[^`]|``)*`';

        return preg_replace(["#/\\*!50017 DEFINER={$id}\\s*\\*/ ?#", "# DEFINER={$id}#", '# DEFINER=CURRENT_USER(?:\(\))?#'], '', $line) ?? $line;
    }
}
