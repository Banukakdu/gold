<?php

use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-087 background exports (docs/specs/background-exports.md section 5 "Table exports", R15, accounting review
 * finding 11).
 *  - rows are never deleted: only the file goes when it expires (exports:prune);
 *  - BEFORE DELETE / BEFORE UPDATE triggers (MySQL + SQLite): a DELETE is refused, and so is a change to the columns
 *    that tie a file to its request, or to `sha256` once it is set;
 *  - each foreign key has an index of its own (MySQL error 1553 otherwise when a composite index changes).
 */
return new class extends Migration
{
    /** Frozen once the row exists. */
    public const FROZEN_COLUMNS = ['id', 'user_id', 'branch_id', 'kind', 'name', 'format', 'params', 'branch_ids', 'requested_at'];

    public function up(): void
    {
        // MySQL DDL is not transactional: refuse to start rather than leave a table without its triggers.
        MigrationPreflight::assertCanCreateTriggers();

        Schema::create('exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // The active branch at request; null = Super Admin "All branches" mode.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('kind', 20); // report | transactions
            $table->string('name', 40);
            $table->string('format', 10);
            $table->json('params');
            $table->json('branch_ids');
            $table->unsignedInteger('rows');
            $table->string('status', 10); // queued | running | ready | failed | expired
            $table->string('error')->nullable();
            $table->string('failure_code', 20)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('data_as_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index('user_id', 'exports_user_id_fk');
            $table->index('branch_id', 'exports_branch_id_fk');
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        $driver = DB::connection()->getDriverName();
        $message = 'exports rows are evidence';

        DB::unprepared(match ($driver) {
            'mysql' => "CREATE TRIGGER exports_no_delete BEFORE DELETE ON exports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message} (DELETE refused).'",
            default => "CREATE TRIGGER exports_no_delete BEFORE DELETE ON exports BEGIN SELECT RAISE(ABORT, '{$message} (DELETE refused).'); END",
        });

        $changed = array_map(fn ($c) => $driver === 'mysql' ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}", self::FROZEN_COLUMNS);
        $sha = $driver === 'mysql' ? '(OLD.sha256 IS NOT NULL AND NOT (NEW.sha256 <=> OLD.sha256))' : '(OLD.sha256 IS NOT NULL AND NEW.sha256 IS NOT OLD.sha256)';
        $refuse = implode(' OR ', [...$changed, $sha]);
        $updateMessage = "{$message} (the request columns and a set sha256 cannot change).";

        DB::unprepared(match ($driver) {
            'mysql' => "CREATE TRIGGER exports_no_update BEFORE UPDATE ON exports FOR EACH ROW
                BEGIN
                    IF {$refuse} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$updateMessage}';
                    END IF;
                END",
            default => "CREATE TRIGGER exports_no_update BEFORE UPDATE ON exports WHEN {$refuse}
                BEGIN SELECT RAISE(ABORT, '{$updateMessage}'); END",
        });
    }

    public function down(): void
    {
        // Rows tie files and audit rows to their request: rolling back is refused once any export exists.
        if (Schema::hasTable('exports') && DB::table('exports')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: the exports table holds export records, and '
                .'rolling back would drop them. Restore a backup taken before this migration instead.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS exports_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS exports_no_delete');
        Schema::dropIfExists('exports');
    }
};
