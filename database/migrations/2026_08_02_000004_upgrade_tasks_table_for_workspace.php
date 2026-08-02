<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes `tasks` from a lead-attached to-do into a first-class workspace
 * entity.
 *
 * The important change is `company_id`. Until now tenancy was *inferred* — via
 * the task's lead, or for lead-less tasks via its creator. That inference is
 * unworkable once a task belongs to a list instead of a lead, and it was
 * already why TaskController could not scope show/update/destroy safely.
 *
 * The legacy `status` enum is deliberately kept. Records, the reports
 * dashboard, the lead-details panel and the public v1 API all read it, so the
 * Task model now mirrors it from the task's custom status `type` on every
 * save. New code should read `task_status_id`; nothing had to be rewritten to
 * keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Structural columns, all nullable so the backfill can run after.
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');

            $table->foreignId('task_list_id')->nullable()->after('company_id')
                ->constrained('task_lists')->nullOnDelete();
            $table->foreignId('task_status_id')->nullable()->after('task_list_id')
                ->constrained('task_statuses')->nullOnDelete();

            // Subtasks: one level of nesting. Deleting a parent takes its
            // children with it — an orphaned subtask has no meaning.
            $table->foreignId('parent_task_id')->nullable()->after('task_status_id')
                ->constrained('tasks')->cascadeOnDelete();

            // Deal linkage, alongside the existing lead_id.
            $table->foreignId('sale_id')->nullable()->after('lead_id')
                ->constrained('sales')->nullOnDelete();

            $table->timestamp('start_date')->nullable()->after('due_date');

            // Fractional so a drag-drop between two neighbours is a single
            // UPDATE instead of renumbering the column.
            $table->double('position')->default(0)->after('start_date');

            $table->timestamp('archived_at')->nullable()->after('completed_at');
        });

        // 2. Backfill tenancy from the strongest available signal, in order of
        //    confidence: the task's lead, then who created it, then who it was
        //    assigned to.
        $this->backfillCompanyFrom('leads', 'lead_id');
        $this->backfillCompanyFrom('users', 'created_by');
        $this->backfillCompanyFrom('users', 'assigned_to');

        // 3. Index + FK. The column stays nullable on purpose: a task with no
        //    lead, no creator and no assignee cannot be attributed to a tenant,
        //    and deleting user data inside a migration is not this file's call.
        //    Every read path filters `where company_id = ?`, so such a row is
        //    invisible rather than leaked. The model sets it on create, so no
        //    new row can be written without one.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $table->index(['company_id', 'archived_at']);
            $table->index(['task_list_id', 'task_status_id', 'position']);
            $table->index('parent_task_id');
        });
    }

    /**
     * Copy company_id across from a related table via a correlated subquery.
     *
     * Written this way rather than as an UPDATE ... JOIN because the test
     * suite runs on SQLite, which has no JOIN clause on UPDATE. The whereExists
     * guard stops a related row with a null company_id from overwriting a value
     * an earlier pass already resolved.
     */
    private function backfillCompanyFrom(string $table, string $foreignKey): void
    {
        DB::table('tasks')
            ->whereNull('company_id')
            ->whereNotNull($foreignKey)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from($table)
                ->whereColumn("{$table}.id", "tasks.{$foreignKey}")
                ->whereNotNull("{$table}.company_id"))
            ->update([
                'company_id' => DB::raw(
                    "(select company_id from {$table} where {$table}.id = tasks.{$foreignKey})"
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['task_list_id']);
            $table->dropForeign(['task_status_id']);
            $table->dropForeign(['parent_task_id']);
            $table->dropForeign(['sale_id']);
            $table->dropForeign(['company_id']);

            $table->dropIndex(['company_id', 'archived_at']);
            $table->dropIndex(['task_list_id', 'task_status_id', 'position']);
            $table->dropIndex(['parent_task_id']);

            $table->dropColumn([
                'company_id', 'task_list_id', 'task_status_id', 'parent_task_id',
                'sale_id', 'start_date', 'position', 'archived_at',
            ]);
        });
    }
};
