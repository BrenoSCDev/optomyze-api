<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('disabled');
            $table->string('provider')->default('openai');
            $table->string('model')->default('gpt-4o-mini');
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->default(1024);
            $table->longText('system_prompt')->nullable();
            $table->json('enabled_tools')->nullable();
            $table->unsignedSmallInteger('response_delay_seconds')->default(10);
            $table->text('handover_message')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('execution_count')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agents');
    }
};
