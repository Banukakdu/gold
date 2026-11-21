<?php

use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-088 follow-up (docs/specs/scheduled-reports.md R15, accepted risk 3): when every delivery of a built run is final
 * the run is "settled" once, with its delivery outcome. A run whose emails all failed then counts for the automatic
 * pause (R10). Posts nothing.
 *  - report_schedule_runs: `settled_at`, `delivery_outcome`; both frozen once set by the runs update trigger, which is
 *    replaced here (the 2026_12_03 trigger plus the two columns) and restored by down();
 *  - report_schedules: `resumed_at` (the R15 streak boundary) and `last_failure_planned_at` (R9: a late `sent` of an
 *    older run does not reset a later counted failure);
 *  - existing built runs whose deliveries are already final are marked settled (no outcome), so the dispatcher sweep
 *    never counts old runs; runs that still have a queued or sending delivery settle normally later;
 *  - `resumed_at` comes from the latest `report_schedule.resumed` audit row, `last_failure_planned_at` from the latest
 *    failed or missed run of a schedule whose streak is not 0.
 * Every step has its own guard: MySQL DDL is not transactional, so a run stopped half-way can be resumed. Run it while
 * the exports and mail workers are stopped or at a quiet time (the backfill locks the runs it updates).
 */
return new class extends Migration
{
    /** The 2026_12_03 frozen run columns (that migration's RUN_FROZEN). */
    private const RUN_FROZEN = ['id', 'schedule_id', 'planned_at', 'period_from', 'period_to', 'report', 'params', 'branch_ids'];

    private const INDEX = 'rs_runs_status_settled_index';

    public function up(): void
    {
        MigrationPreflight::assertCanCreateTriggers();

        if (! Schema::hasColumn('report_schedule_runs', 'settled_at')) {
            Schema::table('report_schedule_runs', fn (Blueprint $t) => $t->timestamp('settled_at')->nullable());
        }
        if (! Schema::hasColumn('report_schedule_runs', 'delivery_outcome')) {
            // delivered | not_delivered | nothing_sent
            Schema::table('report_schedule_runs', fn (Blueprint $t) => $t->string('delivery_outcome', 20)->nullable());
        }
        if (! Schema::hasIndex('report_schedule_runs', self::INDEX)) {
            Schema::table('report_schedule_runs', fn (Blueprint $t) => $t->index(['status', 'settled_at'], self::INDEX));
        }
        if (! Schema::hasColumn('report_schedules', 'resumed_at')) {
            Schema::table('report_schedules', fn (Blueprint $t) => $t->timestamp('resumed_at')->nullable());
        }
        if (! Schema::hasColumn('report_schedules', 'last_failure_planned_at')) {
            Schema::table('report_schedules', fn (Blueprint $t) => $t->timestamp('last_failure_planned_at')->nullable());
        }

        // Backfill before the new trigger (it only refuses a change of a value already set).
        DB::table('report_schedule_runs')->where('status', 'done')->whereNull('settled_at')
            ->whereNotExists(fn ($q) => $q->from('report_schedule_deliveries as d')->whereColumn('d.run_id', 'report_schedule_runs.id')
                ->whereIn('d.status', ['queued', 'sending']))
            ->update(['settled_at' => DB::raw('COALESCE(finished_at, updated_at, created_at)')]);
        DB::update('UPDATE report_schedules SET resumed_at = (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.event = ? '
            .'AND a.auditable_type = ? AND a.auditable_id = report_schedules.id) WHERE resumed_at IS NULL',
            ['report_schedule.resumed', 'App\\Models\\ReportSchedule']);
        DB::update('UPDATE report_schedules SET last_failure_planned_at = (SELECT MAX(r.planned_at) FROM report_schedule_runs r '
            ."WHERE r.schedule_id = report_schedules.id AND r.status IN ('failed', 'missed')) "
            .'WHERE last_failure_planned_at IS NULL AND consecutive_failures > 0');

        $this->runTrigger(true);
    }

    public function down(): void
    {
        // The old trigger first: SQLite refuses to drop a column a trigger names.
        $this->runTrigger(false);
        if (Schema::hasIndex('report_schedule_runs', self::INDEX)) {
            Schema::table('report_schedule_runs', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
        }
        foreach (['report_schedule_runs' => ['settled_at', 'delivery_outcome'], 'report_schedules' => ['resumed_at', 'last_failure_planned_at']] as $table => $cols) {
            foreach ($cols as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }

    /** The runs update trigger: the 2026_12_03 one, plus (when $settled) settled_at and delivery_outcome once set. */
    private function runTrigger(bool $settled): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $once = fn (string $c) => $mysql ? "(OLD.{$c} IS NOT NULL AND NOT (NEW.{$c} <=> OLD.{$c}))" : "(OLD.{$c} IS NOT NULL AND NEW.{$c} IS NOT OLD.{$c})";
        $refuse = array_map(fn ($c) => $mysql ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}", self::RUN_FROZEN);
        $refuse[] = $once('sha256');
        $message = 'report_schedule_runs rows are evidence (the period, filters and a set sha256 cannot change).';
        if ($settled) {
            $refuse[] = $once('settled_at');
            $refuse[] = $once('delivery_outcome');
            $message = 'report_schedule_runs rows are evidence (the period, filters, a set sha256 and a settled outcome cannot change).';
        }
        $refuse = implode(' OR ', $refuse);

        DB::unprepared('DROP TRIGGER IF EXISTS report_schedule_runs_no_update');
        DB::unprepared($mysql
            ? "CREATE TRIGGER report_schedule_runs_no_update BEFORE UPDATE ON report_schedule_runs FOR EACH ROW
                BEGIN
                    IF {$refuse} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END"
            : "CREATE TRIGGER report_schedule_runs_no_update BEFORE UPDATE ON report_schedule_runs WHEN {$refuse}
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
    }
};
