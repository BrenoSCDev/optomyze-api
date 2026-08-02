<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Sale" funnels become "contact" funnels.
 *
 * Two parallel pipelines (sale + deal) never made practical sense: the deal is
 * the actual sale and the thing worth tracking, while the pipeline in front of
 * it is a contact cadence and is optional per company.
 *
 * MySQL needs the enum widened before the data can move and narrowed after.
 * SQLite (used by the test suite) stores enums as text with a check
 * constraint, so only the data update applies there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            DB::statement("ALTER TABLE funnels MODIFY COLUMN type ENUM('sale','contact','deal','model') NOT NULL DEFAULT 'contact'");
        }

        DB::table('funnels')->where('type', 'sale')->update(['type' => 'contact']);

        if ($isMysql) {
            DB::statement("ALTER TABLE funnels MODIFY COLUMN type ENUM('contact','deal','model') NOT NULL DEFAULT 'contact'");
        }
    }

    public function down(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            DB::statement("ALTER TABLE funnels MODIFY COLUMN type ENUM('sale','contact','deal','model') NOT NULL DEFAULT 'sale'");
        }

        DB::table('funnels')->where('type', 'contact')->update(['type' => 'sale']);

        if ($isMysql) {
            DB::statement("ALTER TABLE funnels MODIFY COLUMN type ENUM('sale','deal','model') NOT NULL DEFAULT 'sale'");
        }
    }
};
