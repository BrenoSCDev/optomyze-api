<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');

            // Polymorphic subject (e.g. 'Lead', 'Sale') — nullable so the log
            // survives a hard delete of the underlying record.
            $table->string('recordable_type');
            $table->unsignedBigInteger('recordable_id')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');

            // Record details
            $table->string('type');
            $table->string('action');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('source')->default('system');

            $table->timestamps();

            // Indexes for performance
            $table->index(['recordable_type', 'recordable_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
