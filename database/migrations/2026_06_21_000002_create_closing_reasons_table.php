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
        Schema::create('closing_reasons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->onDelete('cascade');

            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // Unused until Phase 2 wires automatic sending.
            $table->text('automatic_closing_message')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreign('closing_reason_id')
                ->references('id')
                ->on('closing_reasons')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropForeign(['closing_reason_id']);
        });

        Schema::dropIfExists('closing_reasons');
    }
};
