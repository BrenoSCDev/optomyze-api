<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_evo_messages', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('whatsapp_evo_chat_id')
                ->constrained()
                ->nullOnDelete();

            $table->enum('message_mode', ['external', 'internal'])
                ->default('external')
                ->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_evo_messages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'message_mode']);
        });
    }
};
