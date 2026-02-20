<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GroupCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Conversation $conversation;

    public function __construct(Conversation $conversation)
    {
        $this->conversation = $conversation->load(
            'participants:id,name,profile_pic'
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->conversation->company_id);
    }

    public function broadcastAs(): string
    {
        return 'group.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->conversation->id,
            'name'            => $this->conversation->name,
            'cover_image_url' => $this->conversation->cover_image
                ? Storage::url($this->conversation->cover_image)
                : null,
            'participants' => $this->conversation->participants->map(fn($p) => [
                'id'              => $p->id,
                'name'            => $p->name,
                'profile_pic_url' => $p->profile_pic ? Storage::url($p->profile_pic) : null,
            ])->values()->toArray(),
            'created_by' => $this->conversation->created_by,
            'created_at' => $this->conversation->created_at->toISOString(),
        ];
    }
}
