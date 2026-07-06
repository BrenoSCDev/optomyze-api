<?php

namespace App\Events;

use App\Models\InboxChat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when chat-level state changes outside of a new message —
 * currently the AI agent ownership flag (ai_agent_service).
 */
class InboxChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;
    public $companyId;

    public function __construct(InboxChat $chat)
    {
        $this->chat = $chat;
        $this->companyId = $chat->company_id;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    public function broadcastAs(): string
    {
        return 'inbox.chat.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'chat' => [
                'id' => $this->chat->id,
                'lead_id' => $this->chat->lead_id,
                'ai_agent_service' => (bool) $this->chat->ai_agent_service,
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}
