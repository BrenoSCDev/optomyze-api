<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        /*
         * Eloquent, not DB::table()->insert().
         *
         * The raw insert bypassed model events, so the `created` hook on User
         * never fired and the seeded account had no user_preferences row —
         * the one path that could still produce a user without preferences.
         *
         * firstOrCreate keyed on the unique email also makes re-seeding an
         * existing database safe.
         */
        User::firstOrCreate(
            ['email' => 'ceo@optomyze.io'],
            [
                'name'       => 'Breno Castro',
                'password'   => Hash::make('password'),
                'phone'      => '15551111',
                'role'       => 'admin',
                'is_active'  => true,
                'company_id' => $company->id,
            ],
        );
    }
}
