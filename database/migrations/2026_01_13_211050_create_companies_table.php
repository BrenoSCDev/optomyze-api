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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->enum('subscription_plan', ['basic', 'premium', 'enterprise'])->default('basic');
            $table->unsignedBigInteger('leads_limit')->nullable();
            $table->unsignedInteger('users_limit')->nullable();
            $table->unsignedInteger('sales_funnels_limit')->nullable();
            $table->unsignedInteger('companies_limit')->nullable();
            $table->unsignedInteger('deal_funnels_limit')->nullable();
            $table->unsignedInteger('messages_limit')->nullable();
            $table->unsignedInteger('storage_limit_gb')->nullable();
            $table->unsignedInteger('active_deals_limit')->nullable();
            $table->unsignedBigInteger('tasks_limit')->nullable();
            $table->unsignedInteger('integrations_limit')->nullable();
            $table->unsignedInteger('products_limit')->nullable();
            $table->enum('product_module', ['product', 'ERP'])->default('product');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->unsignedInteger('storage_use')->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['is_active', 'deleted_at']);
            $table->index('subscription_plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
