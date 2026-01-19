<?php

namespace App\Events;

use App\Models\WhatsappEvoChat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsAppChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;
    public $companyId;

    public function __construct(WhatsappEvoChat $chat)
    {
        // Load all relationships matching getCompanyChats structure
        $this->chat = $chat->load([
            'instance:id,instance_name,company_id',
            'lead:id,company_id,first_name,last_name,phone,funnel_id,stage_id',
            'lead.funnel:id,name',
            'lead.stage:id,name',
            'messages' => function ($query) {
                $query->orderBy('sent_at');
            }
        ]);
        
        $this->companyId = $chat->company_id;
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
        return 'whatsapp.chat.updated';
    }

    /**
     * Data sent to the frontend
     * Mirrors the structure from getCompanyChats
     */
    public function broadcastWith(): array
    {
        return [
            'chat' => $this->chat->toArray(),
            'timestamp' => now()->toISOString(),
        ];
    }
}