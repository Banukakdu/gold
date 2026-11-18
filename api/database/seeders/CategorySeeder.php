<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public const TREE = [
        'income' => [
            'Sales' => ['Cash sales', 'Credit sales'],
            'Service income' => ['Consulting', 'Repairs', 'Commissions'],
            'Other income' => ['Interest', 'Rent received', 'Miscellaneous'],
        ],
        'expense' => [
            'Salaries & wages' => ['Salaries', 'Overtime', 'EPF / ETF', 'Bonuses'],
            'Rent' => ['Office rent', 'Warehouse rent'],
            'Utilities' => ['Electricity', 'Water', 'Telephone', 'Internet'],
            'Transport' => ['Fuel', 'Vehicle maintenance', 'Taxi / hire'],
            'Office expenses' => ['Stationery', 'Printing', 'Cleaning', 'Refreshments'],
            'Repairs & maintenance' => ['Building', 'Equipment'],
            'Marketing' => ['Advertising', 'Promotions'],
            'Bank charges' => ['Service charges', 'Interest paid'],
            'Taxes & licences' => ['Licences', 'Government fees'],
            'Miscellaneous' => ['Other'],
        ],
    ];

    public function run(): void
    {
        foreach (self::TREE as $type => $cats) {
            foreach ($cats as $name => $subs) {
                $parent = Category::firstOrCreate(['parent_id' => null, 'branch_id' => null, 'name' => $name], ['type' => $type]);
                foreach ($subs as $sub) {
                    Category::firstOrCreate(['parent_id' => $parent->id, 'branch_id' => null, 'name' => $sub], ['type' => $type]);
                }
            }
        }
    }
}
