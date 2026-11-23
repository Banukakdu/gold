<?php

use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-033b bank statement copy on a reconciliation statement (docs/specs/bank-statement-attachment.md section 5 "Schema").
 * ADD only, on the AG-062 `attachments` table (no second table). Resumable: every step checks first (MySQL commits each
 * DDL statement on its own):
 *  - attachments.transaction_id becomes nullable; bank_statement_id (FK bank_statements, restrict) and replaced_by_id
 *    (FK attachments, restrict) are added; index (bank_statement_id, removed_at);
 *  - BEFORE INSERT trigger attachments_one_parent: exactly one of transaction_id / bank_statement_id is set;
 *  - attachments_no_update is rebuilt: bank_statement_id is frozen, replaced_by_id joins the removal columns.
 * On SQLite the nullable change rebuilds the table, which drops its triggers, so all three triggers are (re)created at the
 * end on both drivers. MySQL: each new foreign key gets its index before the constraint, so the constraint uses it and no
 * implicit index is created; down() drops each constraint before its index (no error 1553).
 * Nothing posts money; ledger_entries, balances, marks and the stored statement figures are not touched.
 */
return new class extends Migration
{
    /** AG-062 (create_attachments_table): restored by down(). */
    public const AG062_REMOVAL = ['removed_at', 'removed_by', 'removal_reason', 'updated_at'];

    public const AG062_FROZEN = ['id', 'branch_id', 'transaction_id', 'disk', 'path', 'original_name', 'mime', 'size_bytes',
        'sha256', 'added_after', 'uploaded_by', 'created_at'];

    public const FROZEN_COLUMNS = [...self::AG062_FROZEN, 'bank_statement_id'];

    public const STATEMENT_INDEX = 'attachments_bank_statement_id_removed_at_index';

    public const REPLACED_INDEX = 'attachments_replaced_by_id_fk';

    public const MESSAGE = 'attachments rows are immutable';

    public const ONE_PARENT = 'attachments rows need exactly one parent (transaction_id or bank_statement_id).';

    public const REFUSED = 'Rollback stopped before any change: attachments holds bank statement files, and rolling back would '
        .'drop their link to the statement. Restore a backup taken before this migration instead.';

    public function up(): void
    {
        // MySQL DDL is not transactional: refuse to start rather than leave the table without its triggers.
        MigrationPreflight::assertCanCreateTriggers();
        $mysql = DB::connection()->getDriverName() === 'mysql';

        if (! $this->isNullable('transaction_id')) {
            if ($mysql) {
                DB::statement('ALTER TABLE attachments MODIFY transaction_id BIGINT UNSIGNED NULL');
            } else {
                // Rebuilds the table (columns, indexes and foreign keys are kept; triggers are recreated below).
                Schema::table('attachments', fn (Blueprint $t) => $t->unsignedBigInteger('transaction_id')->nullable()->change());
            }
        }

        if (! Schema::hasColumn('attachments', 'bank_statement_id')) {
            $mysql
                ? DB::statement('ALTER TABLE attachments ADD bank_statement_id BIGINT UNSIGNED NULL AFTER transaction_id')
                : DB::statement('ALTER TABLE attachments ADD COLUMN bank_statement_id INTEGER NULL REFERENCES bank_statements(id) ON DELETE RESTRICT');
        }
        // Before the foreign key: MySQL then uses this index for the constraint.
        if (! Schema::hasIndex('attachments', self::STATEMENT_INDEX)) {
            Schema::table('attachments', fn (Blueprint $t) => $t->index(['bank_statement_id', 'removed_at'], self::STATEMENT_INDEX));
        }
        if ($mysql && ! $this->hasForeignKey('bank_statement_id')) {
            Schema::table('attachments', fn (Blueprint $t) => $t->foreign('bank_statement_id')->references('id')->on('bank_statements')->restrictOnDelete());
        }

        if (! Schema::hasColumn('attachments', 'replaced_by_id')) {
            $mysql
                ? DB::statement('ALTER TABLE attachments ADD replaced_by_id BIGINT UNSIGNED NULL AFTER removal_reason')
                : DB::statement('ALTER TABLE attachments ADD COLUMN replaced_by_id INTEGER NULL REFERENCES attachments(id) ON DELETE RESTRICT');
        }
        if (! Schema::hasIndex('attachments', self::REPLACED_INDEX)) {
            Schema::table('attachments', fn (Blueprint $t) => $t->index('replaced_by_id', self::REPLACED_INDEX));
        }
        if ($mysql && ! $this->hasForeignKey('replaced_by_id')) {
            Schema::table('attachments', fn (Blueprint $t) => $t->foreign('replaced_by_id')->references('id')->on('attachments')->restrictOnDelete());
        }

        $this->createTriggers(self::FROZEN_COLUMNS, true);
    }

    public function down(): void
    {
        if (Schema::hasColumn('attachments', 'bank_statement_id') && DB::table('attachments')->whereNotNull('bank_statement_id')->exists()) {
            throw new RuntimeException(self::REFUSED);
        }
        $mysql = DB::connection()->getDriverName() === 'mysql';
        // Triggers first: they name the columns dropped below.
        foreach (['attachments_one_parent', 'attachments_no_update', 'attachments_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        foreach (['replaced_by_id' => self::REPLACED_INDEX, 'bank_statement_id' => self::STATEMENT_INDEX] as $column => $index) {
            // The constraint before the index that backs it (MySQL error 1553 otherwise).
            if ($mysql && $this->hasForeignKey($column)) {
                Schema::table('attachments', fn (Blueprint $t) => $t->dropForeign([$column]));
            }
            if (Schema::hasIndex('attachments', $index)) {
                Schema::table('attachments', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        if ($mysql) {
            foreach (['replaced_by_id', 'bank_statement_id'] as $column) {
                if (Schema::hasColumn('attachments', $column)) {
                    DB::statement("ALTER TABLE attachments DROP COLUMN {$column}");
                }
            }
            if ($this->isNullable('transaction_id')) {
                DB::statement('ALTER TABLE attachments MODIFY transaction_id BIGINT UNSIGNED NOT NULL');
            }
        } else {
            // SQLite cannot drop a column that carries a REFERENCES clause: one rebuild without the two columns, with
            // transaction_id NOT NULL again (columns, indexes and the other foreign keys are kept).
            $this->sqliteRebuildWithoutStatementColumns();
        }

        $this->createTriggers(self::AG062_FROZEN, false);
    }

    /** (Re)create the immutability triggers. $statements: the AG-033b version (one-parent insert check, replaced_by_id). */
    private function createTriggers(array $frozen, bool $statements): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $message = self::MESSAGE;

        DB::unprepared('DROP TRIGGER IF EXISTS attachments_no_delete');
        DB::unprepared($mysql
            ? "CREATE TRIGGER attachments_no_delete BEFORE DELETE ON attachments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message} (DELETE refused).'"
            : "CREATE TRIGGER attachments_no_delete BEFORE DELETE ON attachments BEGIN SELECT RAISE(ABORT, '{$message} (DELETE refused).'); END");

        // Refused: a second update after removal, an update that is not a removal, or a change to any frozen column.
        // replaced_by_id is not frozen: it is set in the same (one-time) update as the removal fields.
        $changed = array_map(fn ($c) => $mysql ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}", $frozen);
        $refuse = 'OLD.removed_at IS NOT NULL OR NEW.removed_at IS NULL OR NEW.removed_by IS NULL OR '.implode(' OR ', $changed);
        $updateMessage = $statements
            ? "{$message} (only a one-time removal may change removed_at, removed_by, removal_reason and replaced_by_id)."
            : "{$message} (only a one-time removal may change removed_at, removed_by and removal_reason).";
        DB::unprepared('DROP TRIGGER IF EXISTS attachments_no_update');
        DB::unprepared($mysql
            ? "CREATE TRIGGER attachments_no_update BEFORE UPDATE ON attachments FOR EACH ROW
                BEGIN
                    IF {$refuse} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$updateMessage}';
                    END IF;
                END"
            : "CREATE TRIGGER attachments_no_update BEFORE UPDATE ON attachments WHEN {$refuse}
                BEGIN SELECT RAISE(ABORT, '{$updateMessage}'); END");

        DB::unprepared('DROP TRIGGER IF EXISTS attachments_one_parent');
        if ($statements) {
            $both = '(NEW.transaction_id IS NULL) = (NEW.bank_statement_id IS NULL)';
            $one = self::ONE_PARENT;
            DB::unprepared($mysql
                ? "CREATE TRIGGER attachments_one_parent BEFORE INSERT ON attachments FOR EACH ROW
                    BEGIN
                        IF {$both} THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$one}';
                        END IF;
                    END"
                : "CREATE TRIGGER attachments_one_parent BEFORE INSERT ON attachments WHEN {$both}
                    BEGIN SELECT RAISE(ABORT, '{$one}'); END");
        }
    }

    private function isNullable(string $column): bool
    {
        foreach (Schema::getColumns('attachments') as $c) {
            if ($c['name'] === $column) {
                return (bool) $c['nullable'];
            }
        }

        return false;
    }

    private function hasForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys('attachments') as $fk) {
            if ($fk['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function sqliteRebuildWithoutStatementColumns(): void
    {
        $has = fn (string $c) => Schema::hasColumn('attachments', $c);
        if (! $has('bank_statement_id') && ! $has('replaced_by_id') && ! $this->isNullable('transaction_id')) {
            return;
        }
        Schema::table('attachments', function (Blueprint $t) use ($has) {
            foreach (['bank_statement_id', 'replaced_by_id'] as $c) {
                if ($has($c)) {
                    $t->dropForeign([$c]);
                }
            }
        });
        Schema::table('attachments', function (Blueprint $t) use ($has) {
            $drop = array_values(array_filter(['bank_statement_id', 'replaced_by_id'], $has));
            if ($drop) {
                $t->dropColumn($drop);
            }
            $t->unsignedBigInteger('transaction_id')->nullable(false)->change();
        });
    }
};
