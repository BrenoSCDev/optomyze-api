<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optus assistant conversations move from the browser to the database.
 *
 * History lived in localStorage, so it died with the cache and never followed
 * a user between devices. Two tables rather than one JSON column: messages are
 * appended one at a time, read in order, and will later carry per-message
 * metadata (tool calls, feedback), none of which a blob supports well.
 *
 * `company_id` alongside `user_id` even though a conversation is private to
 * its user: an identity can hold accounts in several companies, and asking
 * about the CRM in one is not the same conversation as asking in another.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('optus_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Titles are for the future conversation list; the current UI runs
            // a single thread per user and leaves this null.
            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // The read path is "this user's most recent conversation".
            $table->index(['user_id', 'company_id', 'last_message_at']);
        });

        Schema::create('optus_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optus_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            // Usage is recorded per assistant turn so spend can be attributed
            // later without re-deriving it from the provider's dashboard.
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            // Room for tool calls and citations without another migration.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['optus_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optus_messages');
        Schema::dropIfExists('optus_conversations');
    }
};
