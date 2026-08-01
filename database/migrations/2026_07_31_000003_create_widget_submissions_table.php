<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw record of every widget interaction — kept even when no lead is created
 * (duplicates, spam) so the company can audit what the widget received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('widget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', ['created', 'duplicate', 'rejected'])->default('created');
            $table->string('rejection_reason')->nullable();

            $table->json('payload')->nullable();
            $table->string('origin')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'widget_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_submissions');
    }
};
