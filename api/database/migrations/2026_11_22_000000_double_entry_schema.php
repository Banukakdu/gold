<?php

use App\Support\DoubleEntryPreflight;
use App\Support\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full double entry, schema part (docs/specs/double-entry.md section 5 and R4).
 *  - accounts: class, code, category_id, counterparty_branch_id (the chart of accounts lives in the accounts table);
 *  - categories: ledger_code (4PPSS / 5PPSS, the same in every branch);
 *  - ledger_entries: origin (posting | reclass | backfill). ADD COLUMN only: on SQLite a schema-builder CHECK would
 *    rebuild the table and drop the immutability triggers (controls migration note);
 *  - BEFORE INSERT trigger ledger_entries_line_check (D1-D3). There is deliberately no balance trigger (R4).
 *
 * The chart and the back-fill are in the next migration (2026_11_22_000001_double_entry_backfill).
 */
return new class extends Migration
{
    public const TRIGGER = 'ledger_entries_line_check';

    /**
     * Resumable (review R1): MySQL commits each DDL statement, so a run that stops part-way leaves some columns,
     * keys or the trigger behind. Every step checks first and only adds what is missing, so running `migrate`
     * again completes the schema. The preflight runs before any change, every time.
     */
    public function up(): void
    {
        // Refuse to start, listing the offenders, rather than stop half-way (MySQL DDL is not transactional).
        MigrationPreflight::assertCanCreateTriggers();
        DoubleEntryPreflight::assertReady();

        $driver = DB::connection()->getDriverName();
        if (! Schema::hasColumn('accounts', 'class')) {
            Schema::table('accounts', fn (Blueprint $table) => $table->string('class', 10)->default('asset')->after('type'));
        }
        if (! Schema::hasColumn('accounts', 'code')) {
            Schema::table('accounts', fn (Blueprint $table) => $table->string('code', 10)->nullable()->after('branch_id'));
        }
        foreach (['category_id' => ['categories', 'class'], 'counterparty_branch_id' => ['branches', 'category_id']] as $column => [$on, $after]) {
            if ($driver === 'sqlite') {
                // Native ADD COLUMN ... REFERENCES: the schema builder would rebuild the table to add a foreign key, and a
                // rebuild inside the migration transaction cannot switch foreign keys off (rows of transactions point here).
                if (! Schema::hasColumn('accounts', $column)) {
                    DB::statement("ALTER TABLE accounts ADD COLUMN {$column} INTEGER NULL REFERENCES {$on}(id) ON DELETE RESTRICT");
                }

                continue;
            }
            if (! Schema::hasColumn('accounts', $column)) {
                Schema::table('accounts', fn (Blueprint $table) => $table->unsignedBigInteger($column)->nullable()->after($after));
            }
            if (! self::hasForeignKey('accounts', $column)) {
                Schema::table('accounts', fn (Blueprint $table) => $table->foreign($column)->references('id')->on($on)->restrictOnDelete());
            }
        }

        // The unique keys start with branch_id, so MySQL keeps a separate index for the branch_id foreign key
        // (accounts_branch_id_foreign); the new foreign keys get their own single-column indexes above.
        foreach ([['branch_id', 'code'], ['branch_id', 'category_id'], ['branch_id', 'counterparty_branch_id']] as $columns) {
            if (! Schema::hasIndex('accounts', $columns, 'unique')) {
                Schema::table('accounts', fn (Blueprint $table) => $table->unique($columns));
            }
        }

        if (! Schema::hasColumn('categories', 'ledger_code')) {
            Schema::table('categories', fn (Blueprint $table) => $table->string('ledger_code', 5)->nullable());
        }
        if (! Schema::hasIndex('categories', ['ledger_code'], 'unique')) {
            Schema::table('categories', fn (Blueprint $table) => $table->unique('ledger_code'));
        }

        if (! Schema::hasColumn('ledger_entries', 'origin')) {
            Schema::table('ledger_entries', fn (Blueprint $table) => $table->string('origin', 10)->default('posting'));
        }

        // Review R3b: the chart code lock, one row that no foreign key references (see ChartOfAccounts::lockChart).
        if (! Schema::hasTable('chart_code_locks')) {
            Schema::create('chart_code_locks', fn (Blueprint $table) => $table->unsignedTinyInteger('id')->primary());
        }
        DB::table('chart_code_locks')->insertOrIgnore(['id' => 1]);

        // A trigger left by a run that stopped (or an older definition) is replaced, never duplicated (MySQL error 1359).
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        DB::unprepared(self::triggerSql($driver));
    }

    private static function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if ($fk['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // The trigger reads accounts, so it goes before the accounts columns.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        Schema::dropIfExists('chart_code_locks');

        // Native DROP COLUMN on SQLite >= 3.35 and MySQL: ledger_entries is not rebuilt, so its immutability triggers stay.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('origin');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['ledger_code']);
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('ledger_code');
        });

        // MySQL: the branch_id foreign key may use one of the unique keys as its index (error 1553 on drop),
        // so give it its own index first.
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql && ! Schema::hasIndex('accounts', 'accounts_branch_id_fk')) {
            Schema::table('accounts', fn (Blueprint $table) => $table->index('branch_id', 'accounts_branch_id_fk'));
        }
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['counterparty_branch_id']);
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'code']);
            $table->dropUnique(['branch_id', 'category_id']);
            $table->dropUnique(['branch_id', 'counterparty_branch_id']);
        });
        // accounts_branch_id_fk stays on MySQL: it is now the branch_id foreign key's index.
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['class', 'code', 'category_id', 'counterparty_branch_id']);
        });
    }

    /** D1: one side > 0 and none negative. D2: line branch = account branch. D3: a contra mirrors its original. */
    public static function triggerSql(string $driver): string
    {
        $d1 = 'NEW.debit < 0 OR NEW.credit < 0 OR (NEW.debit > 0 AND NEW.credit > 0) OR (NEW.debit = 0 AND NEW.credit = 0)';
        $d2 = 'NOT EXISTS (SELECT 1 FROM accounts a WHERE a.id = NEW.account_id AND a.branch_id = NEW.branch_id)';
        $d3 = 'NEW.contra_of_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ledger_entries o WHERE o.id = NEW.contra_of_id
            AND o.account_id = NEW.account_id AND o.transaction_id = NEW.transaction_id AND o.debit = NEW.credit AND o.credit = NEW.debit)';
        $msg = fn (string $rule) => "ledger_entries line check failed ({$rule}).";
        $name = self::TRIGGER;

        if ($driver === 'mysql') {
            return "CREATE TRIGGER {$name} BEFORE INSERT ON ledger_entries FOR EACH ROW
                BEGIN
                    IF {$d1} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg('D1')}'; END IF;
                    IF {$d2} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg('D2')}'; END IF;
                    IF {$d3} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg('D3')}'; END IF;
                END";
        }

        return "CREATE TRIGGER {$name} BEFORE INSERT ON ledger_entries
            BEGIN
                SELECT RAISE(ABORT, '{$msg('D1')}') WHERE {$d1};
                SELECT RAISE(ABORT, '{$msg('D2')}') WHERE {$d2};
                SELECT RAISE(ABORT, '{$msg('D3')}') WHERE {$d3};
            END";
    }
};
