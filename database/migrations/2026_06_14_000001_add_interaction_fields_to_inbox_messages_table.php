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
        Schema::table('inbox_messages', function (Blueprint $table) {
            // Reply / quoted-message context
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->after('metadata')
                ->constrained('inbox_messages')
                ->nullOnDelete();

            // Snapshot of the quoted message content (survives even if the
            // original message isn't stored, e.g. it predates the chat history)
            $table->json('reply_to_snapshot')->nullable()->after('reply_to_message_id');

            // Emoji reactions keyed by reactor ('contact' | 'agent')
            $table->json('reactions')->nullable()->after('reply_to_snapshot');

            // Set when WhatsApp reports this message was edited.
            // The new content itself is E2E-encrypted and cannot be recovered.
            $table->boolean('is_edited')->default(false)->after('reactions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropColumn(['reply_to_snapshot', 'reactions', 'is_edited']);
        });
    }
};
