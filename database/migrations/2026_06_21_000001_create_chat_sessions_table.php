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
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('inbox_chat_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('lead_id')
                ->constrained()
                ->onDelete('cascade');

            // Snapshot of the chat's channel/connected account at session start
            $table->string('channel')->index();
            $table->nullableMorphs('channelable');

            $table->timestamp('started_at');
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->string('status')->default('active')->index();

            // FK constraint added once closing_reasons exists (same migration batch).
            $table->unsignedBigInteger('closing_reason_id')->nullable();
            $table->text('closing_notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['inbox_chat_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
