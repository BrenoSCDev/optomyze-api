<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // PermissionSeeder was removed: the permissions and role_permissions
        // tables it wrote to were dropped by
        // 2026_04_16_000001_drop_permissions_and_role_permissions_tables, so it
        // had been failing on every run.
        $this->call([
            CompanySeeder::class,
            UserSeeder::class,
        ]);
    }
}
