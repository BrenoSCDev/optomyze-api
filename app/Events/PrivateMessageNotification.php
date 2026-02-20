<?php

namespace App\Events;

use App\Models\InternalMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class PrivateMessageNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly InternalMessage $message,
        public readonly int $recipientId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat-notifications.' . $this->recipientId);
    }

    public function broadcastAs(): string
    {
        return 'private.message.notification';
    }

    public function broadcastWith(): array
    {
        $sender  = $this->message->sender;
        $preview = match ($this->message->type) {
            'image' => 'Image',
            'audio' => 'Audio',
            default => $this->message->body,
        };

        return [
            'conversation_id' => $this->message->conversation_id,
            'sender'          => [
                'id'              => $sender->id,
                'name'            => $sender->name,
                'profile_pic_url' => $sender->profile_pic
                    ? Storage::url($sender->profile_pic)
                    : null,
            ],
            'preview'    => $preview,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }
}
