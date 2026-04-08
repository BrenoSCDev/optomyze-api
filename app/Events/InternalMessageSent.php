<?php

namespace App\Events;

use App\Models\InternalMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InternalMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public InternalMessage $message;

    public function __construct(InternalMessage $message)
    {
        $this->message = $message->load('sender:id,name,profile_pic');
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.' . $this->message->conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $sender = $this->message->sender;

        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'type'            => $this->message->type,
            'body'            => $this->message->body,
            'file_url'        => $this->message->file_path,
            'sender' => [
                'id'              => $sender->id,
                'name'            => $sender->name,
                'profile_pic_url' => $sender->profile_pic,
            ],
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }
}
