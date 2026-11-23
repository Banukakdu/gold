<?php

namespace Tests\Feature\BankReconciliation;

use App\Services\ReportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AG-033c AC 14 (R6): with AG-033b's `attachments.bank_statement_id` (PR #25) the copy column and filter switch on.
 * Works before and after AG-033b is merged:
 *  - column missing (main): the test adds a plain nullable column for itself and removes it again. SQLite: inside the
 *    test transaction (rolled back with it). MySQL commits DDL: added before the test transaction, dropped after its
 *    rollback; the drop is registered right after the column is added, so a failing setUp still removes it;
 *  - column there (AG-033b migrated, with its one-parent trigger and foreign key): nothing is added or dropped, and the
 *    files are statement files only (transaction_id null).
 */
class StatementListCopiesTest extends StatementListTestCase
{
    /** The test added the column (so it must remove it); false when AG-033b's migration provides it. */
    private bool $added = false;

    /** @var (\Closure(): void)|null */
    private ?\Closure $drop = null;

    private static function mysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    private function addColumnIfMissing(): void
    {
        if (! Schema::hasColumn('attachments', 'bank_statement_id')) {
            Schema::table('attachments', fn ($t) => $t->unsignedBigInteger('bank_statement_id')->nullable());
            $this->added = true;
        }
        ReportService::forgetSchemaCache();
    }

    protected function beforeRefreshingDatabase()
    {
        parent::beforeRefreshingDatabase();
        if (self::mysql()) {
            if (! RefreshDatabaseState::$migrated) {
                $this->artisan('migrate:fresh', $this->migrateFreshUsing());
                $this->app[Kernel::class]->setArtisan(null);
                RefreshDatabaseState::$migrated = true;
            }
            $this->addColumnIfMissing();
            if ($this->added) {
                // Registered now (SHOULD 2), moved behind the test-transaction rollback in setUp() below. If setUp fails
                // before that, the drop runs first: its DDL commits the open test transaction, RefreshDatabase then
                // finds no transaction and migrates fresh for the next test, so nothing is left behind either way.
                $this->drop = function () {
                    if ($this->added && Schema::hasColumn('attachments', 'bank_statement_id')) {
                        Schema::table('attachments', fn ($t) => $t->dropColumn('bank_statement_id'));
                    }
                    $this->added = false;
                    ReportService::forgetSchemaCache();
                };
                $this->beforeApplicationDestroyed($this->drop);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->drop) {
            // After the RefreshDatabase rollback (callbacks run in the order they were registered).
            $this->beforeApplicationDestroyedCallbacks = array_values(array_filter($this->beforeApplicationDestroyedCallbacks, fn ($c) => $c !== $this->drop));
            $this->beforeApplicationDestroyed($this->drop);
        }
        if (! self::mysql()) {
            $this->addColumnIfMissing();
        }
        $this->beforeApplicationDestroyed(fn () => ReportService::forgetSchemaCache());
        $this->scenario();
    }

    private function file(int $statementId, bool $removed = false): void
    {
        // Before AG-033b transaction_id is NOT NULL; after it, a statement file has no transaction (one parent).
        DB::table('attachments')->insert(['branch_id' => $this->kdy->id, 'transaction_id' => $this->added ? $this->top->id : null, 'bank_statement_id' => $statementId,
            'disk' => 'local', 'path' => 'statements/'.Str::random(20).'.pdf', 'original_name' => 'October.pdf', 'mime' => 'application/pdf',
            'size_bytes' => 1000, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $this->acc->id, 'created_at' => now(), 'updated_at' => now(),
            'removed_at' => $removed ? now() : null, 'removed_by' => $removed ? $this->acc->id : null, 'removal_reason' => $removed ? 'Wrong month uploaded' : null]);
    }

    public function test_ac14_copy_column_and_filter(): void
    {
        $this->file($this->st1->id);
        $this->file($this->st1->id, removed: true);

        $r = $this->list()->assertOk()->assertJsonPath('copies_supported', true);
        $this->assertSame([1, 1], [$this->row($r, $this->st1)['attachments_count'], $this->row($r, $this->st1)['attachments_removed_count']]);
        $this->assertSame([0, 0], [$this->row($r, $this->st3)['attachments_count'], $this->row($r, $this->st3)['attachments_removed_count']]);
        $this->assertSame([0, 0], [$this->row($r, $this->st2)['attachments_count'], $this->row($r, $this->st2)['attachments_removed_count']]);

        $this->assertSame([$this->st3->id], $this->ids($this->list(['copy' => 'none'])));
        $this->assertSame([$this->st1->id], $this->ids($this->list(['copy' => 'attached'])));
        $this->assertSame([$this->st3->id, $this->st2->id, $this->st1->id], $this->ids($this->list(['copy' => 'any'])));
        $this->list(['copy' => 'some'])->assertStatus(422)->assertJsonValidationErrors('copy');

        // Exports: the column and the filter line.
        $text = implode(' ', $this->pdfPages($this->exportList([], 'pdf')->assertOk()->getContent()));
        foreach (['Bank statement copy: All', '1 file (1 removed)', 'No copy'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $grid = $this->xlsx($this->exportList(['copy' => 'none'], 'xlsx')->assertOk())->getSheet(0)->toArray(null, false, false);
        $lines = array_map(fn ($row) => (string) $row[0], $grid);
        $head = array_search('Branch', $lines, true);
        $copy = array_search('Bank statement copy', $grid[$head], true);
        $this->assertNotFalse($copy);
        $this->assertSame('No copy', $grid[$head + 1][$copy]);
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_contains($l, 'Bank statement copy: None')));
    }
}
