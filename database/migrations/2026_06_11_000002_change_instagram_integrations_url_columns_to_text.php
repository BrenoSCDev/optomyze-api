<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_integrations', function (Blueprint $table) {
            $table->text('access_token')->nullable()->change();
            $table->text('profile_picture_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('instagram_integrations', function (Blueprint $table) {
            $table->string('access_token')->nullable()->change();
            $table->string('profile_picture_url')->nullable()->change();
        });
    }
};
