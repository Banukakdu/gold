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

    public function up(): void
    {
        // MySQL DDL is not transactional: refuse to start, listing the offenders, rather than stop half-way.
        MigrationPreflight::assertCanCreateTriggers();
        DoubleEntryPreflight::assertReady();

        $driver = DB::connection()->getDriverName();
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('class', 10)->default('asset')->after('type');
            $table->string('code', 10)->nullable()->after('branch_id');
        });
        if ($driver === 'sqlite') {
            // Native ADD COLUMN ... REFERENCES: the schema builder would rebuild the table to add a foreign key, and a
            // rebuild inside the migration transaction cannot switch foreign keys off (rows of transactions point here).
            DB::statement('ALTER TABLE accounts ADD COLUMN category_id INTEGER NULL REFERENCES categories(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE accounts ADD COLUMN counterparty_branch_id INTEGER NULL REFERENCES branches(id) ON DELETE RESTRICT');
        } else {
            Schema::table('accounts', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->after('class')->constrained('categories')->restrictOnDelete();
                $table->foreignId('counterparty_branch_id')->nullable()->after('category_id')->constrained('branches')->restrictOnDelete();
            });
        }

        // The unique keys start with branch_id, so MySQL keeps a separate index for the branch_id foreign key
        // (accounts_branch_id_foreign); the new foreign keys get their own single-column indexes above.
        Schema::table('accounts', function (Blueprint $table) {
            $table->unique(['branch_id', 'code']);
            $table->unique(['branch_id', 'category_id']);
            $table->unique(['branch_id', 'counterparty_branch_id']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('ledger_code', 5)->nullable()->unique();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('origin', 10)->default('posting');
        });

        DB::unprepared(self::triggerSql($driver));
    }

    public function down(): void
    {
        // The trigger reads accounts, so it goes before the accounts columns.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);

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
