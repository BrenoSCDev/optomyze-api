<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact-pipeline management is opt-in. Deals are the primary tracked object;
 * running a contact cadence in front of them is a choice each company makes,
 * so this defaults to off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('contact_pipeline_enabled')->default(false)->after('product_module');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('contact_pipeline_enabled');
        });
    }
};
