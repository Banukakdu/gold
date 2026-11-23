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
 * AG-064 expense approvals (docs/specs/expense-approvals.md section 5 "Schema"). ADD only, resumable (every step
 * checks first):
 *  - branches.expense_approval_threshold_cents unsigned bigint null (integer cents; null = never, the default);
 *  - permission expense.approve where missing, given to the "*" templates as PermissionSeeder::run does for a key new
 *    to the catalogue (on a fresh install the permission tables are still empty and the seeder adds it);
 *  - every branch role that holds every other catalogue key except the MAIN-only ones (an unmodified Branch Admin copy:
 *    a KDY copy made before AG-051 never got transfer.approve, which AG-051 gave to MAIN copies only) gets the key too,
 *    with a role.updated audit row each. A copy the Super Admin narrowed is left as it is;
 *  - index (created_by, category_id, txn_date) for the R2b same-day locking read (narrow next-key locks on MySQL).
 * The transactions approval columns and the (approval_status, branch_id) index exist since AG-051.
 */
return new class extends Migration
{
    public const PERMISSION = 'expense.approve';

    public const COLUMN = 'expense_approval_threshold_cents';

    public const SOURCE = 'migration expense_approvals (AG-064)';

    /** Keys that only ever counted in MAIN and were granted to MAIN copies only (AG-051): not required of a full copy. */
    public const MAIN_ONLY = ['transfer.approve'];

    /**
     * Keys added to the catalogue after this migration (AG-033, AG-033d). On an upgrade from before AG-051 that runs this
     * and the later migrations in one deploy they do not exist yet, so requiring them would make the catalogue look
     * incomplete and grant nothing.
     */
    public const LATER = ['account.reconcile', 'account.reconcile_check'];

    public const SAME_DAY_INDEX = 'transactions_created_by_category_txn_date_index';

    public function up(): void
    {
        if (! Schema::hasColumn('branches', self::COLUMN)) {
            Schema::table('branches', fn (Blueprint $t) => $t->unsignedBigInteger(self::COLUMN)->nullable());
        }
        if (! Schema::hasIndex('transactions', self::SAME_DAY_INDEX)) {
            Schema::table('transactions', fn (Blueprint $t) => $t->index(['created_by', 'category_id', 'txn_date'], self::SAME_DAY_INDEX));
        }
        $this->addPermission();
    }

    public function down(): void
    {
        // The approval columns of a held expense are the only record of who requested and decided it.
        if (Schema::hasColumn('transactions', 'approval_status')
            && DB::table('transactions')->where('type', 'expense')->whereNotNull('approval_status')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: transactions hold expense approval requests, and rolling back '
                .'would remove the limit and the permission that decided them. Restore a backup taken before this migration instead.');
        }
        // A limit set by a Super Admin is configuration that a rollback would silently drop (review 5).
        if (Schema::hasColumn('branches', self::COLUMN) && DB::table('branches')->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Rollback stopped before any change: branches have an expense approval limit. '
                .'Clear the limits on the Branches screen first (each change is audited), then roll back.');
        }
        if (Schema::hasIndex('transactions', self::SAME_DAY_INDEX)) {
            // MySQL drops the implicit index behind the created_by foreign key once this composite index (which starts
            // with created_by) exists, and then refuses to drop the composite (error 1553). Put the foreign key's own
            // index back first, under the name the original migration gave it.
            if (DB::getDriverName() === 'mysql'
                && ! Schema::hasIndex('transactions', 'transactions_created_by_foreign')
                && ! Schema::hasIndex('transactions', ['created_by'])) {
                Schema::table('transactions', fn (Blueprint $t) => $t->index('created_by', 'transactions_created_by_foreign'));
            }
            Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(self::SAME_DAY_INDEX));
        }
        if (Schema::hasColumn('branches', self::COLUMN)) {
            DB::statement('ALTER TABLE branches DROP COLUMN '.self::COLUMN);
        }
        $this->removePermission();
    }

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
            foreach (DB::table('roles')->whereNull('branch_id')->whereIn('name', $all)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
        $this->grantFullCopies((int) $id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Branch roles (any branch) holding every other catalogue key receive the new one (resumable: holders are skipped). */
    private function grantFullCopies(int $permissionId): void
    {
        $others = array_values(array_diff(PermissionSeeder::PERMISSIONS, [self::PERMISSION, ...self::MAIN_ONLY, ...self::LATER]));
        $otherIds = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $others)->pluck('id');
        if ($otherIds->count() !== count($others)) {
            return; // catalogue incomplete: leave roles to the Super Admin
        }
        $roles = DB::table('roles as r')->whereNotNull('r.branch_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('role_has_permissions as h')->whereColumn('h.role_id', 'r.id')->where('h.permission_id', $permissionId))
            ->whereRaw('(SELECT COUNT(*) FROM role_has_permissions h2 WHERE h2.role_id = r.id AND h2.permission_id IN ('.$otherIds->implode(',').')) = ?', [count($others)])
            ->orderBy('r.id')->get(['r.id', 'r.name', 'r.branch_id']);
        foreach ($roles as $role) {
            $old = DB::table('role_has_permissions as h')->join('permissions as p', 'p.id', '=', 'h.permission_id')
                ->where('h.role_id', $role->id)->orderBy('p.name')->pluck('p.name')->all();
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $role->id]);
            $new = $old;
            $new[] = self::PERMISSION;
            sort($new);
            AuditLog::create(['user_id' => null, 'branch_id' => $role->branch_id, 'event' => 'role.updated', 'auditable_type' => Role::class,
                'auditable_id' => $role->id, 'old_values' => ['name' => $role->name, 'permissions' => $old],
                'new_values' => ['name' => $role->name, 'permissions' => $new, 'source' => self::SOURCE],
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
        // Review 5: every removed grant is audited, as each grant was.
        $holders = DB::table('roles as r')->join('role_has_permissions as h', 'h.role_id', '=', 'r.id')
            ->whereIn('h.permission_id', $ids)->orderBy('r.id')->get(['r.id', 'r.name', 'r.branch_id']);
        foreach ($holders as $role) {
            $old = DB::table('role_has_permissions as h')->join('permissions as p', 'p.id', '=', 'h.permission_id')
                ->where('h.role_id', $role->id)->orderBy('p.name')->pluck('p.name')->all();
            AuditLog::create(['user_id' => null, 'branch_id' => $role->branch_id, 'event' => 'role.updated', 'auditable_type' => Role::class,
                'auditable_id' => $role->id, 'old_values' => ['name' => $role->name, 'permissions' => $old],
                'new_values' => ['name' => $role->name, 'permissions' => array_values(array_diff($old, [self::PERMISSION])),
                    'source' => 'rollback expense_approvals (AG-064)'],
                'ip' => null, 'user_agent' => 'console']);
        }
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
