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
        Schema::create('welcome_message_configs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            // Polymorphic link to the channel-specific instance/integration
            // (e.g. WhatsAppInstance, InstagramIntegration, FacebookMessengerIntegration).
            $table->nullableMorphs('channelable');

            $table->string('channel');
            $table->boolean('is_enabled')->default(false);
            $table->text('message')->nullable();

            $table->timestamps();

            $table->unique(
                ['channelable_type', 'channelable_id'],
                'welcome_message_configs_channelable_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('welcome_message_configs');
    }
};
