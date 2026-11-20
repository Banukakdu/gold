<?php

use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-088 scheduled email reports (docs/specs/scheduled-reports.md section 5 "Schema"). Posts nothing; ledger_entries,
 * transactions and balances are not touched.
 *  - report_schedules, report_schedule_recipients, report_schedule_runs, report_schedule_deliveries;
 *  - nothing is ever deleted: a BEFORE DELETE trigger on every table refuses (MySQL + SQLite, as `exports`);
 *  - runs: the columns that tie a run to its period and filters are frozen, and so is `sha256` once it is set;
 *  - deliveries: run_id, user_id and the email address used are frozen (accounting review A2);
 *  - every foreign key has a single-column index of its own, created before any composite or unique index that starts
 *    with the same column, so MySQL never has to use (and later drop) a composite index for the constraint (error 1553).
 * down() refuses once any schedule exists (they are evidence of what was sent), else drops the triggers and tables.
 */
return new class extends Migration
{
    public const TABLES = ['report_schedule_deliveries', 'report_schedule_runs', 'report_schedule_recipients', 'report_schedules'];

    public const RUN_FROZEN = ['id', 'schedule_id', 'planned_at', 'period_from', 'period_to', 'report', 'params', 'branch_ids'];

    public const DELIVERY_FROZEN = ['run_id', 'user_id', 'email'];

    public function up(): void
    {
        // MySQL DDL is not transactional: refuse to start rather than leave a table without its triggers.
        MigrationPreflight::assertCanCreateTriggers();

        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('report', 40);
            $t->json('params');
            $t->json('branch_ids'); // [] = All branches (including branches added later)
            $t->string('frequency', 10); // daily | weekly
            $t->unsignedTinyInteger('weekday')->nullable(); // ISO 1 = Monday .. 7 = Sunday (weekly only)
            $t->char('send_time', 5); // HH:mm Asia/Colombo, on the hour or half hour
            $t->string('status', 10); // active | paused | archived
            $t->string('paused_reason', 20)->nullable(); // manual | owner_lost_access | repeated_failures
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('next_run_at')->nullable(); // UTC, null unless active
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('archived_at')->nullable();
            $t->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('archive_reason', 500)->nullable();
            $t->timestamps();

            $t->index('owner_id', 'report_schedules_owner_id_fk');
            $t->index('created_by', 'report_schedules_created_by_fk');
            $t->index('updated_by', 'report_schedules_updated_by_fk');
            $t->index('archived_by', 'report_schedules_archived_by_fk');
            $t->index(['status', 'next_run_at']);
        });

        Schema::create('report_schedule_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->constrained('report_schedules')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('added_at');
            $t->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('removed_at')->nullable(); // null = on the list now

            $t->index('schedule_id', 'rs_recipients_schedule_id_fk');
            $t->index('user_id', 'rs_recipients_user_id_fk');
            $t->index('added_by', 'rs_recipients_added_by_fk');
            $t->index('removed_by', 'rs_recipients_removed_by_fk');
            $t->index(['schedule_id', 'removed_at'], 'rs_recipients_schedule_removed_index');
        });

        Schema::create('report_schedule_runs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('schedule_id')->constrained('report_schedules')->restrictOnDelete();
            $t->timestamp('planned_at'); // UTC
            $t->date('period_from');
            $t->date('period_to');
            $t->string('status', 10); // queued | running | done | failed | missed
            $t->string('failure_code', 20)->nullable();
            $t->string('error')->nullable(); // user-safe text only
            $t->unsignedInteger('missed_count')->nullable();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->string('report', 40);
            $t->json('params'); // the ReportService filters of this run (type / types and the requested branches)
            $t->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete(); // null = All mode
            $t->json('branch_ids'); // resolved at dispatch
            $t->unsignedInteger('rows')->nullable();
            $t->boolean('attached')->default(false);
            $t->string('file_path')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->timestamp('file_removed_at')->nullable();
            $t->timestamp('data_as_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamps();

            $t->index('schedule_id', 'rs_runs_schedule_id_fk');
            $t->index('owner_id', 'rs_runs_owner_id_fk');
            $t->index('branch_id', 'rs_runs_branch_id_fk');
            $t->unique(['schedule_id', 'planned_at'], 'rs_runs_schedule_planned_unique');
            $t->index(['status', 'created_at'], 'rs_runs_status_created_index');
            $t->index(['finished_at', 'file_path'], 'rs_runs_finished_file_index');
        });

        Schema::create('report_schedule_deliveries', function (Blueprint $t) {
            $t->id();
            $t->ulid('run_id');
            $t->foreign('run_id')->references('id')->on('report_schedule_runs')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('email'); // the address checked at build; the only address the email goes to (A2)
            $t->string('status', 10); // queued | sent | skipped | failed
            $t->string('reason', 30)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamps();

            $t->index('run_id', 'rs_deliveries_run_id_fk');
            $t->index('user_id', 'rs_deliveries_user_id_fk');
            $t->unique(['run_id', 'user_id'], 'rs_deliveries_run_user_unique');
            $t->index(['status', 'queued_at'], 'rs_deliveries_status_queued_index');
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        // Schedules, runs and deliveries are the record of what company data left the system and to whom.
        if (Schema::hasTable('report_schedules') && DB::table('report_schedules')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: report schedules exist, and rolling back would drop '
                .'them with their runs and deliveries. Restore a backup taken before this migration instead.');
        }
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createTriggers(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        foreach (self::TABLES as $table) {
            $message = "{$table} rows are evidence (DELETE refused).";
            DB::unprepared($mysql
                ? "CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'"
                : "CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }

        $changed = fn (array $cols) => array_map(fn ($c) => $mysql ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}", $cols);
        $sha = $mysql ? '(OLD.sha256 IS NOT NULL AND NOT (NEW.sha256 <=> OLD.sha256))' : '(OLD.sha256 IS NOT NULL AND NEW.sha256 IS NOT OLD.sha256)';
        $this->updateTrigger('report_schedule_runs', implode(' OR ', [...$changed(self::RUN_FROZEN), $sha]),
            'report_schedule_runs rows are evidence (the period, filters and a set sha256 cannot change).', $mysql);
        $this->updateTrigger('report_schedule_deliveries', implode(' OR ', $changed(self::DELIVERY_FROZEN)),
            'report_schedule_deliveries rows are evidence (run, recipient and email address cannot change).', $mysql);
    }

    private function updateTrigger(string $table, string $refuse, string $message, bool $mysql): void
    {
        DB::unprepared($mysql
            ? "CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                BEGIN
                    IF {$refuse} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END"
            : "CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} WHEN {$refuse}
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
    }
};
