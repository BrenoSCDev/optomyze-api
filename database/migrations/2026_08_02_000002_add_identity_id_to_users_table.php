<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links each account to the person who owns it.
 *
 * Nullable for now so this ships independently of the backfill; the column is
 * tightened once every row has one.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('identity_id')
                ->nullable()
                ->after('id')
                ->constrained('identities')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['identity_id']);
            $table->dropColumn('identity_id');
        });
    }
};
