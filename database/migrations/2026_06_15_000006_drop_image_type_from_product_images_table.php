<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'image_type']);
            $table->dropColumn('image_type');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->enum('image_type', ['thumbnail', 'gallery', 'cover', 'attachment'])->default('gallery');
            $table->index(['product_id', 'image_type']);
        });
    }
};
