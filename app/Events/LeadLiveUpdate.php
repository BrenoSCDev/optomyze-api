<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class LeadLiveUpdate implements ShouldBroadcastNow
{
    /**
     * @param string   $action      'created' | 'stage_changed' | 'deleted'
     * @param Lead     $lead
     * @param int|null $oldStageId  Populated only for stage_changed
     * @param string|null $newStageType  Stage type of the destination stage (e.g. 'won','lost','default')
     */
    public function __construct(
        private readonly string  $action,
        private readonly Lead    $lead,
        private readonly ?int    $oldStageId   = null,
        private readonly ?string $newStageType = null,
    ) {
        if ($action === 'created') {
            $this->lead->loadMissing(['assignedUser:id,name']);
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->lead->company_id);
    }

    public function broadcastAs(): string
    {
        return 'lead.live';
    }

    public function broadcastWith(): array
    {
        $lead = $this->lead;

        if ($this->action === 'deleted') {
            return [
                'action'    => 'deleted',
                'lead_id'   => $lead->id,
                'funnel_id' => $lead->funnel_id,
                'stage_id'  => $lead->stage_id,
            ];
        }

        if ($this->action === 'stage_changed') {
            return [
                'action'          => 'stage_changed',
                'lead_id'         => $lead->id,
                'funnel_id'       => $lead->funnel_id,
                'old_stage_id'    => $this->oldStageId,
                'new_stage_id'    => $lead->stage_id,
                'new_stage_type'  => $this->newStageType,
            ];
        }

        // 'created' — full lead shape matching the kanban active_leads array
        return [
            'action' => 'created',
            'lead'   => [
                ...$lead->only([
                    'id', 'company_id', 'funnel_id', 'stage_id',
                    'first_name', 'last_name', 'email', 'phone',
                    'status', 'source', 'assigned_to',
                    'created_at', 'updated_at',
                ]),
                'assigned_user'   => $lead->assignedUser
                    ? ['id' => $lead->assignedUser->id, 'name' => $lead->assignedUser->name]
                    : null,
                'products'        => [],
                'value_estimated' => 0,
            ],
        ];
    }
}
