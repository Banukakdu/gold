<?php

use App\Models\AuditLog;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * AG-051 transfer approvals (docs/specs/transfer-approvals.md section 5 "Schema"). ADD only, resumable: every step
 * checks first (MySQL commits each DDL statement on its own).
 *  - transactions: approval_status, requested_date, requested_on (business date of the request, accounting review
 *    finding 11), approval_threshold, approval_note, decided_by (FK users, own index), decided_at, and the index
 *    (approval_status, branch_id) of the "Transfers to approve" widget. approved_by / approved_at already exist;
 *  - settings row transfer_approval_threshold = null (never) where missing;
 *  - permission transfer.approve where missing, given to the "*" templates exactly as PermissionSeeder::run does for a
 *    key new to the catalogue, so an existing install works without a re-seed. On a fresh install the permission
 *    tables are still empty here and the seeder adds the key;
 *  - (review M1) MAIN's branch roles that hold every other catalogue key (copies of a "*" template, e.g. MAIN's
 *    "Branch Admin") get the key too, with a role.updated audit row each: the key only counts in MAIN (R3), and spec
 *    section 2 says Branch Admin has it automatically. A copy the Super Admin narrowed is left as it is.
 * SQLite: native ADD COLUMN ... REFERENCES (the schema builder would rebuild transactions to add a foreign key).
 */
return new class extends Migration
{
    public const PERMISSION = 'transfer.approve';

    public const SETTING = 'transfer_approval_threshold';

    private const STATUS_INDEX = 'transactions_approval_status_branch_index';

    private const DECIDED_BY_FK = 'transactions_decided_by_fk';

    private const COLUMNS = ['decided_at', 'decided_by', 'approval_note', 'approval_threshold', 'requested_on', 'requested_date', 'approval_status'];

    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        foreach (['approval_status' => fn (Blueprint $t) => $t->string('approval_status', 20)->nullable(),
            'requested_date' => fn (Blueprint $t) => $t->date('requested_date')->nullable(),
            'requested_on' => fn (Blueprint $t) => $t->date('requested_on')->nullable(),
            'approval_threshold' => fn (Blueprint $t) => $t->decimal('approval_threshold', 15, 2)->nullable(),
            'approval_note' => fn (Blueprint $t) => $t->text('approval_note')->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('transactions', $column)) {
                Schema::table('transactions', $add);
            }
        }
        if (! Schema::hasColumn('transactions', 'decided_by')) {
            if ($mysql) {
                Schema::table('transactions', fn (Blueprint $t) => $t->unsignedBigInteger('decided_by')->nullable());
            } else {
                DB::statement('ALTER TABLE transactions ADD COLUMN decided_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT');
            }
        }
        if (! Schema::hasColumn('transactions', 'decided_at')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->timestamp('decided_at')->nullable());
        }
        // The foreign key keeps an index of its own (created before the constraint, so MySQL adds no implicit one).
        if (! Schema::hasIndex('transactions', self::DECIDED_BY_FK)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index('decided_by', self::DECIDED_BY_FK));
        }
        if ($mysql && ! $this->hasForeignKey('decided_by')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->foreign('decided_by')->references('id')->on('users')->restrictOnDelete());
        }
        // approval_status leads, so this index never serves (or pins) the branch_id foreign key.
        if (! Schema::hasIndex('transactions', self::STATUS_INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index(['approval_status', 'branch_id'], self::STATUS_INDEX));
        }

        $now = now();
        DB::table('settings')->insertOrIgnore(['key' => self::SETTING, 'value' => null, 'created_at' => $now, 'updated_at' => $now]);

        $this->addPermission();
    }

    public function down(): void
    {
        // The approval columns are the only record of who requested, approved or rejected each held transfer. CI's
        // rollback step relies on `migrate:fresh --seed` creating no request (MigrationTest checks that).
        if (Schema::hasColumn('transactions', 'approval_status') && DB::table('transactions')->whereNotNull('approval_status')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: transactions hold transfer approval requests, and rolling back '
                .'would drop who requested and decided them. Restore a backup taken before this migration instead.');
        }
        $mysql = DB::connection()->getDriverName() === 'mysql';

        if (Schema::hasIndex('transactions', self::STATUS_INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::STATUS_INDEX));
        }
        if ($mysql && $this->hasForeignKey('decided_by')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropForeign(['decided_by']));
        }
        if (Schema::hasIndex('transactions', self::DECIDED_BY_FK)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::DECIDED_BY_FK));
        }
        // Native DROP COLUMN (MySQL, SQLite >= 3.35): transactions is not rebuilt.
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('transactions', $column)) {
                DB::statement("ALTER TABLE transactions DROP COLUMN {$column}");
            }
        }

        DB::table('settings')->where('key', self::SETTING)->delete();
        $this->removePermission();
    }

    /** PermissionSeeder::run for one new key: the row, and every "*" template (all keys) gets it. */
    private function addPermission(): void
    {
        if (! Schema::hasTable('permissions') || ! DB::table('permissions')->exists()) {
            return; // fresh install: the seeder creates the catalogue
        }
        $now = now();
        $id = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');
        if ($id === null) {
            $id = DB::table('permissions')->insertGetId(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            $all = collect(PermissionSeeder::TEMPLATES)->filter(fn ($p) => $p === '*')->keys();
            $roles = DB::table('roles')->whereNull('branch_id')->whereIn('name', $all)->pluck('id');
            foreach ($roles as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
        $this->grantMainCopies((int) $id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Review M1: MAIN roles holding every other catalogue key receive the new one (resumable: holders are skipped). */
    private function grantMainCopies(int $permissionId): void
    {
        $main = DB::table('branches')->where('is_main', true)->value('id');
        if ($main === null) {
            return;
        }
        // Keys added to the catalogue after this migration (AG-064 expense.approve, AG-033 account.reconcile, AG-033d
        // account.reconcile_check) do not exist yet when they run in one deploy; requiring them would grant nothing.
        $others = array_values(array_diff(PermissionSeeder::PERMISSIONS, [self::PERMISSION, 'expense.approve', 'account.reconcile', 'account.reconcile_check']));
        $otherIds = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $others)->pluck('id');
        if ($otherIds->count() !== count($others)) {
            return; // catalogue incomplete: leave roles to the Super Admin
        }
        $roles = DB::table('roles as r')->where('r.branch_id', $main)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('role_has_permissions as h')->whereColumn('h.role_id', 'r.id')->where('h.permission_id', $permissionId))
            ->whereRaw('(SELECT COUNT(*) FROM role_has_permissions h2 WHERE h2.role_id = r.id AND h2.permission_id IN ('.$otherIds->implode(',').')) = ?', [count($others)])
            ->orderBy('r.id')->get(['r.id', 'r.name']);
        foreach ($roles as $role) {
            $old = DB::table('role_has_permissions as h')->join('permissions as p', 'p.id', '=', 'h.permission_id')
                ->where('h.role_id', $role->id)->orderBy('p.name')->pluck('p.name')->all();
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $role->id]);
            $new = $old;
            $new[] = self::PERMISSION;
            sort($new);
            AuditLog::create(['user_id' => null, 'branch_id' => $main, 'event' => 'role.updated', 'auditable_type' => Role::class,
                'auditable_id' => $role->id, 'old_values' => ['name' => $role->name, 'permissions' => $old],
                'new_values' => ['name' => $role->name, 'permissions' => $new, 'source' => 'migration transfer_approvals (AG-051)'],
                'ip' => null, 'user_agent' => 'console']);
        }
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
};
