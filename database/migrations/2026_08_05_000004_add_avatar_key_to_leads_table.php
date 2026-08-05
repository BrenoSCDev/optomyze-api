<?php

use App\Models\Lead;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every contact a face.
 *
 * Contacts without a profile picture all fell back to their initials, so a
 * funnel board was a wall of identical grey circles with two letters in them.
 * This holds the key of one of the illustrated faces the frontend ships, so a
 * contact is recognisable at a glance and the board has some character.
 *
 * The key is stored rather than derived from the id on the fly, for two
 * reasons: deriving it would reshuffle every existing contact the day a
 * seventh face is added, and a stored key is something a user could later be
 * allowed to change.
 *
 * Existing rows are backfilled here, in chunks and with the same distribution
 * new contacts get, so nobody is left initials-only after the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Next to the other identity fields. Leads carry no picture column
            // of their own — the real photo, when there is one, comes from the
            // chat channel the conversation arrived on.
            $table->string('avatar_key', 32)->nullable()->after('username');
        });

        // Chunked: the column is nullable and the backfill is idempotent, so a
        // failure part-way through is safe to re-run, and a large tenant does
        // not get one enormous UPDATE.
        DB::table('leads')->whereNull('avatar_key')->orderBy('id')->chunkById(500, function ($leads) {
            foreach ($leads as $lead) {
                DB::table('leads')
                    ->where('id', $lead->id)
                    ->update(['avatar_key' => Lead::randomAvatarKey()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('avatar_key');
        });
    }
};
