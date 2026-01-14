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
        Schema::create('whatsapp_evo_chats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('whatsapp_instance_id')
                ->constrained('whats_app_instances')
                ->onDelete('cascade');

            $table->foreignId('lead_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');

            // WhatsApp identifiers
            $table->string('remote_jid')->index(); // 5511999999999@s.whatsapp.net
            $table->string('phone_number')->index(); // 5511999999999

            // Chat state
            $table->boolean('is_group')->default(false);
            $table->boolean('is_archived')->default(false);

            // Useful for inbox sorting
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            // One chat per instance + number
            $table->unique(['whatsapp_instance_id', 'remote_jid']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_evo_chats');
    }
};
