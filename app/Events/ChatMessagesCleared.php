<?php

namespace App\Events;

use App\Models\WhatsappEvoChat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessagesCleared implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $chatId;
    public int $companyId;

    public function __construct(WhatsappEvoChat $chat)
    {
        $this->chatId    = $chat->id;
        $this->companyId = $chat->company_id;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    public function broadcastAs(): string
    {
        return 'whatsapp.chat.messages.cleared';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'   => $this->chatId,
            'timestamp' => now()->toISOString(),
        ];
    }
}
