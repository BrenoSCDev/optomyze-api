<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CALENDARS
 *
 * A company owns many calendars; a calendar belongs to exactly one company.
 * This replaces the implicit "one calendar per company" model, where the
 * calendar was simply "every appointment with this company_id".
 *
 * `type` records what a calendar stands for (the whole company, a team, one
 * person, a resource) rather than being decorative: team and user calendars
 * are what future scheduling features need to reason about when working out
 * availability. `owner_id` is who the calendar is *for* — a rep's own
 * calendar — which is not the same as who may see it (`visibility`) or who may
 * edit it (calendar_members).
 *
 * Booking and external-sync configuration deliberately live in their own
 * tables, not as columns here: both are optional, both are wide, and both are
 * per-provider/per-page concerns that would otherwise leave most of this table
 * null for most rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Hex, matched to how funnels and tags already carry their colour.
            $table->string('color', 20)->default('#3B82F6');

            // company | team | user | resource | custom
            $table->string('type', 20)->default('custom');

            // company (everyone) | team | private (owner + explicit members)
            $table->string('visibility', 20)->default('company');

            // Who the calendar is for. Null for shared/company calendars.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // Falls back to the company timezone when null, so a calendar only
            // stores one if it genuinely differs (a team in another region).
            $table->string('timezone', 100)->nullable();

            // Exactly one per company; enforced in the model, not by a partial
            // index, because MySQL has no filtered unique indexes.
            $table->boolean('is_default')->default(false);

            // Archived calendars keep their appointments and can be restored;
            // deleting is the destructive path and is guarded in the API.
            $table->boolean('is_active')->default(true);

            // Room for per-calendar preferences that do not deserve a column
            // yet (default view, working-week, reminder defaults).
            $table->json('settings')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The board query is always "this company's usable calendars".
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_default']);
        });

        /**
         * Explicit per-user access, for when `visibility` alone is too coarse.
         * Empty today — company-visible calendars need no rows — but it is the
         * table sharing and delegation will hang off.
         */
        Schema::create('calendar_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // viewer | editor | manager
            $table->string('role', 20)->default('viewer');

            $table->timestamps();

            $table->unique(['calendar_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_members');
        Schema::dropIfExists('calendars');
    }
};
