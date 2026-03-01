<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Migrate any existing 'in_pipeline' rows to 'pending' before changing the enum
        DB::statement("UPDATE sales SET status = 'pending' WHERE status = 'in_pipeline'");

        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM('pending', 'sent', 'closed', 'lost') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE sales SET status = 'in_pipeline' WHERE status = 'pending'");
        DB::statement("UPDATE sales SET status = 'in_pipeline' WHERE status = 'sent'");

        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM('in_pipeline', 'closed', 'lost') NOT NULL DEFAULT 'in_pipeline'");
    }
};
