<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_product_field_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            // null = model-level (default for all new products)
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('icon')->nullable(); // react-icons identifier e.g. "FaTools"
            $table->boolean('is_default')->default(false); // default groups created for every product
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_product_field_groups');
    }
};
