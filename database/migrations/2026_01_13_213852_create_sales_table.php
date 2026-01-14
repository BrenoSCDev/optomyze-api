<?php

// database/migrations/xxxx_xx_xx_create_sales_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // seller

            $table->enum('status', ['pending', 'sent', 'closed', 'lost'])->default('pending');

            $table->enum('pricing_model', ['product', 'commission', 'hybrid'])->default('product');

            // Commission-related (nullable)
            $table->enum('commission_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('commission_value', 12, 2)->nullable(); 
            $table->decimal('reference_value', 12, 2)->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->string('currency', 10)->default('USD');
            $table->text('notes')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->timestamp('lost_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
