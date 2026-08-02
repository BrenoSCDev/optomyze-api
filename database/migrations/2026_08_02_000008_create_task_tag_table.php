<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks reuse the company's existing tags rather than owning a parallel label
 * set, following the lead_tag / sale_tag convention — so a "VIP" tag means the
 * same thing on a lead, a deal and a task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_tag');
    }
};
