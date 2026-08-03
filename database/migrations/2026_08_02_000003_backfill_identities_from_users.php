<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One identity per existing user.
 *
 * `users.email` is globally unique today, so no two accounts can already
 * belong to the same person — every user becomes exactly one identity and
 * there is nothing to merge. Credentials are copied rather than moved, so the
 * old columns keep working and this stays reversible.
 *
 * Idempotent: re-running only touches users that still have no identity.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('users')
            ->whereNull('identity_id')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    // An identity may already exist if a previous run was
                    // interrupted between the insert and the link.
                    $identityId = DB::table('identities')
                        ->where('email', $user->email)
                        ->value('id');

                    $identityId ??= DB::table('identities')->insertGetId([
                        'name'              => $user->name,
                        'email'             => $user->email,
                        'email_verified_at' => $user->email_verified_at,
                        'password'          => $user->password,
                        'remember_token'    => $user->remember_token,
                        'last_login_at'     => $user->last_login_at,
                        'is_active'         => $user->is_active,
                        'created_at'        => $user->created_at ?? now(),
                        'updated_at'        => $user->updated_at ?? now(),
                        'deleted_at'        => $user->deleted_at,
                    ]);

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['identity_id' => $identityId]);
                }
            });

        $orphans = DB::table('users')->whereNull('identity_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Backfill incomplete: {$orphans} user(s) still have no identity. "
                . 'Resolve before running the index swap.'
            );
        }
    }

    public function down(): void
    {
        // Unlink, then drop only the identities this migration can account
        // for. Identities created afterwards by the new signup flow are left
        // alone — deleting them would destroy live credentials.
        DB::table('users')->update(['identity_id' => null]);

        DB::table('identities')
            ->whereIn('email', function ($query) {
                $query->select('email')->from('users');
            })
            ->delete();
    }
};
