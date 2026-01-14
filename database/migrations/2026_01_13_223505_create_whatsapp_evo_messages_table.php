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
        Schema::create('whatsapp_evo_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('whatsapp_evo_chat_id')
                ->constrained()
                ->onDelete('cascade');

            // WhatsApp message id (prevents duplicates)
            $table->string('wa_message_id')->index();

            // Message direction
            $table->enum('direction', ['incoming', 'outgoing'])->index();

            // Message type
            $table->enum('type', ['text', 'image', 'audio'])->index();

            // Content
            $table->text('text')->nullable();

            // Media
            $table->string('media_url')->nullable();   // stored file or remote
            $table->string('media_mime')->nullable();  // image/jpeg, audio/ogg
            $table->integer('media_size')->nullable(); // bytes

            // Message status (Evolution / WhatsApp)
            $table->enum('status', [
                'pending',
                'sent',
                'delivered',
                'read',
                'failed'
            ])->default('pending');

            // WhatsApp timestamp
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Prevent duplicate webhook inserts
            $table->unique(['whatsapp_evo_chat_id', 'wa_message_id']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_evo_messages');
    }
};
