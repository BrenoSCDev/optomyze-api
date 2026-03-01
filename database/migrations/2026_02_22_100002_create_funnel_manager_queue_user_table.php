<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnel_manager_queue_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_manager_queue_id')
                ->constrained('funnel_manager_queues')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->unique(['funnel_manager_queue_id', 'user_id']);
            $table->unique(['funnel_manager_queue_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_manager_queue_user');
    }
};
