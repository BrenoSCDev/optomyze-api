<?php

namespace App\Services;

use App\Models\FunnelManagerQueue;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadQueueAssignmentService
{
    /**
     * If the lead's funnel has an active queue, assign the lead to the next
     * user in the rotation using a pessimistic lock for concurrency safety.
     * Only runs when the lead has no existing assignment.
     */
    public static function assign(Lead $lead): void
    {
        if (!$lead->funnel_id) {
            return;
        }

        // Use the already-loaded relation if available, otherwise query
        $funnel = $lead->relationLoaded('funnel')
            ? $lead->funnel
            : \App\Models\Funnel::find($lead->funnel_id);

        if (!$funnel || !$funnel->funnel_manager_queue_id) {
            return;
        }

        DB::transaction(function () use ($lead, $funnel) {
            $queue = FunnelManagerQueue::where('id', $funnel->funnel_manager_queue_id)
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
}
