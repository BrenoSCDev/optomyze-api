<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One queue per company can be marked global.
 *
 * Distribution used to hang entirely off the funnel: a queue was reached
 * through funnels.funnel_manager_queue_id, so a lead with no funnel had no
 * route to a queue and simply arrived unowned. Now that a contact can exist
 * outside any pipeline — and does, on every webhook, widget and booking page
 * that creates one unfiled — that left a growing pile of leads nobody owned.
 *
 * A flag on the queue rather than a column on companies, because "global" is
 * a property of the queue and this mirrors how calendars carry is_default.
 * Only one queue per company may hold it; FunnelManagerQueue::makeGlobal()
 * demotes the others in the same transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnel_manager_queues', function (Blueprint $table) {
            $table->boolean('is_global')->default(false)->after('is_active');

            // Every unowned lead now asks "does this company have a global
            // queue?", so that lookup gets an index of its own.
            $table->index(['company_id', 'is_global']);
        });
    }

    public function down(): void
    {
        Schema::table('funnel_manager_queues', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_global']);
            $table->dropColumn('is_global');
        });
    }
};
