<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embeddable widgets (website form, chat, booking, …).
 *
 * A single table backs every widget type: `type` selects the behaviour and
 * `config` holds the type-specific schema, so new widget types can ship
 * without new tables or migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type')->default('form');
            // Public identifier used by the embed snippet — never the numeric id.
            $table->string('public_id', 40)->unique();
            $table->string('name');
            $table->enum('status', ['active', 'disabled'])->default('active');

            $table->json('allowed_domains')->nullable();
            $table->json('config');

            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamp('last_submission_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
