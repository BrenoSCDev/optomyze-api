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
        Schema::create('lead_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // User who performed the action
            
            // Transaction details
            $table->string('type'); // stage_change, assignment, qualification, contact, note, status_change, etc.
            $table->string('action'); // moved, assigned, qualified, contacted, noted, etc.
            $table->text('description'); // Human readable description of the transaction
            
            // System/automation related
            $table->string('source')->default('system');
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['lead_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_transactions');
    }
};