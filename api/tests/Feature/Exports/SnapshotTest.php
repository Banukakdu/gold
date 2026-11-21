<?php

namespace Tests\Feature\Exports;

use App\Jobs\RunExport;
use App\Models\AuditLog;
use App\Models\Export;
use App\Models\Transaction;
use App\Notifications\ExportFailedNotification;
use App\Services\LedgerService;
use App\Support\ReadSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * docs/specs/background-exports.md ACs 7 and 37 (R3, accounting review findings 1, 2 and 12) against a real snapshot:
 * no test transaction around the test (a snapshot cannot open inside one), committed data, and a second connection
 * (`writer`) that posts while the export reads. SQLite runs on a WAL file database so a reader and a writer can work
 * at the same time; MySQL runs on the suite's database, which is migrated fresh again afterwards.
 */
class SnapshotTest extends ExportsTestCase
{
    private ?string $file = null;

    public function refreshDatabase()
    {
        $name = config('database.default');
        $driver = config("database.connections.{$name}.driver");
        if ($driver === 'sqlite') {
            $this->file = sys_get_temp_dir().'/ag-snapshot-'.getmypid().'.sqlite';
            $this->removeFile();
            touch($this->file);
            config(["database.connections.{$name}.database" => $this->file, "database.connections.{$name}.journal_mode" => 'wal',
                "database.connections.{$name}.busy_timeout" => 5000]);
            DB::purge($name);
        }
        $this->beforeRefreshingDatabase();
        $this->artisan('migrate:fresh');
        config(['database.connections.writer' => config("database.connections.{$name}"),
            'database.connections.exports_queue' => config("database.connections.{$name}")]);
        DB::purge('exports_queue');

        $this->beforeApplicationDestroyed(function () use ($driver, $name) {
            ReadSnapshot::abandon();
            DB::purge('writer');
            DB::purge('exports_queue');
            if ($driver === 'mysql') {
                // Leave the schema empty for the tests that follow (they expect a freshly migrated database).
                $this->artisan('migrate:fresh');
            } else {
                DB::purge($name);
                $this->removeFile();
            }
        });
    }

