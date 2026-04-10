<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 7)->nullable(); // hex color
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::create('product_category_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_category_id')->constrained()->onDelete('cascade');
            $table->primary(['product_id', 'product_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category_product');
        Schema::dropIfExists('product_categories');
    }
};
