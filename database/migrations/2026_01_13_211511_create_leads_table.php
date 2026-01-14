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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('funnel_id')->constrained()->onDelete('cascade');
            $table->foreignId('stage_id')->constrained()->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            
            // Lead identification
            $table->string('external_id')->nullable(); // ID from external platform
            $table->string('source_platform')->nullable(); // whatsapp, instagram, telegram, facebook, etc.
            $table->string('source_type')->default('ai_automation'); // ai_automation, manual, web_form, api, etc.
            $table->string('workflow_id')->nullable(); // AI workflow identifier
            $table->string('automation_name')->nullable(); // Name of the automation that captured the lead
            
            // Contact information
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ddi')->nullable();
            $table->string('username')->nullable(); // Platform username (@username)
            $table->string('platform_user_id')->nullable(); // Platform-specific user ID
            
            // Lead details
            $table->string('status')->default('new'); // new, contacted, qualified, unqualified, converted, lost
            $table->string('priority')->default('medium'); // low, medium, high, urgent
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            
            // Communication tracking
            $table->json('contact_methods')->nullable(); // Available contact methods
            $table->timestamp('last_contact_at')->nullable();
            $table->string('preferred_contact_method')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language', 5)->default('en');
            
            // AI and automation data
            $table->json('ai_data')->nullable(); // Data from AI analysis (sentiment, intent, etc.)
            $table->json('conversation_data')->nullable(); // Initial conversation/interaction data
            $table->json('platform_data')->nullable(); // Platform-specific data
            $table->text('initial_message')->nullable(); // First message/inquiry
            $table->decimal('ai_score', 5, 2)->nullable(); // AI-generated lead score (0-100)
            $table->json('tags')->nullable(); // Tags from AI analysis
            
            // Tracking and attribution
            $table->string('referrer')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            
            // Additional metadata
            $table->json('custom_fields')->nullable(); // Flexible custom data
            $table->json('settings')->nullable(); // Lead-specific settings
            $table->text('notes')->nullable(); // Internal notes
            $table->json('erp_budgets_ids')->nullable();
            
            // Status tracking
            $table->boolean('is_active')->default(true);
            $table->boolean('is_qualified')->nullable(); // null = not assessed, true = qualified, false = unqualified
            $table->timestamp('qualified_at')->nullable();
            $table->foreignId('qualified_by')->nullable()->constrained('users')->onDelete('set null');
        
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'funnel_id', 'stage_id']);
            $table->index(['source_platform', 'external_id']);
            $table->index(['assigned_to', 'status']);
            $table->index(['created_at', 'company_id']);
            $table->index(['email', 'company_id']);
            $table->index(['phone', 'company_id']);
            $table->index(['platform_user_id', 'source_platform']);
            $table->index('ai_score');
            $table->index(['priority', 'status']);
            $table->index(['last_contact_at', 'assigned_to']);
            
            // Unique constraints
            $table->unique(['external_id', 'source_platform', 'company_id'], 'unique_external_lead');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};