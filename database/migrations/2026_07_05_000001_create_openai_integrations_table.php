<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('openai_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('name')->default('ChatGPT (OpenAI)');
            $table->text('api_key')->nullable();
            $table->string('organization_id')->nullable();
            $table->string('connection_status')->default('not_configured');
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openai_integrations');
    }
};
