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
        Schema::table('whats_app_instances', function (Blueprint $table) {
            $table->foreignId('funnel_id')
                ->nullable()
                ->constrained()
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_app_instances', function (Blueprint $table) {
            $table->dropForeign(['funnel_id']);
            $table->dropColumn('funnel_id');
        });
    }
};
