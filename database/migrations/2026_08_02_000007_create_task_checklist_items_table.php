<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight tick-boxes inside a task. Distinct from subtasks: a checklist
 * item has no assignee, dates or status of its own — when those are needed the
 * user promotes it to a real subtask instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            $table->string('title', 500);
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('order')->default(0);

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['task_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
    }
};
