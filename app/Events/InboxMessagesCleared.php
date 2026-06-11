<?php

namespace App\Events;

use App\Models\InboxChat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InboxMessagesCleared implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $chatId;
    public int $companyId;
    public string $channel;

    public function __construct(InboxChat $chat)
    {
        $this->chatId    = $chat->id;
        $this->companyId = $chat->company_id;
        $this->channel   = $chat->channel;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    public function broadcastAs(): string
    {
        return 'inbox.messages.cleared';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'   => $this->chatId,
            'channel'   => $this->channel,
            'timestamp' => now()->toISOString(),
        ];
    }
}
