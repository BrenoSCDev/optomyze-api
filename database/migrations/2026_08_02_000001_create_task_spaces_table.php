<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spaces are the top level of the task hierarchy: Space > List > Task > Subtask.
 *
 * A space is the unit a department owns ("Sales", "Support"). It is scoped
 * directly to the company rather than inferred through a lead, which is what
 * lets tasks exist independently of the sales pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('description', 500)->nullable();
            // Hex swatch shown on the space chip in the sidebar.
            $table->string('color', 7)->default('#6366f1');
            // Lucide icon name, resolved on the frontend against an allow-list.
            $table->string('icon', 40)->default('Folder');
            $table->unsignedInteger('order')->default(0);

            // Archiving hides a space without destroying its task history.
            $table->timestamp('archived_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
            $table->index(['company_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_spaces');
    }
};
