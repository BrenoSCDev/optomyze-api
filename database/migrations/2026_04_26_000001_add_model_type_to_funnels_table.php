<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE funnels MODIFY COLUMN type ENUM('sale', 'deal', 'model') NOT NULL DEFAULT 'sale'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE funnels MODIFY COLUMN type ENUM('sale', 'deal', 'sale_model', 'deal_model') NOT NULL DEFAULT 'sale'");
    }
};
