<?php

namespace Tests\Feature\Exports;

use App\Exceptions\ExportFailure;
use App\Jobs\RunExport;
use App\Models\AuditLog;
use App\Models\Export;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ExportFailedNotification;
use App\Services\BackgroundExports;
use App\Services\LedgerService;
use App\Services\ReportExporter;
use App\Support\ReadSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** docs/specs/background-exports.md ACs 6, 8, 10-14 and 32-33 (R2-R5, R8, R9, accounting review findings 1, 4, 5). */
class JobTest extends ExportsTestCase
{
    public function test_ac6_ready_file_equals_the_synchronous_export(): void
    {
        $this->requestEight();
        $requested = now();
        $this->travel(5)->seconds();
        $this->work();

        $e = $this->latest();
        $this->assertSame(Export::READY, $e->status);
        $this->assertNotNull($e->data_as_at);
        $this->assertTrue($e->expires_at->equalTo($e->ready_at->copy()->addHours(24)));
        $this->assertSame("{$this->ak->id}/{$e->id}.xlsx", $e->file_path);
        $disk = Storage::disk(Export::DISK);
        $this->assertTrue($disk->exists($e->file_path));
        $this->assertFalse($disk->exists($e->partPath()));
        $this->assertSame(hash_file('sha256', $disk->path($e->file_path)), $e->sha256);
        $this->assertSame(filesize($disk->path($e->file_path)), $e->size_bytes);

        $async = $this->storedBook($e)->getSheet(0);
        $lines = self::lines($async);
        $extracted = 'Data extracted '.$e->data_as_at->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i:s')
            .' (Asia/Colombo). Includes every entry, void and reversal recorded before this time. Requested '
            .$requested->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i').'.';
        $this->assertContains($extracted, $lines);
        $printed = array_search($extracted, $lines, true) - 1;
        $this->assertStringStartsWith('Printed by Anura Kumara at ', $lines[$printed]);
        $this->assertStringNotContainsString('as at', implode("\n", $lines));

        // The synchronous export of the same filters (sync_rows raised).
        config(['exports.sync_rows' => 100]);
        $sync = $this->requestEight()->assertOk();
        $syncSheet = $this->book($sync->getFile()->getPathname())->getSheet(0);
        $this->assertSame(self::lines($syncSheet), array_values(array_diff($lines, [$extracted])));
        $this->assertSame(self::grid($syncSheet), self::grid($async));
        $this->assertSame(8, self::dataRows($async));
        // Frozen below the column labels (one header line more in the queued file).
        $this->assertSame('A'.(count(self::lines($syncSheet)) + 3), $syncSheet->getFreezePane());
        $this->assertSame('A'.(count($lines) + 3), $async->getFreezePane());

        $a = AuditLog::where('event', 'export.ready')->sole();
        $this->assertSame([$this->ak->id, '10.0.0.7', 'RequestAgent/1.0', $this->kdy->id], [(int) $a->user_id, $a->ip, $a->user_agent, (int) $a->branch_id]);
        $this->assertSame(self::sorted(['export_id' => $e->id, 'name' => 'transactions', 'format' => 'xlsx', 'rows' => 8, 'params' => $e->params,
            'size_bytes' => $e->size_bytes, 'sha256' => $e->sha256, 'data_as_at' => $e->data_as_at->toIso8601String(), 'by_system' => true]), self::sorted($a->new_values));
        $this->assertSame(0, AuditLog::where('event', 'report.exported')->where('created_at', '<', now())->count());
    }

