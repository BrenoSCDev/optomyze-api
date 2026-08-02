<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags become scoped: a company decides whether each tag applies to leads,
 * deals, tasks, or any combination. Pickers then only offer the tags that
 * belong to what is being tagged, instead of one flat list shared by
 * everything.
 *
 * Stored as a JSON array rather than three booleans so adding a fourth taggable
 * entity later is a constant change, not another migration.
 *
 * Existing tags are backfilled to all three: they were usable everywhere
 * before, and silently narrowing them would make tags disappear from pickers
 * where people are already using them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->json('scopes')->nullable()->after('color');
        });

        DB::table('tags')->update(['scopes' => json_encode(['lead', 'deal', 'task'])]);
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('scopes');
        });
    }
};
