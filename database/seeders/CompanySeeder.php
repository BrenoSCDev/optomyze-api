<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('companies')->insert([
            'name' => 'Optomyze',
            'email' => 'optomyze@optomyze.io',
            'phone' => '+1-555-0101',
            'website' => 'https://optomyze.com',
            'subscription_plan' => 'premium',
            'is_active' => true,

            // Premium plan limits
            'leads_limit' => 25000,
            'users_limit' => 10,
            'storage_limit_gb' => 50,
            'active_deals_limit' => 500,
            'tasks_limit' => 25000,
            'integrations_limit' => 10,
            'products_limit' => 500,
            'product_module' => 'product',

            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
