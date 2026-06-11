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
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inbox_chat_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Channel-specific message id (e.g. WhatsApp wa_message_id) - prevents duplicates
            $table->string('external_message_id')->index();

            // Message direction
            $table->enum('direction', ['incoming', 'outgoing'])->index();

            $table->enum('message_mode', ['external', 'internal'])->default('external');

            // Message type
            $table->string('type')->index();

            // Content
            $table->text('text')->nullable();

            // Media
            $table->string('media_url')->nullable();   // stored file or remote
            $table->string('media_mime')->nullable();  // image/jpeg, audio/ogg
            $table->integer('media_size')->nullable(); // bytes

            // Location
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Extra channel-specific data (e.g. contact cards)
            $table->json('metadata')->nullable();

            // Message status (provider-reported)
            $table->enum('status', [
                'pending',
                'sent',
                'delivered',
                'read',
                'failed',
            ])->default('pending');

            // Channel timestamp
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Prevent duplicate webhook inserts
            $table->unique(['inbox_chat_id', 'external_message_id']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
