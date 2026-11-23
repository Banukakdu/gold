<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Support\MigrationPreflight;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * AG-033d "Checked by" on bank reconciliation statements (docs/specs/reconciliation-checked-by.md section 5 "Schema").
 * ADD only, resumable (every step checks first; MySQL commits each DDL statement on its own). Dated after AG-033b's
 * 2026_12_06 migration, so either PR can merge first. Posts nothing; ledger_entries and bank_statements are not touched.
 *  - bank_statement_checks: who checked a completed statement, when, against what, with the counts stored at the check;
 *    one ACTIVE check per statement (active_key, as bank_statements); never deleted, and a row changes only once (an
 *    active check becomes superseded, withdrawn or cancelled), enforced by triggers;
 *  - permission account.reconcile_check where missing, given to the "*" templates and to every branch role that holds
 *    every other key except the MAIN-only transfer.approve (the unchanged Branch Admin copies), with a role.updated audit
 *    row each (the AG-033 pattern). The Viewer template and the branch Viewer roles are left to the Super Admin (release
 *    note). On a fresh install the permission tables are still empty here and the seeder adds the key.
 */
return new class extends Migration
{
    public const PERMISSION = 'account.reconcile_check';

    public const SOURCE = 'rollback reconciliation_checks (AG-033d)';

    public const GRANT_SOURCE = 'migration reconciliation_checks (AG-033d)';

    public const ACTIVE_UNIQUE_MYSQL = 'bank_statement_checks_active_key_unique';

    public const ACTIVE_UNIQUE_SQLITE = 'bank_statement_checks_active_unique';

    /** Keys that only ever counted in MAIN (AG-051): not required of a full copy. */
    public const MAIN_ONLY = ['transfer.approve'];

    /** Columns fixed at the check; only status, ended_*, superseded_by_id and updated_at may change (once). */
    public const FROZEN = ['id', 'branch_id', 'bank_statement_id', 'account_id', 'checked_by', 'checked_at', 'compared_with', 'note',
        'max_entry_id', 'changed_at_check', 'late_count_at_check', 'checker_recorded_count', 'checker_uncleared_count', 'entries_count',
        'after_close', 'created_at'];

    public function up(): void
    {
        MigrationPreflight::assertCanCreateTriggers();
        $mysql = DB::connection()->getDriverName() === 'mysql';

        if (! Schema::hasTable('bank_statement_checks')) {
            Schema::create('bank_statement_checks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $t->foreignId('bank_statement_id')->constrained('bank_statements')->restrictOnDelete();
                $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $t->foreignId('checked_by')->constrained('users')->restrictOnDelete();
                $t->dateTime('checked_at');
                $t->string('compared_with', 20); // bank_statement | internet_banking
                $t->text('note')->nullable();
                // The account's highest ledger line id at the check: a later line dated on or before S needs a re-check (R4).
                $t->unsignedBigInteger('max_entry_id')->default(0);
                $t->boolean('changed_at_check')->default(false);
                $t->unsignedInteger('late_count_at_check')->default(0);
                $t->unsignedInteger('checker_recorded_count')->default(0);
                $t->unsignedInteger('checker_uncleared_count')->default(0);
                $t->unsignedInteger('entries_count')->default(0);
                $t->string('after_close', 10)->nullable(); // 'close' when recorded in a locked month or closed year (R8)
                $t->string('status', 12)->default('active'); // active | superseded | withdrawn | cancelled
                $t->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->dateTime('ended_at')->nullable();
                $t->text('end_reason')->nullable();
                $t->foreignId('superseded_by_id')->nullable()->constrained('bank_statement_checks')->restrictOnDelete();
                $t->timestamps();
                $t->index(['bank_statement_id', 'status']);
                $t->index(['branch_id', 'status']);
            });
        }
        if ($mysql) {
            if (! Schema::hasColumn('bank_statement_checks', 'active_key')) {
                DB::statement("ALTER TABLE bank_statement_checks ADD active_key BIGINT UNSIGNED GENERATED ALWAYS AS
                    (IF(status = 'active', bank_statement_id, NULL)) STORED");
            }
            if (! Schema::hasIndex('bank_statement_checks', self::ACTIVE_UNIQUE_MYSQL)) {
                DB::statement('ALTER TABLE bank_statement_checks ADD UNIQUE '.self::ACTIVE_UNIQUE_MYSQL.' (active_key)');
            }
        } elseif (! Schema::hasIndex('bank_statement_checks', self::ACTIVE_UNIQUE_SQLITE)) {
            DB::statement('CREATE UNIQUE INDEX '.self::ACTIVE_UNIQUE_SQLITE." ON bank_statement_checks (bank_statement_id) WHERE status = 'active'");
        }

        $this->triggers($mysql);
        $this->addPermission();
    }

    public function down(): void
    {
        // A check is the record of an independent sign-off: never dropped with data in it.
        if (Schema::hasTable('bank_statement_checks') && DB::table('bank_statement_checks')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: bank_statement_checks holds checks of bank reconciliation statements, and '
                .'rolling back would drop them. Restore a backup taken before this migration instead.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_checks_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_checks_no_delete');
        Schema::dropIfExists('bank_statement_checks');
        $this->removePermission();
    }

    /**
     * R21 / AC 21: no DELETE; an UPDATE only as the one-time end of an active check (status to superseded, withdrawn or
     * cancelled with ended_by and ended_at, superseded_by_id only on a supersede), or the one-time link of a superseded
     * check to the check that replaced it (Record writes the new check after ending the old one: the one-active-check
     * index allows no other order). Nothing else ever changes.
     */
    private function triggers(bool $mysql): void
    {
        $same = fn (string $c) => $mysql ? "(NEW.{$c} <=> OLD.{$c})" : "(NEW.{$c} IS OLD.{$c})";
        $frozen = implode(' AND ', array_map($same, self::FROZEN));
        $end = "(OLD.status = 'active' AND NEW.status IN ('superseded', 'withdrawn', 'cancelled') AND NEW.ended_by IS NOT NULL "
            ."AND NEW.ended_at IS NOT NULL AND (NEW.status = 'superseded' OR NEW.superseded_by_id IS NULL))";
        $link = "(OLD.status = 'superseded' AND NEW.status = 'superseded' AND OLD.superseded_by_id IS NULL AND NEW.superseded_by_id IS NOT NULL "
            ."AND {$same('ended_by')} AND {$same('ended_at')} AND {$same('end_reason')})";
        $allowed = "({$frozen} AND ({$end} OR {$link}))";
        $update = 'bank_statement_checks rows are evidence: only the one-time end of an active check is allowed.';
        $delete = 'bank_statement_checks rows cannot be deleted.';

        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_checks_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_checks_no_delete');
        if ($mysql) {
            DB::unprepared("CREATE TRIGGER bank_statement_checks_no_update BEFORE UPDATE ON bank_statement_checks FOR EACH ROW
                BEGIN
                    IF NOT {$allowed} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$update}';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER bank_statement_checks_no_delete BEFORE DELETE ON bank_statement_checks FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$delete}';
                END");
        } else {
            DB::unprepared("CREATE TRIGGER bank_statement_checks_no_update BEFORE UPDATE ON bank_statement_checks WHEN NOT {$allowed}
                BEGIN SELECT RAISE(ABORT, '{$update}'); END");
            DB::unprepared("CREATE TRIGGER bank_statement_checks_no_delete BEFORE DELETE ON bank_statement_checks
                BEGIN SELECT RAISE(ABORT, '{$delete}'); END");
        }
    }

    /** PermissionSeeder::run for one new key (the AG-033 addPermission pattern). Resumable. */
    private function addPermission(): void
    {
        if (! Schema::hasTable('permissions') || ! DB::table('permissions')->exists()) {
            return; // fresh install: the seeder creates the catalogue
        }
        $id = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');
        if ($id === null) {
            $now = now();
            $id = DB::table('permissions')->insertGetId(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            $all = collect(PermissionSeeder::TEMPLATES)->filter(fn ($p) => $p === '*')->keys();
            foreach (DB::table('roles')->whereNull('branch_id')->whereIn('name', $all)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
        $this->grantFullCopies((int) $id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Branch roles (any branch) holding every other catalogue key receive the new one, audited (resumable: holders are skipped). */
    private function grantFullCopies(int $permissionId): void
    {
        $others = array_values(array_diff(PermissionSeeder::PERMISSIONS, [self::PERMISSION, ...self::MAIN_ONLY]));
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
                'new_values' => ['name' => $role->name, 'permissions' => $new, 'source' => self::GRANT_SOURCE],
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
        // Every removed grant is audited, as each grant on the Roles screen was (AG-064 review 5).
        $holders = DB::table('roles as r')->join('role_has_permissions as h', 'h.role_id', '=', 'r.id')
            ->whereIn('h.permission_id', $ids)->orderBy('r.id')->get(['r.id', 'r.name', 'r.branch_id']);
        foreach ($holders as $role) {
            $old = DB::table('role_has_permissions as h')->join('permissions as p', 'p.id', '=', 'h.permission_id')
                ->where('h.role_id', $role->id)->orderBy('p.name')->pluck('p.name')->all();
            AuditLog::create(['user_id' => null, 'branch_id' => $role->branch_id, 'event' => 'role.updated', 'auditable_type' => Role::class,
                'auditable_id' => $role->id, 'old_values' => ['name' => $role->name, 'permissions' => $old],
                'new_values' => ['name' => $role->name, 'permissions' => array_values(array_diff($old, [self::PERMISSION])), 'source' => self::SOURCE],
                'ip' => null, 'user_agent' => 'console']);
        }
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
