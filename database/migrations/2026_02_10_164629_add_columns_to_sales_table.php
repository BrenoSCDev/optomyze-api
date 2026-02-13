<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('name');
            $table->foreignId('funnel_id')->constrained()->onDelete('cascade');
            $table->foreignId('stage_id')->constrained()->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropForeign(['funnel_id']);
            $table->dropColumn('funnel_id');
            $table->dropForeign(['stage_id']);
            $table->dropColumn('stage_id');
        });
    }
};
