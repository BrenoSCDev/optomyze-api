<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('field_key');
            $table->enum('field_type', ['title', 'text', 'number', 'boolean', 'json', 'date']);
            $table->text('field_value')->nullable();
            $table->foreignId('lead_id')
                ->nullable()
                ->constrained()
                ->onDelete('cascade');
            $table->enum('type', ['model', 'lead'])->default('lead');
            $table->timestamps();

            $table->index(['company_id', 'type']);
            $table->index(['lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_custom_fields');
    }
};
