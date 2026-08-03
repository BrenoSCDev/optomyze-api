<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Favouriting for inbox conversations, plus a starting read state.
 *
 * Archiving already exists (inbox_chats.is_archived); only the pin is new.
 * The composite index matches the list's ordering — favourites first, then
 * newest activity — so the sort stays a single index scan.
 *
 * Read state itself needs no new column: inbox_messages.status is already an
 * enum containing 'read', used today for outbound WhatsApp/Instagram receipts.
 * An inbound message counts as unread until someone opens the conversation,
 * which flips the same column. Historic inbound messages are marked read here
 * so switching this on doesn't confront everyone with months of backlog.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('inbox_chats', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('is_archived');
            $table->index(['company_id', 'is_favorite', 'last_message_at'], 'inbox_chats_company_pinned_recent_idx');
        });

        DB::table('inbox_messages')
            ->where('direction', 'incoming')
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);
    }

    public function down(): void
    {
        Schema::table('inbox_chats', function (Blueprint $table) {
            $table->dropIndex('inbox_chats_company_pinned_recent_idx');
            $table->dropColumn('is_favorite');
        });
    }
};
