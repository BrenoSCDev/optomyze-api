<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('closing_reasons', function (Blueprint $table) {
            $table->string('outcome')->default('neutral')->after('description');
        });

        // Backfill the seeded defaults (ChatSessionService::DEFAULT_CLOSING_REASONS)
        // with a sensible won/lost/neutral classification used by Phase 3 metrics.
        DB::table('closing_reasons')->whereIn('name', ['Sale Completed', 'Support Resolved'])
            ->update(['outcome' => 'won']);

        DB::table('closing_reasons')->whereIn('name', ['Lead Disqualified', 'No Response'])
            ->update(['outcome' => 'lost']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('closing_reasons', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};
