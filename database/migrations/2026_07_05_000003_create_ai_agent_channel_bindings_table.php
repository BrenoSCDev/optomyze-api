<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_channel_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('channelable_type');
            $table->unsignedBigInteger('channelable_id');
            $table->timestamps();

            // One agent per channel account across the whole system.
            $table->unique(['channelable_type', 'channelable_id'], 'ai_agent_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_channel_bindings');
    }
};
