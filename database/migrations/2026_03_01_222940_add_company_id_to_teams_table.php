<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add nullable first so existing rows don't violate the constraint
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        // 2. Backfill: inherit company_id from the team's manager user
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE teams t
                JOIN users u ON u.id = t.manager_user_id
                SET t.company_id = u.company_id
            ');
        } else {
            DB::statement('
                UPDATE teams
                SET company_id = (SELECT company_id FROM users WHERE users.id = teams.manager_user_id)
            ');
        }

        // 3. Enforce NOT NULL going forward
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
