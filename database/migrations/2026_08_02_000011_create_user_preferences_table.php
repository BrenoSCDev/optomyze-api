<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user preferences, previously scattered across five localStorage keys.
 *
 * Browser storage meant a user's setup did not follow them to another machine
 * or survive clearing site data, and support could not see what someone had
 * configured. One row per user, created on first read.
 *
 * Discrete columns rather than a JSON blob: this is a small, known set with
 * enumerable values, so the database can validate them and a query like "how
 * many people use light mode" stays trivial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            // One row per user; the unique constraint is what makes
            // firstOrCreate safe under concurrent requests.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('theme', 10)->default('dark');
            $table->string('language', 5)->default('en');
            $table->boolean('optus_enabled')->default(false);
            $table->string('notification_sound', 40)->default('default');
            $table->string('default_search_target', 20)->default('leads');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
