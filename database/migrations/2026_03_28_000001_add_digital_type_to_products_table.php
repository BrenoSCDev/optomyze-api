<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service', 'digital') NOT NULL DEFAULT 'product'");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE products SET type = 'product' WHERE type = 'digital'");
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service') NOT NULL DEFAULT 'product'");
        }
    }
};
