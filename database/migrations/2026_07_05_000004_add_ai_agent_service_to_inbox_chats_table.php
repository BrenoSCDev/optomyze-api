<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_chats', function (Blueprint $table) {
            // True = the AI agent owns the conversation. Inert while the
            // chat's channel account has no enabled agent bound to it.
            $table->boolean('ai_agent_service')->default(true)->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_chats', function (Blueprint $table) {
            $table->dropColumn('ai_agent_service');
        });
    }
};
