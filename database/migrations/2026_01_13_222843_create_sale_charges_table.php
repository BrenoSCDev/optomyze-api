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
        Schema::create('sale_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            $table->string('name'); // Tax, Service Fee, Expense, etc
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 12, 2); // 10 (%) or 500 (fixed)

            $table->decimal('calculated_amount', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_charges');
    }
};
