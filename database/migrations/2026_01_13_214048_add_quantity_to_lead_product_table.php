<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_product', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lead_product', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit_price', 'total_price']);
        });
    }
};