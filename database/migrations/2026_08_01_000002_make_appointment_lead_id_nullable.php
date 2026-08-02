<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments become the CRM's only scheduling entity, so they must be able
 * to exist on their own — an internal meeting or a block of focus time has no
 * lead. The relationship stays, it just stops being mandatory.
 *
 * The FK is dropped and re-added because changing nullability on a
 * constrained column requires it. onDelete stays cascade: an appointment that
 * *is* tied to a lead should still go when the lead does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Rows created without a lead can't be represented once the column is
        // NOT NULL again, so they're removed on the way down.
        DB::table('appointments')->whereNull('lead_id')->delete();

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable(false)->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }
};
