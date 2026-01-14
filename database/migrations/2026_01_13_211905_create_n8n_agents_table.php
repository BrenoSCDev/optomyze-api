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
        Schema::create('n8n_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('n8n_integration_id')->constrained()->onDelete('cascade');
            $table->string('workflow_id');
            $table->string('workflow_name')->nullable();
            $table->string('platform'); // e.g., 'whatsapp', 'telegram', 'email', 'sms', etc.
            $table->string('agent_name');
            $table->text('description')->nullable();
            $table->json('configuration')->nullable(); // Store agent-specific config
            $table->json('webhook_data')->nullable(); // Store the last received webhook data
            $table->string('status')->default('active'); // active, inactive, error
            $table->timestamp('last_execution_at')->nullable();
            $table->json('last_execution_result')->nullable();
            $table->integer('execution_count')->default(0);
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['n8n_integration_id', 'status']);
            $table->index(['workflow_id', 'platform']);
            $table->index(['platform', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('n8n_agents');
    }
};