<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('storage_path');
            $table->string('filename');
            $table->enum('image_type', ['thumbnail', 'gallery', 'cover', 'attachment'])->default('gallery');
            $table->integer('display_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('alt_text')->nullable();
            $table->integer('file_size')->nullable(); // in bytes
            $table->string('mime_type', 50)->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'image_type']);
            $table->index(['product_id', 'is_primary']);
            $table->index(['product_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};