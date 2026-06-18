<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();

            // Correlation id, also echoed back to the caller as X-Request-Id
            $table->uuid('request_id')->unique();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('method', 10);
            // Route URI pattern (e.g. "api/v1/leads/{id}") - stable for grouping/filtering
            $table->string('endpoint');
            // Actual requested path + query string, for display
            $table->string('full_url', 2048)->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->json('request_headers')->nullable();
            $table->json('query_params')->nullable();
            $table->json('request_body')->nullable();

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->enum('status', ['success', 'failure'])->default('success');

            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();
            $table->text('exception_trace')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'response_status']);
            $table->index(['company_id', 'api_token_id']);
            $table->index(['company_id', 'method']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
