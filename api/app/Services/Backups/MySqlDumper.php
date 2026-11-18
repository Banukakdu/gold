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
    public function dumpArguments(string $defaultsFile, string $resultFile, string $database): array
    {
        return [(string) config('backup.mysqldump_path'), '--defaults-extra-file='.$defaultsFile, ...self::DUMP_OPTIONS,
            '--result-file='.$resultFile, $database];
    }

    public function dump(string $path): void
    {
        $cfg = DB::connection()->getConfig();
        $raw = $path.'.raw';
        $defaults = self::writeDefaultsFile(dirname($path), $cfg);
        try {
            $p = new Process($this->dumpArguments($defaults, $raw, (string) $cfg['database']));
            $p->setTimeout(null);
            $p->run();
            if (! $p->isSuccessful() || ! is_file($raw) || filesize($raw) === 0) {
                $why = trim($p->getErrorOutput()) ?: 'no output';
                throw new BackupException('mysqldump failed (exit '.$p->getExitCode().'): '.mb_substr($why, 0, 500), 'dump');
            }
            self::stripDefinersFile($raw, $path);
        } finally {
            @unlink($defaults);
            @unlink($raw);
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
    public static function writeDefaultsFile(string $dir, array $cfg): string
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

    private static function stripDefinersFile(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');
        while (($line = fgets($in)) !== false) {
            fwrite($out, self::stripDefiners($line));
        }
        fclose($in);
        fclose($out);
    }
}
