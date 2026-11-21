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
 * AG-033 bank reconciliation (docs/specs/bank-reconciliation.md section 5 "Schema"). ADD only, resumable (every step
 * checks first; MySQL commits each DDL statement on its own):
 *  - bank_statements: completed reconciliations of one bank account up to a statement date, with the figures stored at
 *    completion; one ACTIVE (completed) statement per (account, date) enforced by the database (active_key, as
 *    period_locks);
 *  - bank_reconciliation_marks: the ticks (cleared dates) on bank ledger lines; one ACTIVE mark per line (active_key);
 *  - permission account.reconcile where missing, given to the "*" templates and (review finding 4, as AG-064) to every
 *    branch role that holds every other key except the MAIN-only transfer.approve (the Branch Admin copies), with a
 *    role.updated audit row each. A copy the Super Admin narrowed, and every Accountant copy, are left to the Super Admin
 *    (release note). On a fresh install the permission tables are still empty here and the seeder adds the key (and the
 *    Accountant template gets it there).
 * Nothing posts money and ledger_entries is not touched. Both tables are dropped whole on rollback, so no index is ever
 * dropped on its own (the composite indexes that start with a foreign-key column go with their table). The active_key
 * column and its unique index are separate steps, each checked on its own (review finding 5: MySQL commits each DDL).
 */
return new class extends Migration
{
    public const PERMISSION = 'account.reconcile';

    public const SOURCE = 'rollback bank_reconciliation (AG-033)';

    public const MARKS_UNIQUE = 'bank_reconciliation_marks_active_key_unique';

    public const GRANT_SOURCE = 'migration bank_reconciliation (AG-033)';

    /** Keys that only ever counted in MAIN (AG-051): not required of a full copy. */
    public const MAIN_ONLY = ['transfer.approve'];

    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        if (! Schema::hasTable('bank_statements')) {
            Schema::create('bank_statements', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $t->date('statement_date');
                $t->bigInteger('statement_balance_cents'); // signed: an overdrawn balance is negative
                // Figures stored at completion (R6, R3 at S).
                $t->bigInteger('reconciled_cents');
                $t->bigInteger('book_balance_cents');
                $t->bigInteger('uncleared_receipts_cents');
                $t->bigInteger('uncleared_payments_cents');
                $t->bigInteger('cleared_booked_later_cents'); // signed (review finding 1)
                $t->unsignedInteger('self_count');
                $t->unsignedInteger('lines_count');
                $t->string('status', 10); // completed | undone
                $t->foreignId('completed_by')->constrained('users')->restrictOnDelete();
                $t->timestamp('completed_at');
                $t->foreignId('undone_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp('undone_at')->nullable();
                $t->text('undo_reason')->nullable();
                // Review finding 7: the account's highest ledger line id at completion; a line with a higher id dated on
                // or before the statement date is a late entry (no timestamp boundary).
                $t->unsignedBigInteger('max_entry_id')->default(0);
                $t->timestamps();
                $t->index(['account_id', 'status', 'statement_date']);
            });
        }
        if ($mysql) {
            if (! Schema::hasColumn('bank_statements', 'active_key')) {
                DB::statement("ALTER TABLE bank_statements ADD active_key VARCHAR(40) GENERATED ALWAYS AS
                    (IF(status = 'completed', CONCAT(account_id, ':', statement_date), NULL)) STORED");
            }
            if (! Schema::hasIndex('bank_statements', 'bank_statements_active_key_unique')) {
                DB::statement('ALTER TABLE bank_statements ADD UNIQUE bank_statements_active_key_unique (active_key)');
            }
        } elseif (! Schema::hasIndex('bank_statements', 'bank_statements_active_unique')) {
            DB::statement("CREATE UNIQUE INDEX bank_statements_active_unique ON bank_statements (account_id, statement_date) WHERE status = 'completed'");
        }

        if (! Schema::hasTable('bank_reconciliation_marks')) {
            Schema::create('bank_reconciliation_marks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $t->foreignId('ledger_entry_id')->constrained('ledger_entries')->restrictOnDelete();
                $t->date('cleared_date');
                // Set when a statement completes; the mark is frozen while it points to a `completed` statement.
                $t->foreignId('statement_id')->nullable()->constrained('bank_statements')->restrictOnDelete();
                $t->foreignId('marked_by')->constrained('users')->restrictOnDelete();
                $t->timestamp('marked_at');
                $t->foreignId('unmarked_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp('unmarked_at')->nullable();
                $t->timestamps();
                $t->index(['account_id', 'unmarked_at', 'cleared_date'], 'bank_recon_marks_account_unmarked_cleared_index'); // MySQL names are at most 64 characters
            });
        }
        if ($mysql) {
            if (! Schema::hasColumn('bank_reconciliation_marks', 'active_key')) {
                DB::statement('ALTER TABLE bank_reconciliation_marks ADD active_key BIGINT UNSIGNED GENERATED ALWAYS AS
                    (IF(unmarked_at IS NULL, ledger_entry_id, NULL)) STORED');
            }
            if (! Schema::hasIndex('bank_reconciliation_marks', self::MARKS_UNIQUE)) {
                DB::statement('ALTER TABLE bank_reconciliation_marks ADD UNIQUE '.self::MARKS_UNIQUE.' (active_key)');
            }
        } elseif (! Schema::hasIndex('bank_reconciliation_marks', 'bank_reconciliation_marks_active_unique')) {
            DB::statement('CREATE UNIQUE INDEX bank_reconciliation_marks_active_unique ON bank_reconciliation_marks (ledger_entry_id) WHERE unmarked_at IS NULL');
        }

        $this->addPermission();
    }

    public function down(): void
    {
        // Ticks and completed statements are the only record of the reconciliations (and of who signed them).
        foreach (['bank_statements', 'bank_reconciliation_marks'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback stopped before any change: {$table} holds bank reconciliation records, and rolling back "
                    .'would drop them. Restore a backup taken before this migration instead.');
            }
        }
        // Marks reference statements: drop them first. Dropping a table drops its indexes and foreign keys together.
        Schema::dropIfExists('bank_reconciliation_marks');
        Schema::dropIfExists('bank_statements');
        $this->removePermission();
    }

    /**
     * PermissionSeeder::run for one new key: the row, and every "*" template (all keys) gets it; then the full branch
     * copies (review finding 4). Resumable: each step skips what is already there.
     */
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
