<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_token_allowed_origins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allowed_origin_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['api_token_id', 'allowed_origin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token_allowed_origins');
    }
};
