<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving for deals.
 *
 * Mirrors `leads.is_active`: false means archived, and archived records are
 * hidden from the board and search rather than deleted. Deals already had
 * deletion, which is destructive and cascades to items, charges and docs —
 * archiving is the reversible "get this off my board" action alongside it.
 *
 * Deliberately not a status value: `sales.status` already means won/lost/open
 * and drives reporting, so overloading it would make an archived deal vanish
 * from revenue figures it should still count towards.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('status');
            // The board filters by stage and then by this flag on every column
            // fetch, which is the hot path for the funnel view.
            $table->index(['stage_id', 'is_active'], 'sales_stage_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_stage_active_idx');
            $table->dropColumn('is_active');
        });
    }
};
