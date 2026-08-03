<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The point of no easy return.
 *
 * A global unique on `users.email` is exactly what forbids one person holding
 * an account in two companies, so it has to go. Uniqueness moves to:
 *
 *   (company_id, email)   one account per address per company
 *   (identity_id, company_id)  a person cannot hold two accounts in one company
 *
 * Rolling this back re-adds the global unique, which fails if any person has
 * since gained a second account. Back up before running it in production.
 */
return new class extends Migration {
    public function up(): void
    {
        $orphans = DB::table('users')->whereNull('identity_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Refusing to swap indexes: {$orphans} user(s) have no identity. Run the backfill first."
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['company_id', 'email']);
            $table->unique(['identity_id', 'company_id']);
        });

        // Separated from the index work: SQLite rebuilds the whole table to
        // change a column, and doing that in the same closure as index changes
        // makes the order of operations grammar-dependent.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('identity_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('identity_id')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['identity_id', 'company_id']);
            $table->dropUnique(['company_id', 'email']);
            $table->unique('email', 'users_email_unique');
        });
    }
};
