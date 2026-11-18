<?php

use App\Support\LegacyVoids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting controls (docs/specs/controls.md section 5 + accounting review findings 2 and 13):
 *  - ledger_entries: contra_of_id (void = contra entries), transaction FK restrict;
 *  - period_locks: unlock history, one ACTIVE lock per (branch, period) enforced by the database;
 *  - audit_logs: indexes for the audit log screen;
 *  - BEFORE UPDATE / BEFORE DELETE triggers that refuse any change to ledger_entries and audit_logs (MySQL + SQLite);
 *  - one `ledger.legacy_voids_identified` audit row for voids made before this change (R6). No entries are rebuilt.
 */
return new class extends Migration
{
    private const IMMUTABLE = ['ledger_entries', 'audit_logs'];

    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['transaction_id']);
            $table->foreign('transaction_id')->references('id')->on('transactions')->restrictOnDelete();
            $table->foreignId('contra_of_id')->nullable()->after('transaction_id')->unique()->constrained('ledger_entries')->restrictOnDelete();
        });

        Schema::table('period_locks', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'period']);
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users');
            $table->text('unlock_reason')->nullable();
            $table->index(['period', 'branch_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['branch_id', 'created_at']);
            $table->index('event');
            $table->index('user_id');
        });

        $driver = DB::connection()->getDriverName();

        // Finding 13: at most one active lock per (branch or global, period), even under concurrent requests.
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE period_locks ADD active_key VARCHAR(40) GENERATED ALWAYS AS
                (IF(unlocked_at IS NULL, CONCAT(COALESCE(branch_id, 0), ':', period), NULL)) STORED");
            DB::statement('ALTER TABLE period_locks ADD UNIQUE period_locks_active_key_unique (active_key)');
        } else {
            DB::statement('CREATE UNIQUE INDEX period_locks_active_unique ON period_locks (COALESCE(branch_id, 0), period) WHERE unlocked_at IS NULL');
        }

        // Finding 2: immutability at database level (query-builder writes skip model events).
        foreach (self::IMMUTABLE as $t) {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = "{$t}_no_".strtolower($op);
                $message = "{$t} rows are immutable ({$op} refused).";
                DB::unprepared(match ($driver) {
                    'mysql' => "CREATE TRIGGER {$name} BEFORE {$op} ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                    default => "CREATE TRIGGER {$name} BEFORE {$op} ON {$t} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                });
            }
        }

        LegacyVoids::recordIdentified();
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (self::IMMUTABLE as $t) {
            foreach (['update', 'delete'] as $op) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_{$op}");
            }
        }
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE period_locks DROP INDEX period_locks_active_key_unique');
            DB::statement('ALTER TABLE period_locks DROP COLUMN active_key');
        } else {
            DB::statement('DROP INDEX IF EXISTS period_locks_active_unique');
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropIndex(['event']);
            $table->dropIndex(['user_id']);
        });

        // Note: rolling back fails on purpose once unlock history exists (two rows per branch+period break the old unique key).
        Schema::table('period_locks', function (Blueprint $table) {
            $table->dropIndex(['period', 'branch_id']);
            $table->dropForeign(['unlocked_by']);
            $table->dropColumn(['unlocked_at', 'unlocked_by', 'unlock_reason']);
            $table->unique(['branch_id', 'period']);
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['contra_of_id']);
            $table->dropUnique(['contra_of_id']);
            $table->dropColumn('contra_of_id');
            $table->dropForeign(['transaction_id']);
            $table->foreign('transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
        });
    }
};
