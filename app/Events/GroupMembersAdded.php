<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GroupMembersAdded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Conversation $conversation;
    public array $newUserIds;

    public function __construct(Conversation $conversation, array $newUserIds)
    {
        $this->conversation = $conversation->load('participants:id,name,profile_pic');
        $this->newUserIds   = $newUserIds;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->conversation->company_id);
    }

    public function broadcastAs(): string
    {
        return 'group.members.added';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'name'            => $this->conversation->name,
            'cover_image_url' => $this->conversation->cover_image
                ? Storage::url($this->conversation->cover_image)
                : null,
            'new_member_ids'  => $this->newUserIds,
            'participants'    => $this->conversation->participants->map(fn($p) => [
                'id'                => $p->id,
                'name'              => $p->name,
                'profile_pic_url'   => $p->profile_pic ? Storage::url($p->profile_pic) : null,
                'conversation_role' => $p->pivot->role ?? 'member',
            ])->values()->toArray(),
        ];
    }
}
