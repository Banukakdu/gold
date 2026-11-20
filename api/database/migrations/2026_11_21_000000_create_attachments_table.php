<?php

use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-062 entry attachments (docs/specs/attachments.md section 5 + accounting review findings 1 and 4).
 *  - every foreign key restricts deletes (transaction, branch, uploader, remover);
 *  - `added_after` records, at upload, whether the file was added after the month was closed or the entry voided/reversed;
 *  - BEFORE DELETE / BEFORE UPDATE triggers (MySQL + SQLite): rows are never deleted, and the only allowed update is
 *    the one-time removal (removed_at, removed_by, removal_reason, updated_at).
 */
return new class extends Migration
{
    /** Columns the removal may set; every other column is frozen once the row exists. */
    public const REMOVAL_COLUMNS = ['removed_at', 'removed_by', 'removal_reason', 'updated_at'];

    public const FROZEN_COLUMNS = ['id', 'branch_id', 'transaction_id', 'disk', 'path', 'original_name', 'mime', 'size_bytes',
        'sha256', 'added_after', 'uploaded_by', 'created_at'];

    public function up(): void
    {
        // MySQL DDL is not transactional: refuse to start rather than leave a table without its triggers.
        MigrationPreflight::assertCanCreateTriggers();

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('disk', 20);
            $table->string('path')->unique();
            $table->string('original_name', 150);
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('added_after', 10)->nullable(); // close | void | reversal (finding 4)
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('removal_reason')->nullable();

            // Each foreign key keeps an index of its own on MySQL, so a later change to the composite indexes
            // below can never fail with error 1553 (see the controls migration).
            $table->index('branch_id', 'attachments_branch_id_fk');
            $table->index('transaction_id', 'attachments_transaction_id_fk');
            $table->index(['transaction_id', 'removed_at']);
            // Duplicate checks (R3 same entry, finding 7 same branch).
            $table->index(['branch_id', 'sha256']);
        });

        $driver = DB::connection()->getDriverName();
        $message = 'attachments rows are immutable';

        DB::unprepared(match ($driver) {
            'mysql' => "CREATE TRIGGER attachments_no_delete BEFORE DELETE ON attachments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message} (DELETE refused).'",
            default => "CREATE TRIGGER attachments_no_delete BEFORE DELETE ON attachments BEGIN SELECT RAISE(ABORT, '{$message} (DELETE refused).'); END",
        });

        // Refused: a second update after removal, an update that is not a removal, or a change to any frozen column.
        $changed = array_map(fn ($c) => $driver === 'mysql' ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}", self::FROZEN_COLUMNS);
        $refuse = 'OLD.removed_at IS NOT NULL OR NEW.removed_at IS NULL OR NEW.removed_by IS NULL OR '.implode(' OR ', $changed);
        $updateMessage = "{$message} (only a one-time removal may change removed_at, removed_by and removal_reason).";

        DB::unprepared(match ($driver) {
            'mysql' => "CREATE TRIGGER attachments_no_update BEFORE UPDATE ON attachments FOR EACH ROW
                BEGIN
                    IF {$refuse} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$updateMessage}';
                    END IF;
                END",
            default => "CREATE TRIGGER attachments_no_update BEFORE UPDATE ON attachments WHEN {$refuse}
                BEGIN SELECT RAISE(ABORT, '{$updateMessage}'); END",
        });
    }

    public function down(): void
    {
        // Rows are evidence: rolling back is refused once any attachment exists (files on disk would lose their record).
        if (DB::table('attachments')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: the attachments table holds evidence rows, and '
                .'rolling back would drop them. Restore a backup taken before this migration instead.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS attachments_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS attachments_no_delete');
        Schema::dropIfExists('attachments');
    }
};
