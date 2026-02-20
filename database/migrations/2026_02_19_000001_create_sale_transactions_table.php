<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_transactions', function (Blueprint $table) {
            $table->id();

            // Nullable so the log is preserved when a sale is hard-deleted
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('set null');

            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');

            // Transaction details
            $table->string('type');        // stage_change, status_change, creation, deletion, update
            $table->string('action');      // moved, won, lost, closed, created, deleted, updated
            $table->text('description');   // Human-readable description
            $table->string('source')->default('system'); // manual, system, automation, api

            $table->timestamps();

            // Indexes for performance
            $table->index(['sale_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_transactions');
    }
};
