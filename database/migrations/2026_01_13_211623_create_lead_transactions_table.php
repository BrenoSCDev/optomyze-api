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
            
            // Before/After data for changes
            $table->json('previous_data')->nullable(); // Data before the change
            $table->json('current_data')->nullable(); // Data after the change
            $table->json('metadata')->nullable(); // Additional context data
            
            // Specific transaction fields
            $table->foreignId('from_stage_id')->nullable()->constrained('stages')->onDelete('set null');
            $table->foreignId('to_stage_id')->nullable()->constrained('stages')->onDelete('set null');
            $table->foreignId('assigned_from')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            
            // Contact/Communication related
            $table->string('contact_method')->nullable(); // email, phone, whatsapp, etc.
            $table->text('message')->nullable(); // Message content if applicable
            $table->string('communication_direction')->nullable(); // inbound, outbound
            $table->json('communication_data')->nullable(); // Additional communication context
            
            // Status and priority changes
            $table->string('previous_status')->nullable();
            $table->string('current_status')->nullable();
            $table->string('previous_priority')->nullable();
            $table->string('current_priority')->nullable();
            
            // Qualification tracking
            $table->boolean('previous_qualification')->nullable();
            $table->boolean('current_qualification')->nullable();
            $table->text('qualification_reason')->nullable();
            
            // Value and scoring changes
            $table->decimal('previous_estimated_value', 15, 2)->nullable();
            $table->decimal('current_estimated_value', 15, 2)->nullable();
            $table->decimal('previous_ai_score', 5, 2)->nullable();
            $table->decimal('current_ai_score', 5, 2)->nullable();
            
            // System/automation related
            $table->string('source')->default('manual'); // manual, system, automation, api, webhook
            $table->string('trigger')->nullable(); // What triggered this transaction
            $table->boolean('is_automated')->default(false);
            $table->string('automation_id')->nullable(); // ID of automation that triggered this
            
            // Tracking and attribution
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->json('context')->nullable(); // Additional context (UI state, etc.)
            
            // Visibility and notifications
            $table->boolean('is_visible')->default(true); // Show in activity timeline
            $table->boolean('is_important')->default(false); // Highlight in UI
            $table->boolean('notifications_sent')->default(false);
            $table->json('notification_data')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['lead_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'action']);
            $table->index(['lead_id', 'type']);
            $table->index(['is_visible', 'is_important']);
            $table->index(['source', 'is_automated']);
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