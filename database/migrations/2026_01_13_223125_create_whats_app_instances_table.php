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
        Schema::create('whats_app_instances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_evo_integration_id')->constrained()->cascadeOnDelete();

            $table->string('instance_name');
            $table->string('label')->nullable(); // "Sales", "Support"
            $table->string('phone_number')->nullable();

            $table->enum('status', [
                'created',
                'qrcode',
                'connected',
                'disconnected',
                'error'
            ])->default('created');

            $table->json('metadata')->nullable();
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'instance_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whats_app_instances');
    }
};
