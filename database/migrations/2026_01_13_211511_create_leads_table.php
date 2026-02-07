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
            $table->string('source_type')->default('Manual'); // ai_automation, manual, web_form, api, etc.
            
            // Contact information
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ddi')->nullable();
            $table->string('username')->nullable(); // Platform username (@username)
            
            // Lead details
            $table->string('priority')->default('Medium'); // low, medium, high, urgent
            $table->string('currency', 3)->default('USD');
            
            // Tracking
            $table->timestamp('last_contact_at')->nullable();
            $table->string('preferred_contact_method')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language', 5)->default('en');
            $table->decimal('ai_score', 5, 2)->nullable(); // AI-generated lead score (0-100)
            $table->json('tags')->nullable(); // Tags from
            $table->text('notes')->nullable(); // Internal notes
            $table->json('erp_budgets_ids')->nullable();
            
            // Status tracking
            $table->boolean('is_active')->default(true);
            $table->boolean('is_qualified')->nullable(); // null = not assessed, true = qualified, false = unqualified
            $table->timestamp('qualified_at')->nullable();
            $table->foreignId('qualified_by')->nullable()->constrained('users')->onDelete('set null');
        
            // Address information
            $table->string('address_line_1')->nullable(); // Street, number
            $table->string('address_line_2')->nullable(); // Apt, suite, unit, etc
            $table->string('neighborhood')->nullable(); // Bairro
            $table->string('city')->nullable();
            $table->string('state')->nullable(); // State / Province
            $table->string('state_code', 10)->nullable(); // SP, CA, NY, etc
            $table->string('postal_code')->nullable(); // ZIP / CEP
            $table->string('country')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['company_id']);
            $table->index(['company_id', 'funnel_id', 'stage_id']);
            $table->index(['source_platform', 'external_id']);
            $table->index(['assigned_to']);
            $table->index(['created_at', 'company_id']);
            $table->index(['email', 'company_id']);
            $table->index(['phone', 'company_id']);
            $table->index(['priority']);
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