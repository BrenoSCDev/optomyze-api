<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company enablement for the widget types.
 *
 * The widget types used to be flagged `alwaysAvailable` in the frontend, so
 * their cards always rendered "Configure" and skipped the install step every
 * other integration goes through. This gives them the same shape as the rest:
 * a row per company means installed.
 *
 * One table with a `type` column rather than one table per widget type — the
 * widget engine is already type-driven, and a third type should not need a
 * fourth migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('widget_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');            // matches widgets.type: form | chat
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'type']);
        });

        // Anyone already using a widget is already installed — without this
        // they would open Integrations and be told to install something they
        // have live on their website.
        $existing = DB::table('widgets')
            ->select('company_id', 'type')
            ->distinct()
            ->get();

        foreach ($existing as $row) {
            DB::table('widget_integrations')->insertOrIgnore([
                'company_id' => $row->company_id,
                'type'       => $row->type,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_integrations');
    }
};
