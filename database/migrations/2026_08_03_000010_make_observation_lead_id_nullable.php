<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `observations.lead_id` becomes nullable.
 *
 * The column is legacy: notes went polymorphic in
 * 2026_08_02_000008, and `lead_id` was kept only so lead-scoped views could
 * keep querying it directly. It stayed NOT NULL because every notable at the
 * time necessarily had a lead behind it — a note was on a Lead, or on a Sale,
 * which always belongs to one.
 *
 * Appointments break that assumption: a meeting can exist with no contact
 * attached (an internal review, a focus block), and a comment on one has no
 * lead to record. Rather than inventing a lead or refusing the note, the
 * column now admits that it is optional.
 *
 * Nothing is backfilled or cleared — every existing row keeps its value.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed: rows written since this ran may legitimately
        // have no lead, and restoring NOT NULL would fail on them. Dropping
        // those notes to satisfy the constraint would lose real content.
    }
};
