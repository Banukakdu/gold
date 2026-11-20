<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * AG-052 transfer receipt (docs/specs/transfer-receipt.md section 5). ADD only, resumable: every step checks first
 * (MySQL commits each DDL statement on its own).
 *  - transactions: receipt_status, received_on, receipt_note, receipt_by (FK users, own index), receipt_at, and the
 *    index (related_branch_id, receipt_status) of the receiving branch's widget;
 *  - settings row transfer_receipt_required = 0 where missing (default off, Q2);
 *  - permission transfer.acknowledge where missing, given to the "*" templates exactly as PermissionSeeder::run does
 *    for a key new to the catalogue, so an existing install works without a re-seed. On a fresh install the
 *    permission tables are still empty here and the seeder adds the key.
 * SQLite: native ADD COLUMN ... REFERENCES (the schema builder would rebuild transactions to add a foreign key).
 */
return new class extends Migration
{
    public const PERMISSION = 'transfer.acknowledge';

    private const STATUS_INDEX = 'transactions_related_branch_receipt_index';

    private const RECEIPT_BY_FK = 'transactions_receipt_by_fk';

    private const RELATED_FK = 'transactions_related_branch_id_fk';

    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        foreach (['receipt_status' => fn (Blueprint $t) => $t->string('receipt_status', 20)->nullable(),
            'received_on' => fn (Blueprint $t) => $t->date('received_on')->nullable(),
            'receipt_note' => fn (Blueprint $t) => $t->text('receipt_note')->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('transactions', $column)) {
                Schema::table('transactions', $add);
            }
        }
        if (! Schema::hasColumn('transactions', 'receipt_by')) {
            if ($mysql) {
                Schema::table('transactions', fn (Blueprint $t) => $t->unsignedBigInteger('receipt_by')->nullable());
            } else {
                DB::statement('ALTER TABLE transactions ADD COLUMN receipt_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT');
            }
        }
        if (! Schema::hasColumn('transactions', 'receipt_at')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->timestamp('receipt_at')->nullable());
        }
        // The foreign key keeps an index of its own (created before the constraint, so MySQL adds no implicit one).
        if (! Schema::hasIndex('transactions', self::RECEIPT_BY_FK)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index('receipt_by', self::RECEIPT_BY_FK));
        }
        if ($mysql && ! $this->hasForeignKey('receipt_by')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->foreign('receipt_by')->references('id')->on('users')->restrictOnDelete());
        }
        if (! Schema::hasIndex('transactions', self::STATUS_INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index(['related_branch_id', 'receipt_status'], self::STATUS_INDEX));
        }

        $now = now();
        DB::table('settings')->insertOrIgnore(['key' => 'transfer_receipt_required', 'value' => '0', 'created_at' => $now, 'updated_at' => $now]);

        $this->addPermission();
    }

    public function down(): void
    {
        // The receipt columns are the only record of who confirmed each cash handover.
        if (Schema::hasColumn('transactions', 'receipt_status') && DB::table('transactions')->whereNotNull('receipt_status')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: transactions hold transfer receipts, and rolling back '
                .'would drop who confirmed them. Restore a backup taken before this migration instead.');
        }
        $mysql = DB::connection()->getDriverName() === 'mysql';

        // MySQL may use the composite as the related_branch_id foreign key's index (error 1553 on drop): give the
        // foreign key its own index first when no other index would serve it.
        if ($mysql && Schema::hasIndex('transactions', self::STATUS_INDEX) && ! $this->otherIndexStartsWith('related_branch_id', self::STATUS_INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index('related_branch_id', self::RELATED_FK));
        }
        if (Schema::hasIndex('transactions', self::STATUS_INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::STATUS_INDEX));
        }
        if ($mysql && $this->hasForeignKey('receipt_by')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropForeign(['receipt_by']));
        }
        if (Schema::hasIndex('transactions', self::RECEIPT_BY_FK)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::RECEIPT_BY_FK));
        }
        // Native DROP COLUMN (MySQL, SQLite >= 3.35): transactions is not rebuilt.
        foreach (['receipt_at', 'receipt_by', 'receipt_note', 'received_on', 'receipt_status'] as $column) {
            if (Schema::hasColumn('transactions', $column)) {
                DB::statement("ALTER TABLE transactions DROP COLUMN {$column}");
            }
        }

        DB::table('settings')->where('key', 'transfer_receipt_required')->delete();
        $this->removePermission();
    }

    /** PermissionSeeder::run for one new key: the row, and every "*" template (all keys) gets it. */
    private function addPermission(): void
    {
        if (! Schema::hasTable('permissions') || ! DB::table('permissions')->exists()) {
            return; // fresh install: the seeder creates the catalogue
        }
        if (DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->exists()) {
            return;
        }
        $now = now();
        $id = DB::table('permissions')->insertGetId(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $all = collect(PermissionSeeder::TEMPLATES)->filter(fn ($p) => $p === '*')->keys();
        $roles = DB::table('roles')->whereNull('branch_id')->whereIn('name', $all)->pluck('id');
        foreach ($roles as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
        }
        $this->forgetPermissionCache();
    }

    private function removePermission(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $ids = DB::table('permissions')->where('name', self::PERMISSION)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        $this->forgetPermissionCache();
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function hasForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys('transactions') as $fk) {
            if ($fk['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function otherIndexStartsWith(string $column, string $except): bool
    {
        foreach (Schema::getIndexes('transactions') as $index) {
            if ($index['name'] !== $except && ($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
