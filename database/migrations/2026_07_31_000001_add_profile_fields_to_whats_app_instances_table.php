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
        Schema::table('whats_app_instances', function (Blueprint $table) {
            $table->string('profile_name')->nullable()->after('label');
            $table->text('profile_pic_url')->nullable()->after('profile_name');
            $table->string('owner_jid')->nullable()->after('phone_number');
            $table->timestamp('status_checked_at')->nullable()->after('connected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_app_instances', function (Blueprint $table) {
            $table->dropColumn(['profile_name', 'profile_pic_url', 'owner_jid', 'status_checked_at']);
        });
    }
};
