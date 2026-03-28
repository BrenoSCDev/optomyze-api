<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service', 'digital') NOT NULL DEFAULT 'product'");
    }

    public function down(): void
    {
        DB::statement("UPDATE products SET type = 'product' WHERE type = 'digital'");
        DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service') NOT NULL DEFAULT 'product'");
    }
};
