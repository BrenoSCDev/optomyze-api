<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous website-chat visitors.
 *
 * A visitor exists from the moment the widget is opened — before any message,
 * lead or conversation. The browser keeps the secret `token`; the CRM only
 * ever sees the public `visitor_id`. Once the visitor sends a message the row
 * is linked to the lead and the InboxChat that carries the conversation, so a
 * page refresh (or a return visit) resumes the same thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_chat_visitors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('widget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inbox_chat_id')->nullable()->constrained()->nullOnDelete();

            // Public identifier (stored on the chat) vs. the browser's secret.
            $table->string('visitor_id', 40)->unique();
            $table->string('token', 80)->unique();

            // Whatever the visitor told us, when identification is enabled.
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('identified')->default(false);

            // Lightweight context for the agent and for future analytics.
            $table->string('page_url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'widget_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_chat_visitors');
    }
};
