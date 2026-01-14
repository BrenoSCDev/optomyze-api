<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        DB::table('users')->insert([
            'name' => 'Breno Castro',
            'email' => 'ceo@optomyze.io',
            'password' => Hash::make('password'),
            'phone' => '+1-555-1111',
            'role' => 'admin',
            'is_active' => true,
            'company_id' => $company->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
