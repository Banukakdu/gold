<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Permission catalogue (fixed in code) + global role templates (branch_id NULL). */
class PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'branch.view',
        'user.view', 'user.create', 'user.edit',
        'role.view', 'role.manage',
        'account.view', 'account.manage',
        'transfer.create', 'transfer.reverse',
        'transaction.view',
        'income.create', 'income.edit', 'income.void',
        'expense.create', 'expense.edit', 'expense.void',
        'category.manage',
        'report.dashboard', 'report.view', 'report.export',
        'audit.view',
    ];

    public const TEMPLATES = [
        'Branch Admin' => '*',
        'Accountant' => ['branch.view', 'account.view', 'account.manage', 'transfer.create', 'transfer.reverse', 'transaction.view',
            'income.create', 'income.edit', 'income.void', 'expense.create', 'expense.edit', 'expense.void',
            'report.dashboard', 'report.view', 'report.export'],
        'Cashier' => ['branch.view', 'account.view', 'transaction.view', 'income.create', 'expense.create', 'report.dashboard'],
        'Viewer' => ['branch.view', 'account.view', 'transaction.view', 'report.dashboard', 'report.view', 'audit.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        setPermissionsTeamId(null);
        foreach (self::TEMPLATES as $name => $perms) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'branch_id' => null]);
            $role->syncPermissions($perms === '*' ? self::PERMISSIONS : $perms);
        }
    }
}
