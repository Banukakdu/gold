<?php

namespace Tests\Feature\Attachments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** The attachments migration refuses to roll back once evidence rows exist (they cannot be deleted either). */
class MigrationTest extends AttachmentsTestCase
{
    public function test_rollback_is_refused_while_evidence_rows_exist(): void
    {
        $migration = require database_path('migrations/2026_11_21_000000_create_attachments_table.php');
        $this->attach('r1.jpg');
        try {
            $migration->down();
            $this->fail('rollback did not stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('attachments'));
        $this->assertSame(1, DB::table('attachments')->count());
    }
}
