<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            // Explicit origin of a message, so the UI can tell apart the
            // otherwise identical (user_id null, ai_agent_id null) cases:
            // contact | user | ai_agent | automation | external.
            $table->string('source')->nullable()->after('ai_agent_id');
        });

        // Backfill existing rows from what we can infer. Outbound messages
        // with neither a user nor an AI agent are, in practice, sent from
        // the connected account outside the CRM (device-synced) — the rare
        // historical welcome/closing message is acceptably mislabeled.
        DB::table('inbox_messages')->where('direction', 'incoming')->update(['source' => 'contact']);
        DB::table('inbox_messages')->where('direction', 'outgoing')->whereNotNull('ai_agent_id')->update(['source' => 'ai_agent']);
        DB::table('inbox_messages')->where('direction', 'outgoing')->whereNull('ai_agent_id')->whereNotNull('user_id')->update(['source' => 'user']);
        DB::table('inbox_messages')->where('direction', 'outgoing')->whereNull('ai_agent_id')->whereNull('user_id')->whereNull('source')->update(['source' => 'external']);
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
