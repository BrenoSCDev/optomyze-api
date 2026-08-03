<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read state for the internal team chat.
 *
 * A high-water mark per participant rather than a row per message per reader:
 * a read receipts pivot on a busy group chat grows at members × messages, and
 * the only questions being asked are "how many have I not seen" and "who has
 * caught up to this message" — both answerable from one timestamp.
 *
 * Differs from the Global Inbox on purpose. There, "read" is a property of the
 * message (one company, anyone opening it clears it for everyone). Here every
 * participant has their own unread count, so it has to live on the membership.
 *
 * Existing conversations start fully read: switching this on should not greet
 * everyone with months of backlog marked unread.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('role');
        });

        DB::table('conversation_user')->update(['last_read_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->dropColumn('last_read_at');
        });
    }
};
