<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadCreatedNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $lead;
    public $creator;
    public $companyId;

    public function __construct(Lead $lead, User $creator)
    {
        $this->lead = $lead;
        $this->creator = $creator;
        $this->companyId = $lead->company_id;
    }

    /**
     * Broadcast to company-specific private channel
     * CRITICAL: Only users with matching company_id will receive this
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    /**
     * Custom event name for the frontend to listen to
     */
    public function broadcastAs(): string
    {
        return 'lead.created';
    }

    /**
     * Data sent to the frontend
     */
    public function broadcastWith(): array
    {
        return [
            'id' => uniqid('notif_'),
            'type' => 'lead_created',
            'message' => "{$this->creator->name} created a new lead: {$this->lead->first_name}",
            'lead' => [
                'id' => $this->lead->id,
                'funnel_id' => $this->lead->funnel_id,
                'first_name' => $this->lead->first_name,
            ],
            'creator' => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'profile_pic' => $this->creator->profile_pic,
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}