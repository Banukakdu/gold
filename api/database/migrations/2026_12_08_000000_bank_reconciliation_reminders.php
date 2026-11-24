<?php

use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-033f bell reminder for overdue bank reconciliations (docs/specs/reconciliation-overdue-reminder.md section 5 "New
 * table"). ADD only, resumable. Dated after AG-033d's 2026_12_07 migration. Posts nothing.
 *  - bank_reconciliation_reminders: the dedupe record of the reminders sent, one row per (account, overdue month-end E,
 *    round). Round 1 is the first reminder for E, each re-send after an undo adds one (R6). Rows are never updated or
 *    deleted (triggers), and notifications:prune does not touch them (R11).
 */
return new class extends Migration
{
    public const UNIQUE = 'bank_reconciliation_reminders_key_unique';

    public function up(): void
    {
        MigrationPreflight::assertCanCreateTriggers();
        if (! Schema::hasTable('bank_reconciliation_reminders')) {
            Schema::create('bank_reconciliation_reminders', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $t->date('overdue_through');
                $t->unsignedTinyInteger('round')->default(1);
                $t->unsignedInteger('months_overdue');
                $t->date('last_statement_date')->nullable();
                $t->json('recipient_ids');
                $t->boolean('super_admin_fallback')->default(false);
                $t->timestamp('created_at')->nullable();
                $t->unique(['account_id', 'overdue_through', 'round'], self::UNIQUE);
                $t->index(['branch_id', 'overdue_through']);
            });
        }
        $this->triggers(DB::connection()->getDriverName() === 'mysql');
    }

    public function down(): void
    {
        // The reminders are the record that the control fired: never dropped with data in it.
        if (Schema::hasTable('bank_reconciliation_reminders') && DB::table('bank_reconciliation_reminders')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: bank_reconciliation_reminders holds the overdue reconciliation '
                .'reminders sent, and rolling back would drop them. Restore a backup taken before this migration instead.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS bank_reconciliation_reminders_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS bank_reconciliation_reminders_no_delete');
        Schema::dropIfExists('bank_reconciliation_reminders');
    }

    /** Section 5: rows are never updated or deleted. */
    private function triggers(bool $mysql): void
    {
        $update = 'bank_reconciliation_reminders rows are evidence (UPDATE refused).';
        $delete = 'bank_reconciliation_reminders rows are evidence (DELETE refused).';
        DB::unprepared('DROP TRIGGER IF EXISTS bank_reconciliation_reminders_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS bank_reconciliation_reminders_no_delete');
        if ($mysql) {
            DB::unprepared("CREATE TRIGGER bank_reconciliation_reminders_no_update BEFORE UPDATE ON bank_reconciliation_reminders FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$update}'");
            DB::unprepared("CREATE TRIGGER bank_reconciliation_reminders_no_delete BEFORE DELETE ON bank_reconciliation_reminders FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$delete}'");
        } else {
            DB::unprepared("CREATE TRIGGER bank_reconciliation_reminders_no_update BEFORE UPDATE ON bank_reconciliation_reminders
                BEGIN SELECT RAISE(ABORT, '{$update}'); END");
            DB::unprepared("CREATE TRIGGER bank_reconciliation_reminders_no_delete BEFORE DELETE ON bank_reconciliation_reminders
                BEGIN SELECT RAISE(ABORT, '{$delete}'); END");
        }
    }
};
