<?php

use App\Models\UserPreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing user a preferences row.
 *
 * The table was introduced with lazy creation — a row appeared the first time
 * someone loaded the app. That left the table almost empty and meant anyone
 * querying it (support, reporting, a future join) saw a partial picture that
 * depended on who had happened to log in.
 *
 * New users are covered by the `created` hook on the User model; this closes
 * the gap for everyone who already existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Chunked so a large tenant does not build one enormous insert, and
        // written straight to the table rather than through the model to skip
        // per-row events on what is a bulk backfill.
        DB::table('users')
            ->select('users.id')
            ->whereNotExists(function ($query) {
                $query->selectRaw(1)
                    ->from('user_preferences')
                    ->whereColumn('user_preferences.user_id', 'users.id');
            })
            ->orderBy('users.id')
            ->chunk(500, function ($users) use ($now) {
                DB::table('user_preferences')->insert(
                    collect($users)->map(fn ($user) => [
                        ...UserPreference::DEFAULTS,
                        'user_id'    => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        // Nothing to reverse: dropping rows here would delete preferences users
        // have since changed. The table's own migration handles teardown.
    }
};
