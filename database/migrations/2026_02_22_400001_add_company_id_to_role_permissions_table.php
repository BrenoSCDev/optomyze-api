<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1️⃣ Add company_id as nullable so existing rows don't violate NOT NULL
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        // 2️⃣ Copy the existing global templates for every company in the system
        $companies  = DB::table('companies')->pluck('id');
        $globalRows = DB::table('role_permissions')->whereNull('company_id')->get();

        foreach ($companies as $companyId) {
            $rows = $globalRows->map(fn ($r) => [
                'company_id'     => $companyId,
                'role'           => $r->role,
                'permission_key' => $r->permission_key,
            ])->toArray();

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('role_permissions')->insertOrIgnore($chunk);
            }
        }

        // 3️⃣ Remove the now-obsolete global (null company_id) rows
        DB::table('role_permissions')->whereNull('company_id')->delete();

        // 4️⃣ Drop old unique + index before recreating them
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropUnique('role_permissions_role_permission_key_unique');
            $table->dropIndex('role_permissions_role_index');
        });

        // 5️⃣ Make company_id NOT NULL and add new scoped constraints
        DB::statement('ALTER TABLE role_permissions MODIFY company_id BIGINT UNSIGNED NOT NULL');

        Schema::table('role_permissions', function (Blueprint $table) {
            $table->unique(['company_id', 'role', 'permission_key']);
            $table->index(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropUnique('role_permissions_company_id_role_permission_key_unique');
            $table->dropIndex('role_permissions_company_id_role_index');
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('role_permissions', function (Blueprint $table) {
            $table->unique(['role', 'permission_key']);
            $table->index('role');
        });
    }
};
