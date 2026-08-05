<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move existing WhatsApp integrations onto the Evolution server we now host.
 *
 * Two things change with the CRM becoming the Evolution provider:
 *
 * 1. Integrations that were never configured (base_url / api_key null, because
 *    the customer never completed the credentials form) are pointed at the
 *    managed server, so they start working instead of sitting half-installed.
 *    Rows that already name a server are left exactly as they are — that is a
 *    customer running their own Evolution deployment, and this refactor is not
 *    allowed to take it away from them.
 *
 * 2. Instance names become globally unique rather than unique per company. They
 *    are Evolution instance names, and every tenant now shares one Evolution
 *    deployment, so its namespace is global. The old company-scoped index would
 *    happily allow two companies to claim the same name and let the second
 *    provisioning attempt fail somewhere inside Evolution instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $credentials = array_filter([
            'base_url' => config('services.evolution.url'),
            'api_key'  => config('services.evolution.key'),
        ]);

        // Nothing to hand out if the platform server is not configured yet.
        // The model falls back to config on read regardless, so this is only
        // about persisting the value where it can be seen and overridden.
        if (count($credentials) === 2) {
            DB::table('whats_app_evo_integrations')
                ->where(function ($query) {
                    $query->whereNull('base_url')->orWhere('base_url', '');
                })
                ->where(function ($query) {
                    $query->whereNull('api_key')->orWhere('api_key', '');
                })
                ->update($credentials);
        }

        Schema::table('whats_app_instances', function (Blueprint $table) {
            $table->unique('instance_name');
        });
    }

    public function down(): void
    {
        Schema::table('whats_app_instances', function (Blueprint $table) {
            $table->dropUnique(['instance_name']);
        });

        // The backfill is deliberately not reversed: emptying those columns
        // would disconnect every WhatsApp account that is working today.
    }
};
