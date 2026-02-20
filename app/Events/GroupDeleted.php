<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GroupDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $groupId,
        public readonly string $groupName,
        public readonly int    $companyId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    public function broadcastAs(): string
    {
        return 'group.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->groupId,
            'name'            => $this->groupName,
        ];
    }
}
