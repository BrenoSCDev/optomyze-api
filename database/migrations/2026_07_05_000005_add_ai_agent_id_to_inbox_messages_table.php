<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->foreignId('ai_agent_id')->nullable()->after('user_id')
                ->constrained('ai_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_agent_id');
        });
    }
};
