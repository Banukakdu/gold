<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-066 recurring expense templates (docs/specs/recurring-expenses.md section 5 "Schema"). ADD only, resumable (every
 * step checks first; MySQL commits each DDL statement on its own):
 *  - recurring_templates: a saved pre-fill of the Record expense form, owned by one branch. It posts nothing; amounts
 *    are integer cents (null = "Enter each time"). The case-insensitive unique name among non-archived templates is
 *    checked in code (R3): a plain unique index cannot ignore archived rows;
 *  - transactions.expense_template_id (FK recurring_templates) and the index (expense_template_id, txn_date) of the R6
 *    duplicate guard. The index is created before the foreign key, so MySQL uses it for the constraint and adds no
 *    implicit index of its own; down() drops the foreign key first, then the index (no error 1553).
 * No permission key and no role changes (spec section 2). Nothing posts money; ledger_entries is not touched.
 * SQLite: native ADD COLUMN ... REFERENCES (the schema builder would rebuild transactions to add a foreign key).
 */
return new class extends Migration
{
    public const COLUMN = 'expense_template_id';

    public const INDEX = 'transactions_expense_template_txn_date_index';

    public const REFUSED = 'Expense templates exist; rollback would lose them.';

    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        if (! Schema::hasTable('recurring_templates')) {
            Schema::create('recurring_templates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $t->string('type', 20)->default('expense'); // only `expense` is accepted (income may follow, spec section 1)
                $t->string('name', 100);
                $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $t->foreignId('category_id')->constrained('categories')->restrictOnDelete();
                $t->unsignedBigInteger('amount_cents')->nullable(); // null = "Enter each time" (Q2)
                $t->string('payee_payer', 255)->nullable();
                $t->string('payment_method', 30)->nullable();
                $t->text('description')->nullable();
                $t->string('frequency', 10); // monthly | quarterly | yearly | none
                $t->unsignedInteger('version')->default(1); // +1 on every PUT that changes a field (R11, review MUST 2)
                $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp('archived_at')->nullable();
                $t->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->string('archive_reason', 500)->nullable();
                $t->timestamps();
                // Leads with the branch_id foreign key column; it goes with its table on rollback (never dropped alone).
                $t->index(['branch_id', 'archived_at']);
            });
        }

        if (! Schema::hasColumn('transactions', self::COLUMN)) {
            if ($mysql) {
                Schema::table('transactions', fn (Blueprint $t) => $t->unsignedBigInteger(self::COLUMN)->nullable());
            } else {
                DB::statement('ALTER TABLE transactions ADD COLUMN '.self::COLUMN.' INTEGER NULL REFERENCES recurring_templates(id) ON DELETE RESTRICT');
            }
        }
        // Before the foreign key: MySQL then uses this index for the constraint and creates no implicit one.
        if (! Schema::hasIndex('transactions', self::INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index([self::COLUMN, 'txn_date'], self::INDEX));
        }
        if ($mysql && ! $this->hasForeignKey()) {
            Schema::table('transactions', fn (Blueprint $t) => $t->foreign(self::COLUMN)->references('id')->on('recurring_templates')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        // Templates are standing payment data with their audit history; the link on each expense is evidence (R1, R10).
        if (Schema::hasTable('recurring_templates') && DB::table('recurring_templates')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: '.self::REFUSED);
        }
        $mysql = DB::connection()->getDriverName() === 'mysql';

        // The foreign key first: on MySQL it is backed by INDEX, which cannot be dropped while the constraint needs it.
        if ($mysql && $this->hasForeignKey()) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropForeign([self::COLUMN]));
        }
        if (Schema::hasIndex('transactions', self::INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
        }
        // Native DROP COLUMN (MySQL, SQLite >= 3.35): transactions is not rebuilt.
        if (Schema::hasColumn('transactions', self::COLUMN)) {
            DB::statement('ALTER TABLE transactions DROP COLUMN '.self::COLUMN);
        }
        Schema::dropIfExists('recurring_templates');
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('transactions') as $fk) {
            if ($fk['columns'] === [self::COLUMN]) {
                return true;
            }
        }

        return false;
    }
};
