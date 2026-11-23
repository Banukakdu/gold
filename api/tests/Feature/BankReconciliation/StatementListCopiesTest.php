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
 * The column is added here, not by a migration of this story. SQLite: inside the test transaction (rolled back with it).
 * MySQL commits DDL, so the column is added before the test transaction starts and dropped after its rollback.
 */
class StatementListCopiesTest extends StatementListTestCase
{
    private static function mysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    private static function addColumn(): void
    {
        if (! Schema::hasColumn('attachments', 'bank_statement_id')) {
            Schema::table('attachments', fn ($t) => $t->unsignedBigInteger('bank_statement_id')->nullable());
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
            self::addColumn();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::mysql()) {
            self::addColumn();
        }
        // Runs after the test transaction is rolled back (registered after it).
        $this->beforeApplicationDestroyed(function () {
            if (self::mysql() && Schema::hasColumn('attachments', 'bank_statement_id')) {
                Schema::table('attachments', fn ($t) => $t->dropColumn('bank_statement_id'));
            }
            ReportService::forgetSchemaCache();
        });
        $this->scenario();
    }

    private function file(int $statementId, bool $removed = false): void
    {
        DB::table('attachments')->insert(['branch_id' => $this->kdy->id, 'transaction_id' => $this->top->id, 'bank_statement_id' => $statementId,
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
