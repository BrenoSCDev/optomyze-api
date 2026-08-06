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
        Schema::table('inbox_chats', function (Blueprint $table) {
            // The chat address exactly as the provider reports it, learned
            // from inbound webhooks.
            //
            // Distinct from external_chat_id, which for WhatsApp we *synthesise*
            // as "<normalized digits>@s.whatsapp.net" so a chat can be matched
            // across the Brazilian 8-/9-digit format variants (see
            // InboxChat::findWhatsApp). That synthesised form is fine as a
            // lookup key but is not guaranteed to be the JID WhatsApp actually
            // uses for the contact — and endpoints that address a message by
            // its full key (sendReaction) need the real one, or they silently
            // apply the reaction to nothing and still answer 200.
            $table->string('provider_jid')->nullable()->after('external_chat_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbox_chats', function (Blueprint $table) {
            $table->dropColumn('provider_jid');
        });
    }
};
