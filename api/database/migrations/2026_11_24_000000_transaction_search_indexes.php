<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-065 transaction search (docs/specs/transaction-search.md R13). ADD only, resumable: every step checks first
 * (MySQL commits each DDL statement on its own).
 *  - (txn_date, id): All mode with no branch filter pages the whole table in date order without a filesort;
 *  - (related_branch_id, txn_date): the incoming leg of the scope (branch_id = ? OR related_branch_id = ?).
 * MySQL silently drops the implicit index of the related_branch_id foreign key once another index can serve it, and
 * then refuses to drop that index (error 1553). So the rollback first gives the foreign key its own named index when
 * it has no single-column index, and drops only the two indexes above. up() adds that index only when no index serves
 * the foreign key at all (a partial run), never as a duplicate.
 */
return new class extends Migration
{
    private const DATE_ID = 'transactions_txn_date_id_index';

    private const RELATED = 'transactions_related_branch_txn_date_index';

    private const RELATED_FK = 'transactions_related_branch_id_fk';

    public function up(): void
    {
        // Only when no index serves the foreign key at all (reviewer m2: no duplicate of the implicit index; a re-run
        // after the composite exists adds nothing).
        if (DB::connection()->getDriverName() === 'mysql' && ! $this->hasIndexStartingWith('related_branch_id')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index('related_branch_id', self::RELATED_FK));
        }
        if (! Schema::hasIndex('transactions', self::DATE_ID)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index(['txn_date', 'id'], self::DATE_ID));
        }
        if (! Schema::hasIndex('transactions', self::RELATED)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index(['related_branch_id', 'txn_date'], self::RELATED));
        }
    }

    public function down(): void
    {
        // The composite may be the only index of the foreign key (MySQL dropped the implicit one): give it its own first.
        if (DB::connection()->getDriverName() === 'mysql' && ! $this->hasSingleColumnIndex('related_branch_id')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index('related_branch_id', self::RELATED_FK));
        }
        if (Schema::hasIndex('transactions', self::RELATED)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::RELATED));
        }
        if (Schema::hasIndex('transactions', self::DATE_ID)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::DATE_ID));
        }
    }

    private function hasIndexStartingWith(string $column): bool
    {
        foreach (Schema::getIndexes('transactions') as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    private function hasSingleColumnIndex(string $column): bool
    {
        foreach (Schema::getIndexes('transactions') as $index) {
            if ($index['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
