<?php

namespace App\Services;

use App\Models\Funnel;
use App\Models\FunnelManagerQueue;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadQueueAssignmentService
{
    /**
     * Give a new lead an owner, if anything is configured to provide one.
     *
     * Every path that creates a lead ends up here — webhooks, widgets, booking
     * pages, the API, the CRM's own form — so this is the one place that
     * decides who a lead belongs to. The order is most specific first:
     *
     *   1. An owner already on the lead. Something deliberately chose a person
     *      — a widget's assigned user, an API caller passing assigned_to — and
     *      that always wins.
     *   2. The queue on the lead's funnel, when it is in one.
     *   3. The company's global queue.
     *
     * Nothing configured at any level means the lead is left unowned, which is
     * a legitimate outcome for teams that triage by hand.
     */
    public static function assign(Lead $lead): void
    {
        /*
         * Rule 1, and it used to be missing.
         *
         * The docblock has always claimed this method "only runs when the lead
         * has no existing assignment", but nothing enforced it — it rotated the
         * queue and overwrote whoever was there. Two call sites had grown their
         * own guard to work around it (AiHandoverService says so in a comment),
         * while others had not: creating a lead through the API with an
         * explicit assigned_to silently handed it to the next person in the
         * rotation instead. Enforcing it here makes the promise true everywhere
         * and leaves those guards harmlessly redundant.
         */
        if ($lead->assigned_to) {
            return;
        }

        $queueId = self::resolveQueueId($lead);

        if (!$queueId) {
            return;
        }

        DB::transaction(function () use ($lead, $queueId) {
            $queue = FunnelManagerQueue::whereKey($queueId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$queue) {
                return;
            }

            $users = $queue->users()->get();

            if ($users->isEmpty()) {
                return;
            }

            $assignedUser = $users[$queue->current_index % $users->count()];

            $lead->update(['assigned_to' => $assignedUser->id]);

            $queue->increment('current_index');
        });
    }

    /**
     * Which queue should hand out this lead — rules 2 and 3 above.
     *
     * A funnel that names a queue keeps it whether or not that queue is
     * currently active. Switching a queue off is an instruction to stop
     * distributing those leads, not an invitation for the global queue to
     * quietly pick them up; the is_active check lives at the lock in assign(),
     * so an inactive queue means nobody is assigned. The global queue is only
     * consulted when the funnel names no queue at all, or there is no funnel.
     */
    private static function resolveQueueId(Lead $lead): ?int
    {
        if ($lead->funnel_id) {
            // Use the already-loaded relation if available, otherwise query.
            $funnel = $lead->relationLoaded('funnel')
                ? $lead->funnel
                : Funnel::find($lead->funnel_id);

            if ($funnel?->funnel_manager_queue_id) {
                return $funnel->funnel_manager_queue_id;
            }
        }

        return FunnelManagerQueue::globalFor($lead->company_id)?->id;
    }
}
