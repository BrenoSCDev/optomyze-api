<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_messenger_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('name');

            // Page connection (Facebook Graph API, page access token)
            $table->text('access_token')->nullable();
            $table->string('page_id')->nullable();
            $table->string('page_name')->nullable();
            $table->text('profile_picture_url')->nullable();
            $table->string('connection_status')->default('not_configured');

            // Webhook handshake
            $table->string('webhook_verify_token')->nullable();
            $table->uuid('webhook_callback_id')->nullable()->unique();
            $table->string('webhook_status')->default('pending');

            $table->foreignId('funnel_id')->nullable()->constrained()->onDelete('cascade');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_messenger_integrations');
    }
};
