<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads become "contacts": they exist in their own right, and belonging to a
 * contact pipeline is optional. A company that turns contact-pipeline
 * management off should still be able to hold leads.
 *
 * The FKs are dropped and re-added because nullability can't change while a
 * constraint is attached. Cascade behaviour is preserved: a lead that *is* in
 * a funnel still goes when that funnel does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['funnel_id']);
            $table->dropForeign(['stage_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('funnel_id')->nullable()->change();
            $table->foreignId('stage_id')->nullable()->change();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
            $table->foreign('stage_id')->references('id')->on('stages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Leads with no pipeline can't be represented once the columns are
        // NOT NULL again.
        DB::table('leads')->whereNull('funnel_id')->orWhereNull('stage_id')->delete();

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['funnel_id']);
            $table->dropForeign(['stage_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('funnel_id')->nullable(false)->change();
            $table->foreignId('stage_id')->nullable(false)->change();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
            $table->foreign('stage_id')->references('id')->on('stages')->cascadeOnDelete();
        });
    }
};
