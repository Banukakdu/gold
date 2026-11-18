<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PermissionSeeder::class, CategorySeeder::class]);

        // AG-010: the Main branch with its Main Account
        $main = Branch::firstOrCreate(['is_main' => true], ['code' => 'MAIN', 'name' => 'Main Branch', 'status' => 'active']);
        Account::withoutGlobalScopes()->firstOrCreate(
            ['branch_id' => $main->id, 'is_main_account' => true],
            ['name' => 'Main Account', 'type' => 'bank']
        );

        $admin = User::firstOrNew(['email' => env('ADMIN_EMAIL', 'admin@accountgold.local')]);
        if (! $admin->exists) {
            $admin->fill(['name' => 'Super Admin', 'password' => env('ADMIN_PASSWORD', 'ChangeMe123'), 'status' => 'active']);
            $admin->is_super_admin = true;
            $admin->save();
        }

        foreach (['company_name' => 'Account-gold', 'currency' => 'LKR', 'expense_negative_balance' => 'block'] as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