    public function test_ac6_report_file_equals_the_synchronous_export(): void
    {
        Setting::updateOrCreate(['key' => 'date_format'], ['value' => 'DD/MM/YYYY']);
        foreach ([['income-expense', $this->october + ['type' => 'all']], ['cash-book', ['from' => '2026-09-01', 'to' => '2026-09-30']],
            ['transfers', ['from' => '2026-09-01', 'to' => '2026-10-31']], ['trial-balance', ['from' => '2026-04-01', 'to' => '2026-10-31']]] as [$name, $q]) {
            config(['exports.sync_rows' => 0]);
            $this->exportReport($this->ak, $this->kdy->id, $name, $q)->assertStatus(202);
            $this->work();
            $e = $this->latest();
            $this->assertSame(Export::READY, $e->status, "{$name}: {$e->error}");
            config(['exports.sync_rows' => 100]);
            $sync = $this->book($this->exportReport($this->ak, $this->kdy->id, $name, $q)->assertOk()->getFile()->getPathname());
            $async = $this->storedBook($e);
            $this->assertSame($sync->getSheetNames(), $async->getSheetNames(), $name);
            foreach ($sync->getAllSheets() as $i => $ws) {
                $lines = self::lines($async->getSheet($i));
                $this->assertSame(self::lines($ws), array_values(array_filter($lines, fn ($l) => ! str_starts_with($l, 'Data extracted '))), $name);
                $this->assertSame(self::grid($ws), self::grid($async->getSheet($i)), "{$name} sheet {$i}");
            }
        }
    }

