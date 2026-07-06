<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('inbox_chat_id')->constrained('inbox_chats')->cascadeOnDelete();
            $table->foreignId('chat_session_id')->nullable()->constrained('chat_sessions')->nullOnDelete();
            $table->string('status');
            $table->unsignedBigInteger('last_inbound_message_id')->nullable();
            $table->json('tool_calls')->nullable();
            $table->text('response_text')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['inbox_chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_executions');
    }
};
