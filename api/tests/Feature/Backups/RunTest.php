<?php

namespace Tests\Feature\Backups;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Notifications\BackupAlertNotification;
use App\Services\Backups\BackupAlerts;
use App\Services\Backups\BackupArchive;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\MySqlDumper;
use App\Support\LedgerChecks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** docs/specs/backups.md section 7 "Run" (AC 1-14), AC 33 and AC 43 (control.json). */
class RunTest extends BackupsTestCase
{
    public function test_ac1_backup_run_writes_an_encrypted_archive_and_sidecar(): void
    {
        [$code, $out] = $this->backupRun();
        $this->assertSame(0, $code, $out);
        $b = Backup::sole();
        $this->assertSame('succeeded', $b->status);
        $this->assertSame('console', $b->triggered_by);
        $this->assertTrue($b->encrypted);
        $this->assertSame(4, $b->files_count);
        $this->assertSame('monthly', $b->kept_as); // the first succeeded backup of November
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $b->sha256);
        $this->assertGreaterThan(0, $b->size_bytes);
        Storage::disk('backups')->assertExists([self::FIRST, self::FIRST.'.sha256', self::FIRST.'.manifest.json']);
        $this->assertSame($b->sha256.'  '.self::FIRST."\n", Storage::disk('backups')->get(self::FIRST.'.sha256'));
        $this->assertSame([], glob("{$this->work}/temp/*"), 'the temp folder is removed');
        Notification::assertNothingSent();
    }

    public function test_ac2_entries_need_the_password(): void
    {
        $this->backupRun();
        $z = $this->openZip($this->localPath(), null);
        $this->assertFalse($z->getFromName('db.sqlite'));
        $z->close();

        $z = $this->openZip($this->localPath());
        $this->assertNotFalse($z->getFromName('db.sqlite'));
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
            $this->assertSame(\ZipArchive::EM_AES_256, $z->statIndex($i)['encryption_method']);
        }
        $this->assertContains('manifest.json', $names);
        $this->assertContains('control.json', $names);
        $this->assertCount(3, preg_grep('#^files/attachments/#', $names));
        $this->assertSame(['files/branding/logo-abc.png'], array_values(preg_grep('#^files/branding/#', $names)));
        $this->assertSame(\ZipArchive::CM_STORE, $z->statName('files/branding/logo-abc.png')['comp_method']);
        $this->assertSame(\ZipArchive::CM_DEFLATE, $z->statName('db.sqlite')['comp_method']);
    }

    public function test_ac3_manifest_counts_and_hashes(): void
    {
        $this->backupRun();
        $z = $this->openZip($this->localPath());
        $m = json_decode($z->getFromName('manifest.json'), true);
        $this->assertSame(hash('sha256', $z->getFromName('db.sqlite')), $m['db_sha256']);
        $this->assertSame(hash('sha256', $z->getFromName('control.json')), $m['control_sha256']);
        foreach (['transactions', 'ledger_entries', 'accounts', 'attachments', 'audit_logs', 'users', 'branches'] as $t) {
            $this->assertSame(DB::table($t)->where('id', '<=', $m['max_ids'][$t])->count(), $m['counts'][$t], $t);
        }
        $this->assertSame(3, $m['attachments_count'] ?? $m['counts']['attachments']);
        $this->assertSame(3, $m['files']['attachments']['files_count']);
        $this->assertSame(1, $m['files']['branding']['files_count']);
        $this->assertSame('sqlite', $m['db_driver']);
        $this->assertNull($m['year_end']);
        $this->assertSame('2026_11_29_000000_create_notifications_table', $m['last_migration']);
        // AG-087: background export files are not part of the backup set.
        $this->assertArrayNotHasKey('exports', $m['files']);
        $this->assertSame($m['backup'], self::FIRST);
        // The plain sidecar is the same manifest.
        $this->assertEquals(self::ksorted($m), self::ksorted(json_decode(Storage::disk('backups')->get(self::FIRST.'.manifest.json'), true)));
    }

    public function test_ac4_row_sha256_is_the_archive_hash(): void
    {
        $this->backupRun();
        $this->assertSame(hash_file('sha256', $this->localPath()), Backup::sole()->sha256);
    }

    public function test_ac5_backup_created_audit_row(): void
    {
        $this->backupRun();
        $b = Backup::sole();
        $a = AuditLog::where('event', 'backup.created')->sole();
        $this->assertNull($a->user_id);
        $this->assertNull($a->branch_id);
        $this->assertSame(Backup::class, $a->auditable_type);
        $this->assertSame($b->id, $a->auditable_id);
        $this->assertSame('artisan backup:run', $a->user_agent);
        $this->assertSame($b->filename, $a->new_values['filename']);
        $this->assertSame($b->sha256, $a->new_values['sha256']);
        $this->assertSame($b->size_bytes, $a->new_values['size_bytes']);
        $this->assertSame('console', $a->new_values['triggered_by']);
    }

    public function test_ac6_file_added_after_the_dump_is_harmless(): void
    {
        $this->bindDumper(new HookedDumper(fn () => file_put_contents("{$this->work}/attachments/late.pdf", 'late')));
        [$code, $out] = $this->backupRun();
        $this->assertSame(0, $code, $out);
        $this->assertSame(5, Backup::sole()->files_count);
        $z = $this->openZip($this->localPath());
        file_put_contents("{$this->work}/dumped.sqlite", $z->getFromName('db.sqlite'));
        $paths = (new \PDO("sqlite:{$this->work}/dumped.sqlite"))->query('SELECT path FROM attachments')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(3, $paths);
        foreach ($paths as $p) {
            $this->assertNotFalse($z->locateName("files/attachments/{$p}"), $p);
        }
        $this->assertNotFalse($z->locateName('files/attachments/late.pdf'));
    }

    public function test_ac7_second_run_while_locked(): void
    {
        $lock = BackupAlerts::locks()->lock(BackupRunner::LOCK, 60);
        $this->assertTrue($lock->get());
        [$code, $out] = $this->backupRun();
        $lock->release();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('A backup is already running.', $out);
        $this->assertSame(0, Backup::count());
        Notification::assertNothingSent();
    }

    public function test_ac8_stale_running_row_is_failed(): void
    {
        $stale = Backup::create(['filename' => 'ag-backup-20261117-183000.zip', 'status' => 'running', 'triggered_by' => 'scheduler',
            'started_at' => now()->subHours(7), 'db_driver' => 'sqlite', 'encrypted' => true]);
        $this->backupRun();
        $stale->refresh();
        $this->assertSame('failed', $stale->status);
        $this->assertSame('Interrupted: the run did not finish.', $stale->error);
        $this->assertSame('interrupted', $stale->failed_stage);
        $this->assertSame(1, AuditLog::where('event', 'backup.failed')->where('auditable_id', $stale->id)->count());
        Notification::assertSentTo([$this->sa, $this->sa2], BackupAlertNotification::class);
        Notification::assertNotSentTo($this->sa3, BackupAlertNotification::class);
        $this->assertSame('succeeded', Backup::where('filename', self::FIRST)->value('status'));
    }

    public function test_ac9_production_refuses_an_empty_password(): void
    {
        config(['backup.password' => '']);
        $this->app['env'] = 'production';
        [$code] = $this->backupRun();
        $this->app['env'] = 'testing';
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('failed', $b->status);
        $this->assertSame('BACKUP_ARCHIVE_PASSWORD is not set.', $b->error);
        $this->assertSame('precheck', $b->failed_stage);
        $this->assertSame([], Storage::disk('backups')->files());
        Notification::assertSentTo([$this->sa, $this->sa2], BackupAlertNotification::class);
        Notification::assertNotSentTo($this->sa3, BackupAlertNotification::class);
    }

    public function test_ac9_local_allows_an_unencrypted_archive(): void
    {
        config(['backup.password' => '']);
        $this->app['env'] = 'local';
        [$code, $out] = $this->backupRun();
        $this->app['env'] = 'testing';
        $this->assertSame(0, $code, $out);
        $this->assertFalse(Backup::sole()->encrypted);
        $z = $this->openZip($this->localPath(), null);
        $this->assertNotFalse($z->getFromName('db.sqlite'));
    }

    public function test_short_password_is_refused(): void
    {
        config(['backup.password' => 'too-short']);
        [$code] = $this->backupRun();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('at least 20 characters', Backup::sole()->error);
    }

    public function test_ac10_memory_database_fails_precheck(): void
    {
        $this->useDatabase(':memory:');
        $this->artisan('migrate', ['--force' => true]);
        $this->backupRun();
        $b = Backup::sole();
        $this->assertSame('failed', $b->status);
        $this->assertSame('precheck', $b->failed_stage);
    }

    public function test_precheck_disk_space(): void
    {
        config(['backup.first_run_free_bytes' => PHP_INT_MAX]);
        $this->backupRun();
        $this->assertStringStartsWith('Not enough free disk space:', Backup::sole()->error);
    }

    public function test_ac11_mysqldump_failure(): void
    {
        $script = "{$this->work}/mysqldump";
        file_put_contents($script, "#!/bin/sh\necho 'mysqldump: Got error: 1045: Access denied for user' >&2\nexit 2\n");
        chmod($script, 0700);
        config(['backup.mysqldump_path' => $script]);
        $this->bindDumper(new ScriptedMySqlDumper);
        [$code] = $this->backupRun();
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('failed', $b->status);
        $this->assertStringContainsString('Access denied', $b->error);
        $this->assertSame('dump', AuditLog::where('event', 'backup.failed')->sole()->new_values['stage']);
        $this->assertSame([], glob("{$this->work}/temp/*"));
        $this->assertSame([], Storage::disk('backups')->files());
    }

    public function test_ac12_ac13_mysqldump_arguments_defaults_file_and_definers(): void
    {
        $record = "{$this->work}/args.txt";
        $script = "{$this->work}/mysqldump";
        // Records its arguments and the defaults file (mode + content), then prints a dump with DEFINER clauses to stdout.
        file_put_contents($script, <<<SH
            #!/bin/sh
            for a in "\$@"; do echo "\$a" >> {$record}; done
            for a in "\$@"; do case "\$a" in
              --defaults-extra-file=*) f="\${a#--defaults-extra-file=}"; stat -c '%a' "\$f" > {$record}.mode; cat "\$f" > {$record}.cnf;;
            esac; done
            printf '%s\\n' 'CREATE TABLE t (id int);' '/*!50003 CREATE*/ /*!50017 DEFINER=`u`@`%`*/ /*!50003 TRIGGER t_no_delete BEFORE DELETE ON t FOR EACH ROW SET @x = 1 */;;' 'CREATE DEFINER=`root`@`localhost` PROCEDURE p() BEGIN END' "INSERT INTO t VALUES ('keep DEFINER=\`a\`@\`b\` in data');"
            SH);
        chmod($script, 0700);
        config(['backup.mysqldump_path' => $script]);
        $d = new ScriptedMySqlDumper;
        DB::connection()->setDatabaseName('account_gold');
        config(['database.connections.sqlite.username' => 'gold', 'database.connections.sqlite.password' => 's3cret-pa"ss']);
        DB::purge('sqlite');
        mkdir("{$this->work}/d", 0700);
        $d->dump("{$this->work}/d/db.sql");

        $args = file($record, FILE_IGNORE_NEW_LINES);
        foreach (['--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF'] as $opt) {
            $this->assertContains($opt, $args);
        }
        $this->assertStringStartsWith('--defaults-extra-file=', $args[0]);
        foreach ($args as $a) {
            $this->assertStringNotContainsString('s3cret', $a);
        }
        $this->assertSame('600', trim(file_get_contents("{$record}.mode")));
        $this->assertStringContainsString('password="s3cret-pa\"ss"', file_get_contents("{$record}.cnf"));
        $this->assertFileDoesNotExist(substr($args[0], strlen('--defaults-extra-file=')));
        $this->assertSame(['db.sql'], array_values(array_diff(scandir("{$this->work}/d"), ['.', '..'])));

        $sql = file_get_contents("{$this->work}/d/db.sql");
        $this->assertStringContainsString('/*!50003 CREATE*/ /*!50003 TRIGGER t_no_delete', $sql);
        $this->assertStringContainsString('CREATE PROCEDURE p()', $sql);
        $this->assertSame(1, substr_count($sql, 'DEFINER='), 'only the data row keeps its text');
        $this->assertStringContainsString("'keep DEFINER=", $sql);
    }

    public function test_ac13_strip_definers_unit(): void
    {
        $this->assertSame('/*!50003 CREATE*/ /*!50003 TRIGGER x */', MySqlDumper::stripDefiners('/*!50003 CREATE*/ /*!50017 DEFINER=`u`@`%`*/ /*!50003 TRIGGER x */'));
        $this->assertSame('CREATE FUNCTION f()', MySqlDumper::stripDefiners('CREATE DEFINER=`a``b`@`h` FUNCTION f()'));
    }

    public function test_ac14_self_check_mismatch(): void
    {
        $this->app->instance(BackupArchive::class, new MismatchArchive);
        [$code] = $this->backupRun();
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('failed', $b->status);
        $this->assertSame('verify_archive', $b->failed_stage);
        $this->assertSame([], Storage::disk('backups')->files());
        $this->assertSame([], glob("{$this->work}/temp/*"));
    }

    public function test_review_f6_non_innodb_table_fails_precheck(): void
    {
        $d = new ScriptedMySqlDumper;
        $d->tables = ['legacy_notes'];
        $this->bindDumper($d);
        $this->backupRun();
        $b = Backup::sole();
        $this->assertSame('precheck', $b->failed_stage);
        $this->assertSame('Table legacy_notes is not InnoDB.', $b->error);
    }

    public function test_ac43_control_json_balances_and_matches_ledger_verify(): void
    {
        $this->backupRun();
        $z = $this->openZip($this->localPath());
        $c = json_decode($z->getFromName('control.json'), true);
        $this->assertNotEmpty($c['branches']);
        foreach ($c['branches'] as $s) {
            $this->assertSame($s['debit'], $s['credit']);
        }
        $this->assertSame($c['total']['debit'], $c['total']['credit']);
        $this->assertSame(1250050, $c['total']['debit']); // the income: Dr money 12,500.50 / Cr income
        $ledger = LedgerChecks::accountBalances();
        foreach ($c['accounts'] as $a) {
            $this->assertSame($ledger[$a['id']] ?? 0, $a['balance'], "account {$a['id']}");
        }
        $this->assertSame(Account::withoutGlobalScopes()->count(), count($c['accounts']));
        $this->assertSame(['audit_logs', 'ledger_entries', 'transactions'], array_keys(self::ksorted($c['max_ids'])));
        $this->assertSame((int) DB::table('ledger_entries')->max('id'), $c['max_ids']['ledger_entries']);
    }

    public function test_review_f2_unbalanced_books_keep_the_archive_and_alert(): void
    {
        // A one-sided line (bypassing the posting service and its trigger) makes MAIN unbalanced.
        $t = DB::table('transactions')->value('id');
        $acc = DB::table('ledger_entries')->where('transaction_id', $t)->first();
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'ledger_entries'") as $tr) {
            DB::unprepared("DROP TRIGGER {$tr->name}");
        }
        DB::table('ledger_entries')->insert(['transaction_id' => $t, 'account_id' => $acc->account_id, 'branch_id' => $acc->branch_id,
            'entry_date' => '2026-11-17', 'debit' => '1.00', 'credit' => '0.00', 'origin' => 'posting', 'created_at' => now(), 'updated_at' => now()]);
        [$code] = $this->backupRun();
        $this->assertSame(1, $code);
        $b = Backup::sole();
        $this->assertSame('succeeded', $b->status);
        $this->assertSame('control', $b->failed_stage);
        $this->assertStringContainsString('MAIN', $b->error);
        Storage::disk('backups')->assertExists(self::FIRST);
        $this->assertSame(1, AuditLog::where('event', 'backup.control_failed')->count());
        Notification::assertSentTo($this->sa, BackupAlertNotification::class, fn ($n) => $n->stage === 'control');
    }

    public function test_ac33_schedule(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => trim(preg_replace('/^.*artisan.? /', '', $e->command)));
        $run = $events['backup:run --scheduled'];
        $this->assertSame('30 1 * * *', $run->expression);
        $this->assertSame('Asia/Colombo', $run->timezone);
        $this->assertTrue($run->withoutOverlapping);
        $this->assertTrue($run->onOneServer);
        $verify = $events['backup:verify --latest'];
        $this->assertSame('0 3 * * 0', $verify->expression);
        $this->assertSame('Asia/Colombo', $verify->timezone);
        $oldest = $events['backup:verify --oldest-offsite'];
        $this->assertSame('0 4 * * 0', $oldest->expression);
        $this->assertStringContainsString('Monthly', $oldest->description);
        $this->freeze('2026-12-06 04:00:00'); // first Sunday of December
        $this->assertTrue($oldest->filtersPass($this->app));
        $this->freeze('2026-12-13 04:00:00');
        $this->assertFalse($oldest->filtersPass($this->app));

        $this->artisan('schedule:list', ['--timezone' => 'Asia/Colombo'])->expectsOutputToContain('backup:run')
            ->expectsOutputToContain('backup:verify --oldest-offsite')->assertSuccessful();
    }
}
