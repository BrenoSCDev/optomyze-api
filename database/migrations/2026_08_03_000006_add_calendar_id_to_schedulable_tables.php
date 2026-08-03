<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Put appointments and tasks on a calendar.
 *
 * Nullable and `nullOnDelete` on purpose. Nullable means every existing row
 * stays valid the moment this runs, before the backfill has said a word — the
 * two migrations are separate so a failure in the second cannot leave the
 * schema half-applied. `nullOnDelete` means deleting a calendar can never
 * delete someone's appointments; they fall back to unfiled and the API
 * re-homes them onto the default.
 *
 * Tasks are included because they already appear on the calendar grid
 * alongside appointments — a task with a due date is a scheduling record here,
 * so it has to be filterable by calendar for switching to mean anything.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('calendar_id')
                ->nullable()
                ->after('company_id')
                ->constrained()
                ->nullOnDelete();

            // The calendar view always asks for one calendar over a date range.
            $table->index(['calendar_id', 'scheduled_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('calendar_id')
                ->nullable()
                ->after('company_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['calendar_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['calendar_id']);
            $table->dropIndex(['calendar_id', 'scheduled_at']);
            $table->dropColumn('calendar_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['calendar_id']);
            $table->dropIndex(['calendar_id', 'due_date']);
            $table->dropColumn('calendar_id');
        });
    }
};
