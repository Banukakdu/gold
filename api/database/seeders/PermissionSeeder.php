<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Permission catalogue (fixed in code) + global role templates (branch_id NULL). */
class PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'branch.view',
        'user.view', 'user.create', 'user.edit',
        'role.view', 'role.manage',
        'account.view', 'account.manage',
        // AG-033: tick bank lines, complete and undo bank reconciliations of the role's branch.
        'account.reconcile',
        'transfer.create', 'transfer.reverse', 'transfer.acknowledge',
        // AG-051: approve or reject held transfers; it only counts in MAIN (R3). "*" templates only by default.
        'transfer.approve',
        'transaction.view',
        'income.create', 'income.edit', 'income.void',
        'expense.create', 'expense.edit', 'expense.void',
        // AG-064: approve or reject held expenses of the role's own branch (R3). "*" templates only by default (Q11).
        'expense.approve',
        'category.manage',
        'report.dashboard', 'report.view', 'report.export',
        'audit.view',
    ];

    public const TEMPLATES = [
        'Branch Admin' => '*',
        'Accountant' => ['branch.view', 'account.view', 'account.manage', 'transfer.create', 'transfer.reverse', 'transaction.view',
            'income.create', 'income.edit', 'income.void', 'expense.create', 'expense.edit', 'expense.void',
            'report.dashboard', 'report.view', 'report.export',
            // Accounting review finding 6: the main reviewer of the books reads the audit log and classifies entries.
            'audit.view', 'category.manage',
            // AG-052: confirms receipt of transfers into the branch (Q7: also the Cashier, who counts the cash).
            'transfer.acknowledge',
            // AG-033: the Accountant reconciles the bank accounts (new installs; existing branch roles get it on the Roles screen, Q6).
            'account.reconcile'],
        'Cashier' => ['branch.view', 'account.view', 'transaction.view', 'income.create', 'expense.create', 'report.dashboard', 'transfer.acknowledge'],
        'Viewer' => ['branch.view', 'account.view', 'transaction.view', 'report.dashboard', 'report.view', 'audit.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $added = [];
        foreach (self::PERMISSIONS as $p) {
            if (! Permission::where('name', $p)->where('guard_name', 'web')->exists()) {
                $added[] = $p;
            }
            Permission::findOrCreate($p, 'web');
        }
        setPermissionsTeamId(null);
        foreach (self::TEMPLATES as $name => $perms) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'branch_id' => null]);
            // Finding 7: only a new template gets the default permissions; re-seeding never resets Super Admin edits.
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($perms === '*' ? self::PERMISSIONS : $perms);
            } elseif ($perms === '*' && $added) {
                // "All keys" templates receive keys that are new to the catalogue; removed keys are not re-added.
                $role->givePermissionTo($added);
            }
        }
    }
}
