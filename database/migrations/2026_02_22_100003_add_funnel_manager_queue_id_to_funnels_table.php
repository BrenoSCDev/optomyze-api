<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnels', function (Blueprint $table) {
            $table->foreignId('funnel_manager_queue_id')
                ->nullable()
                ->after('type')
                ->constrained('funnel_manager_queues')
                ->nullOnDelete();

            $table->index('funnel_manager_queue_id');
        });
    }

    public function down(): void
    {
        Schema::table('funnels', function (Blueprint $table) {
            $table->dropForeign(['funnel_manager_queue_id']);
            $table->dropIndex(['funnels_funnel_manager_queue_id_index']);
            $table->dropColumn('funnel_manager_queue_id');
        });
    }
};