    private function removeFile(): void
    {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            @unlink($this->file.$suffix);
        }
    }

    /** Post an income on the `writer` connection (committed there), as another user would during the build. */
    private function postElsewhere(string $amount, string $date = '2026-10-15'): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('writer');
        try {
            Auth::setUser($this->ak);
            app(LedgerService::class)->incomeOrExpense(Transaction::INCOME, ['account_id' => $this->kdyCash->id,
                'category_id' => $this->incomes[0]->category_id, 'txn_date' => $date, 'amount' => $amount, 'payee_payer' => 'Late customer']);
        } finally {
            DB::setDefaultConnection($default);
            Auth::forgetUser();
        }
    }

    private function postDuringTheBuild(string $amount): void
    {
        $done = false;
        ReadSnapshot::$hook = function (string $point) use (&$done, $amount) {
            if ($point === 'counted' && ! $done) {
                $this->assertTrue(ReadSnapshot::isOpen());
                $this->postElsewhere($amount);
                $done = true;
            }
        };
    }

    public function test_ac7_a_posting_during_the_build_is_not_in_the_file(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Finding 2: the isolation level is set for the snapshot, never taken from the session or server default.
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $this->requestEight()->assertStatus(202);
        $this->postDuringTheBuild('999.00');
        $this->work();

        $e = $this->latest();
        $this->assertSame([Export::READY, 8], [$e->status, $e->rows], (string) $e->error);
        $ws = $this->storedBook($e)->getSheet(0);
        $this->assertSame(8, self::dataRows($ws));
        $this->assertContains('Rows: 8', self::lines($ws));
        $this->assertNotContains(999.0, array_column(self::grid($ws), 2));
        // The posting is committed: the list has it now.
        $this->assertSame(9, Transaction::withoutGlobalScopes()->where('type', 'income')->whereDate('txn_date', '>=', '2026-10-01')->count());
    }

    public function test_ac7_totals_come_from_the_snapshot(): void
    {
        // Cash book: the count is the first read, the lines and their totals are read after the posting commits.
        $this->exportReport($this->ak, $this->kdy->id, 'cash-book', $this->october)->assertStatus(202);
        $this->postDuringTheBuild('999.00');
        $this->work();

        $e = $this->latest();
        $this->assertSame([Export::READY, 8], [$e->status, $e->rows], (string) $e->error);
        $values = array_column(self::grid($this->storedBook($e)->getSheet(0)), 2);
        $this->assertNotContains(999.0, $values);
        $this->assertContains(3600.0, $values, 'period receipts: the 8 incomes only');
    }

    public function test_ac7_a_void_before_the_job_starts_is_in_the_file(): void
    {
        $this->exportReport($this->ak, $this->kdy->id, 'income-expense', $this->october + ['type' => 'income'])->assertStatus(202);
        $this->travel(3)->seconds();
        Auth::setUser($this->ak);
        app(LedgerService::class)->void($this->incomes[0], 'Wrong customer');
        Auth::forgetUser();
        $voided = AuditLog::where('event', 'transaction.voided')->sole()->created_at;
        $this->travel(3)->seconds();
        $this->work();

        $e = $this->latest();
        $this->assertSame([Export::READY, 7], [$e->status, $e->rows]);
        $this->assertTrue($e->data_as_at->greaterThan($voided));
        $values = array_column(self::grid($this->storedBook($e)->getSheet(0)), 2);
        $this->assertContains(3500.0, $values, 'total income without the voided 100.00');
        $this->assertNotContains(3600.0, $values);
        $this->assertNotContains('Line 1', $values);
    }

    /**
     * Reviewer M1: the worker's timeout fails the job while the build holds the snapshot. The queue deletes its job on
     * its own connection, failed() ends the snapshot and records the timeout once; the build then cannot mark it ready.
     */
    public function test_m1_a_timeout_inside_the_snapshot_fails_the_export_at_once(): void
    {
        Notification::fake();
        $this->requestEight()->assertStatus(202);
        $id = $this->latest()->id;
        $queue = new DatabaseQueue(DB::connection('exports_queue'), 'jobs', 'exports', 900);
        $queue->setContainer($this->app);
        $queue->setConnectionName('exports');
        $queue->push(new RunExport($id));
        $job = $queue->pop('exports');
        $this->assertNotNull($job);

        ReadSnapshot::$hook = function (string $point) use ($job) {
            if ($point === 'counted') {
                $this->assertTrue(ReadSnapshot::isOpen());
                $job->fail(new TimeoutExceededException('RunExport has timed out.'));
                $this->assertFalse(ReadSnapshot::isOpen(), 'failed() ends the snapshot first');
            }
        };
        $job->fire();

        $e = Export::find($id);
        $this->assertSame([Export::FAILED, 'timeout', 'The export took too long. Narrow the filters.'], [$e->status, $e->failure_code, $e->error]);
        $this->assertTrue($job->isDeleted());
        $this->assertSame(0, DB::connection('exports_queue')->table('jobs')->count());
        $this->assertSame(1, AuditLog::where('event', 'export.failed')->count());
        $this->assertSame(0, AuditLog::where('event', 'export.ready')->count());
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());
        Notification::assertSentToTimes($this->ak, ExportFailedNotification::class, 1);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_ac37_the_synchronous_export_reads_inside_the_read_only_snapshot(): void
    {
        $refused = null;
        ReadSnapshot::$hook = function (string $point) use (&$refused) {
            if ($point !== 'counted') {
                return;
            }
            $this->assertTrue(ReadSnapshot::isOpen());
            try {
                DB::table('settings')->insert(['key' => 'snapshot_probe', 'value' => '1']);
            } catch (QueryException $e) {
                $refused = $e->getMessage();
            }
        };
        $this->exportTxn($this->ak, $this->kdy->id, $this->october + ['type' => 'income', 'min' => '400'])->assertOk();
        $this->assertNotNull($refused, 'a write inside the snapshot must fail');
        $this->assertFalse(ReadSnapshot::isOpen());
        $this->assertNull(DB::table('settings')->where('key', 'snapshot_probe')->value('value'));
        // Written after the snapshot closed.
        $this->assertSame(5, AuditLog::where('event', 'report.exported')->sole()->new_values['rows']);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }
}
