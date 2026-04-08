<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_evo_chats', function (Blueprint $table) {
            $table->string('profile_pic_url')->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_evo_chats', function (Blueprint $table) {
            $table->dropColumn('profile_pic_url');
        });
    }
};
