<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notes become polymorphic, threaded and mention-aware.
 *
 * Three changes, all additive to existing rows:
 *
 *  1. `notable_type` / `notable_id` replace `lead_id`, so a note can hang off a
 *     deal as easily as a lead. A note about payment terms belongs to the deal,
 *     not to the person — they are different things to say.
 *  2. `parent_id` turns the flat list into threads. Self-referencing, cascade
 *     on delete: removing a note takes its replies with it, which is what
 *     "delete this thread" means.
 *  3. `mentions` + `edited_at`, matching the shape task_comments already uses
 *     so the two collaboration surfaces behave identically.
 *
 * `company_id` is added at the same time. Notes were previously scoped by
 * joining through the lead; a direct column means a note can be filtered by
 * tenant without knowing what it is attached to.
 *
 * `lead_id` is kept and backfilled for now: the API keeps accepting it, and a
 * rollback of the application code still works. It is dropped in a later pass.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('notable');
            $table->foreignId('parent_id')->nullable()->after('notable_id')
                ->constrained('observations')->cascadeOnDelete();
            $table->json('mentions')->nullable()->after('note');
            $table->timestamp('edited_at')->nullable()->after('mentions');
        });

        // Every existing note is a lead note. The alias, not the FQCN: the app
        // enforces a morph map (AppServiceProvider), so 'Lead' is what
        // getMorphClass() returns and what every query will compare against.
        DB::table('observations')->update(['notable_type' => 'Lead']);
        // Correlated subqueries rather than UPDATE ... JOIN: the join form is
        // MySQL-only and the test suite runs on SQLite.
        DB::statement('UPDATE observations SET notable_id = lead_id WHERE lead_id IS NOT NULL');
        DB::statement(
            'UPDATE observations
                SET company_id = (SELECT company_id FROM leads WHERE leads.id = observations.lead_id)
              WHERE lead_id IS NOT NULL'
        );

        Schema::table('observations', function (Blueprint $table) {
            $table->index(['notable_type', 'notable_id', 'parent_id'], 'observations_notable_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropIndex('observations_notable_thread_idx');
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'mentions', 'edited_at']);
            $table->dropMorphs('notable');
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
