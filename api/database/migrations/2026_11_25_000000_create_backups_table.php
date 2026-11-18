<?php

use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-093 automated backups (docs/specs/backups.md section 5 + accounting review F1). One row per `backup:run`.
 * Rows are never deleted (BEFORE DELETE trigger, MySQL + SQLite); updates are allowed because the status changes as
 * the run progresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: refuse to start rather than leave a table without its trigger.
        MigrationPreflight::assertCanCreateTriggers();

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 100)->unique();
            $table->string('status', 12); // running | succeeded | failed
            $table->string('triggered_by', 10); // scheduler | console
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('db_driver', 10);
            $table->boolean('encrypted')->default(false);
            $table->unsignedInteger('files_count')->nullable();
            $table->string('kept_as', 8)->nullable(); // daily | monthly | year_end
            $table->unsignedSmallInteger('fiscal_year')->nullable(); // review F1: start year of a year-end snapshot
            $table->foreignId('year_end_close_id')->nullable()->constrained('year_end_closes')->restrictOnDelete();
            $table->string('offsite_status', 16)->nullable(); // copied | failed | not_configured
            $table->text('offsite_error')->nullable();
            $table->timestamp('local_pruned_at')->nullable();
            $table->string('verify_status', 8)->nullable(); // passed | failed
            $table->timestamp('verified_at')->nullable();
            $table->text('verify_error')->nullable();
            $table->string('failed_stage', 16)->nullable();
            $table->text('error')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamps();

            // The foreign key keeps an index of its own on MySQL (see the attachments migration).
            $table->index('year_end_close_id', 'backups_year_end_close_id_fk');
            $table->index(['status', 'started_at']);
        });

        DB::unprepared(match (DB::connection()->getDriverName()) {
            'mysql' => "CREATE TRIGGER backups_no_delete BEFORE DELETE ON backups FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'backups rows are kept (DELETE refused).'",
            default => "CREATE TRIGGER backups_no_delete BEFORE DELETE ON backups BEGIN SELECT RAISE(ABORT, 'backups rows are kept (DELETE refused).'); END",
        });
    }

    public function down(): void
    {
        // The rows are the record of every backup taken; rolling back would drop them.
        if (DB::table('backups')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: the backups table holds backup records, and '
                .'rolling back would drop them.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS backups_no_delete');
        Schema::dropIfExists('backups');
    }
};
