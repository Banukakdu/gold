<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-088 follow-up (docs/specs/scheduled-reports.md R15, accepted risk 3): when every delivery of a built run is final
 * the run is "settled" once, with its delivery outcome. A run whose emails all failed then counts for the automatic
 * pause (R10). Posts nothing.
 *
 * Existing built runs whose deliveries are already final are marked settled (no outcome) so the dispatcher sweep does
 * not count old runs afterwards. Runs that still have a queued or sending delivery settle normally later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('report_schedule_runs', 'settled_at')) {
            Schema::table('report_schedule_runs', function (Blueprint $t) {
                $t->timestamp('settled_at')->nullable(); // every delivery final; set once by a conditional UPDATE
                $t->string('delivery_outcome', 20)->nullable(); // delivered | not_delivered | nothing_sent
                $t->index(['status', 'settled_at'], 'rs_runs_status_settled_index');
            });
        }

        DB::table('report_schedule_runs')->where('status', 'done')->whereNull('settled_at')
            ->whereNotExists(fn ($q) => $q->from('report_schedule_deliveries as d')->whereColumn('d.run_id', 'report_schedule_runs.id')
                ->whereIn('d.status', ['queued', 'sending']))
            ->update(['settled_at' => DB::raw('COALESCE(finished_at, updated_at, created_at)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('report_schedule_runs', 'settled_at')) {
            Schema::table('report_schedule_runs', function (Blueprint $t) {
                $t->dropIndex('rs_runs_status_settled_index');
                $t->dropColumn(['settled_at', 'delivery_outcome']);
            });
        }
    }
};
