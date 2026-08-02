<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple assignees and watchers.
 *
 * `tasks.assigned_to` stays as the primary assignee so existing queries,
 * indexes and the v1 API keep working; task_assignees is the full set and is
 * seeded from that column below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
        });

        // Watchers get notified about a task without owning it.
        Schema::create('task_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
        });

        // Carry the existing single assignee across. Chunked through the query
        // builder rather than INSERT ... SELECT NOW() so it runs on SQLite too,
        // which the test suite uses.
        $now = now();

        DB::table('tasks')
            ->whereNotNull('assigned_to')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($now) {
                DB::table('task_assignees')->insert(
                    collect($rows)->map(fn ($row) => [
                        'task_id'    => $row->id,
                        'user_id'    => $row->assigned_to,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('task_assignees');
    }
};
