<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom, per-list statuses — the board columns.
 *
 * `type` is the semantic bucket the rest of the CRM reasons about, so reports,
 * the legacy `tasks.status` enum and "is this done?" checks keep working no
 * matter what a company names its columns:
 *
 *   not_started -> pending       active -> in_progress
 *   done        -> completed     closed -> cancelled
 *
 * Exactly one status per list should carry is_default (where new tasks land).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('task_list_id')->constrained('task_lists')->cascadeOnDelete();

            $table->string('name', 60);
            $table->string('color', 7)->default('#94a3b8');
            $table->enum('type', ['not_started', 'active', 'done', 'closed'])->default('not_started');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['task_list_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_statuses');
    }
};
