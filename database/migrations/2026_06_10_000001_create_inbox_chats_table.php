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
        Schema::create('inbox_chats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->onDelete('cascade');

            // Messaging channel: whatsapp, instagram, messenger, sms, ...
            $table->string('channel')->index();

            // Polymorphic link to the channel-specific instance/integration
            // (e.g. WhatsAppInstance for the 'whatsapp' channel).
            $table->nullableMorphs('channelable');

            $table->foreignId('lead_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');

            // Generic identifiers (e.g. WhatsApp remote_jid / phone_number)
            $table->string('external_chat_id')->index();
            $table->string('external_contact_id')->index();
            $table->string('contact_name')->nullable();

            // Chat state
            $table->boolean('is_group')->default(false);
            $table->boolean('is_archived')->default(false);

            // Useful for inbox sorting
            $table->timestamp('last_message_at')->nullable();
            $table->string('profile_pic_url')->nullable();

            $table->timestamps();

            // One chat per channel instance + external chat id
            $table->unique(
                ['channelable_type', 'channelable_id', 'channel', 'external_chat_id'],
                'inbox_chats_channel_external_chat_unique'
            );
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_chats');
    }
};
