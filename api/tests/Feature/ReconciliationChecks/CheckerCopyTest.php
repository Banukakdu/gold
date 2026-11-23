<?php

namespace Tests\Feature\ReconciliationChecks;

use App\Services\ReportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AC 10: with AG-033b's `attachments.bank_statement_id` (PR #25) the reprint names the statement files the active
 * checker uploaded (the "uploaded by the checker" tag of the copy line). Works before and after AG-033b is merged, as
 * Tests\Feature\BankReconciliation\StatementListCopiesTest: when the column is missing the test adds a plain nullable
 * column for itself and removes it again (SQLite: inside the test transaction; MySQL: before it, dropped after its
 * rollback). Without the column (`main`), checks work and there is no copy line (RecordTest / ViewsTest AC 24).
 */
class CheckerCopyTest extends ChecksTestCase
{
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
    }

    private function file(int $statementId, int $uploadedBy): int
    {
        // Before AG-033b transaction_id is NOT NULL; after it, a statement file has no transaction (one parent).
        return DB::table('attachments')->insertGetId(['branch_id' => $this->kdy->id, 'transaction_id' => $this->added ? $this->top->id : null,
            'bank_statement_id' => $statementId, 'disk' => 'local', 'path' => 'statements/'.Str::random(20).'.pdf', 'original_name' => 'oct.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1000, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $uploadedBy,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_ac10_file_uploaded_by_the_checker_is_tagged(): void
    {
        $byBa = $this->file($this->st1->id, $this->ba->id);
        $this->file($this->st1->id, $this->acc->id);
        $this->assertNull($this->reprint($this->st1)['checker_attachment_ids']); // no active check yet

        $this->check($this->st1, $this->ba, ['note' => 'Late cheque 25 Oct agreed to chq book'])->assertCreated();
        $this->assertSame([$byBa], $this->reprint($this->st1)['checker_attachment_ids']);
        $this->assertSame([], $this->reprint($this->st3)['checker_attachment_ids'] ?? []);
    }
}