    public function test_ac8_the_job_keeps_the_branch_of_the_request(): void
    {
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'income'])->assertStatus(202);
        // AK switches to GAL before the job runs.
        $this->actingAs($this->ak)->withHeader('X-Branch-Id', (string) $this->gal->id)->getJson('/api/v1/transactions')->assertOk();
        $this->work();
        $ws = $this->storedBook($this->latest())->getSheet(0);
        $this->assertContains('Branches: KDY Kandy', self::lines($ws));
        $this->assertSame(8, self::dataRows($ws));
    }

    public function test_ac10_access_revoked_fails_without_retry(): void
    {
        $cases = [
            'permission' => function () {
                $this->branchRole($this->kdy, 'Accountant')->revokePermissionTo('report.export');
            },
            'removed from KDY' => function () {
                $this->ak->branches()->detach($this->kdy->id);
            },
            'deactivated' => function () {
                $this->ak->update(['status' => 'inactive']);
            },
        ];
        foreach ($cases as $label => $revoke) {
            $this->requestEight()->assertStatus(202);
            $revoke();
            $job = $this->work();
            $e = $this->latest();
            $this->assertSame([Export::FAILED, 'access_revoked', 'Your access changed after this export was requested.', 1],
                [$e->status, $e->failure_code, $e->error, $e->attempts], $label);
            $this->assertNull($e->file_path);
            $this->assertSame([], Storage::disk(Export::DISK)->allFiles(), $label);
            $job->assertFailed();
            $a = AuditLog::where('event', 'export.failed')->latest('id')->first();
            $this->assertSame(['access_revoked', 1, true], [$a->new_values['failure_code'], $a->new_values['attempts'], $a->new_values['by_system']], $label);
            // restore for the next case
            $this->branchRole($this->kdy, 'Accountant')->givePermissionTo('report.export');
            if (! $this->ak->isMemberOf($this->kdy->id)) {
                $this->ak->branches()->attach($this->kdy->id);
            }
            $this->ak->update(['status' => 'active']);
        }
    }

    public function test_ac10_demoted_super_admin_fails_in_all_mode(): void
    {
        $sa2 = User::factory()->create(['status' => 'active']);
        $sa2->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($sa2)->withHeader('X-Branch-Id', 'all')->postJson('/api/v1/transactions/export', ['type' => 'income'])->assertStatus(202);
        $this->assertNull($this->latest()->branch_id);
        $sa2->forceFill(['is_super_admin' => false])->save();
        $this->work();
        $this->assertSame('access_revoked', $this->latest()->failure_code);
    }

    public function test_ac11_recount_at_run_time(): void
    {
        $this->exportTxn($this->ak, $this->kdy->id, ['from' => '2026-09-10'])->assertStatus(202); // 8 expenses + 8 incomes = 16
        $txn = ['branch_id' => $this->kdy->id, 'type' => Transaction::INCOME, 'amount' => '1.00', 'status' => 'posted', 'account_id' => $this->kdyCash->id,
            'category_id' => $this->incomes[0]->category_id, 'created_by' => $this->ak->id, 'txn_date' => '2026-10-20', 'created_at' => now(), 'updated_at' => now()];
        foreach (range(1, 5) as $i) {
            DB::table('transactions')->insert($txn + ['ref_no' => "GROW-{$i}"]);
        }
        $this->work();
        $e = $this->latest();
        $this->assertSame([Export::FAILED, 'over_limit', 'The export now has 21 rows; the limit is 20. Narrow the filters.'], [$e->status, $e->failure_code, $e->error]);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());

    }

    public function test_ac11_shrunk_export_still_completes(): void
    {
        // Shrunk to 3 rows (5 of the 8 posted incomes voided): still completes in the background.
        $this->exportTxn($this->ak, $this->kdy->id, $this->october + ['type' => 'income', 'status' => 'posted'])->assertStatus(202);
        $ledger = app(LedgerService::class);
        Auth::setUser($this->ak);
        foreach (array_slice($this->incomes, 0, 5) as $t) {
            $ledger->void($t, 'test');
        }
        Auth::forgetUser();
        $this->assertSame(3, Transaction::where('type', 'income')->where('status', 'posted')->count());
        $this->work();
        $e = $this->latest();
        $this->assertSame([Export::READY, 3], [$e->status, $e->rows], json_encode($e->params));
        $this->assertSame(3, self::dataRows($this->storedBook($e)->getSheet(0)));
        $this->assertContains('Rows: 3', self::lines($this->storedBook($e)->getSheet(0)));
    }

    public function test_ac12_too_large(): void
    {
        config(['exports.max_bytes' => 1024]);
        $this->requestEight();
        $this->work()->assertFailed();
        $e = $this->latest();
        $this->assertSame([Export::FAILED, 'too_large', 'The file is larger than 1,024 bytes. Narrow the filters.'], [$e->status, $e->failure_code, $e->error]);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());
        $this->assertSame('The file is larger than 50 MB. Narrow the filters.', (function () {
            config(['exports.max_bytes' => 50 * 1024 * 1024]);

            return ExportFailure::tooLarge()->getMessage();
        })());
    }

    public function test_ac13_one_retry_after_an_unexpected_error(): void
    {
        Notification::fake();
        $throws = 0;
        ReadSnapshot::$hook = function (string $point) use (&$throws) {
            if ($point === 'counted' && $throws-- > 0) {
                throw new QueryException('sqlite', 'select 1', [], new \PDOException('Deadlock found when trying to get lock'));
            }
        };
        $this->requestEight()->assertStatus(202);
        $throws = 1;
        $this->work();
        $e = $this->latest();
        $this->assertSame([Export::READY, 2], [$e->status, $e->attempts]);
        $this->assertSame([], array_values(array_filter(Storage::disk(Export::DISK)->allFiles(), fn ($f) => str_ends_with($f, '.part'))));

        $throws = 0;
        $this->exportTxn($this->ak, $this->kdy->id, $this->october)->assertStatus(202);
        $throws = 2;
        $this->work();
        $e = $this->latest();
        $this->assertSame([Export::FAILED, 'error', 'The export could not be prepared. Try again later.', 2], [$e->status, $e->failure_code, $e->error, $e->attempts]);
        $this->assertStringNotContainsString('Deadlock', json_encode($this->actingAs($this->ak)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson("/api/v1/exports/{$e->id}")->assertOk()->json()));
        Notification::assertSentTo($this->ak, ExportFailedNotification::class, fn ($n) => $n->export->id === $e->id);
    }

    public function test_timeout_fails_at_once(): void
    {
        $this->requestEight();
        $e = $this->latest();
        (new RunExport($e->id))->failed(new TimeoutExceededException('timed out'));
        $e->refresh();
        $this->assertSame([Export::FAILED, 'timeout', 'The export took too long. Narrow the filters.'], [$e->status, $e->failure_code, $e->error]);
        $job = new RunExport($e->id);
        $this->assertSame([2, 60, 600, true], [$job->tries, $job->backoff, $job->timeout, $job->failOnTimeout]);
    }

    public function test_ac14_a_finished_export_is_not_built_again(): void
    {
        $this->requestEight();
        $this->work();
        $e = $this->latest();
        $before = [$e->sha256, $e->ready_at->toIso8601String(), $e->attempts, AuditLog::where('event', 'like', 'export.%')->count()];
        $this->travel(10)->minutes();
        $this->work($e->id);
        $e->refresh();
        $this->assertSame($before, [$e->sha256, $e->ready_at->toIso8601String(), $e->attempts, AuditLog::where('event', 'like', 'export.%')->count()]);
    }

    public function test_ac32_a_skipped_chunk_is_incomplete(): void
    {
        config(['exports.max_rows' => 3000]);
        $txn = ['branch_id' => $this->kdy->id, 'type' => Transaction::INCOME, 'amount' => '1.00', 'status' => 'posted', 'account_id' => $this->kdyCash->id,
            'category_id' => $this->incomes[0]->category_id, 'created_by' => $this->ak->id, 'txn_date' => '2026-10-20', 'created_at' => now(), 'updated_at' => now()];
        foreach (array_chunk(range(1, 1200), 400) as $chunk) {
            DB::table('transactions')->insert(array_map(fn ($i) => $txn + ['ref_no' => "BULK-{$i}"], $chunk));
        }
        ReadSnapshot::$hook = fn (string $point, ...$args) => $point === 'chunk' && $args[0] === 2 ? false : null;
        $this->requestEight()->assertStatus(202);
        $this->work()->assertFailed();
        $e = $this->latest();
        $this->assertSame([Export::FAILED, 'incomplete', 'The export could not be prepared. Try again later.', 1208], [$e->status, $e->failure_code, $e->error, $e->rows]);
        $this->assertNull($e->file_path);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());

        // Without the hook the same export is complete, in two chunks.
        ReadSnapshot::$hook = null;
        $this->requestEight()->assertStatus(202);
        $this->work();
        $this->assertSame(1208, self::dataRows($this->storedBook($this->latest())->getSheet(0)));
    }

    public function test_ac32_report_totals_that_do_not_foot_are_incomplete(): void
    {
        $svc = app(ReportExporter::class);
        $d = ['income' => ['total' => '10.00', 'categories' => [['amount' => '4.00'], ['amount' => '5.00']]], 'expense' => null];
        $this->assertSame(['income total'], $svc->unfooted('income-expense', $d));
        $this->assertSame(['detail rows built 7, counted 8'], BackgroundExports::incompleteness(['rows' => 8, 'built' => 7, 'sheets' => [], 'unfooted' => []]));
        $this->assertSame(['sheet 0: rows written 3, built 4'], BackgroundExports::incompleteness(['rows' => 8, 'built' => 8, 'sheets' => [['built' => 4, 'written' => 3]], 'unfooted' => []]));
    }

    public function test_ac33_files_are_private(): void
    {
        $seen = [];
        ReadSnapshot::$hook = function (string $point) use (&$seen) {
            if ($point === 'counted') {
                $seen = Storage::disk(Export::DISK)->allFiles();
            }
        };
        $this->requestEight();
        $this->work();
        $e = $this->latest();
        $path = Storage::disk(Export::DISK)->path($e->file_path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame([], $seen, 'nothing is written before the build');
        $this->assertSame('private', config('filesystems.disks.exports.visibility'));
        $this->assertFalse(config('filesystems.disks.exports.serve'));
        $this->assertStringNotContainsString(public_path(), config('filesystems.disks.exports.root'));
        $this->assertSame(0600, config('filesystems.disks.exports.permissions.file.private'));
    }

    public function test_settings_changed_after_ready_do_not_change_the_file(): void
    {
        $this->requestEight();
        $this->work();
        $e = $this->latest();
        Setting::updateOrCreate(['key' => 'company_name'], ['value' => 'Renamed Ltd']);
        $this->assertSame($e->sha256, hash_file('sha256', Storage::disk(Export::DISK)->path($e->file_path)));
    }
}
