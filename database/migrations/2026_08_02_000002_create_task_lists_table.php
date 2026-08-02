<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lists live inside a space and own their own status set, so "Prospecting"
 * can run Lead In / Contacted / Qualified while "Onboarding" runs a totally
 * different board. That per-list customisation is the main thing separating
 * this from the old fixed four-status enum.
 *
 * company_id is denormalised from the parent space so every tenancy scope can
 * be a plain column filter instead of a two-level whereHas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('task_space_id')->constrained('task_spaces')->cascadeOnDelete();

            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('color', 7)->default('#6366f1');
            $table->unsignedInteger('order')->default(0);

            // The view the list opens on: list | board | calendar.
            $table->string('default_view', 20)->default('list');

            $table->timestamp('archived_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
            $table->index(['task_space_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_lists');
    }
};
