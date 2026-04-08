<?php

namespace App\Events;

use App\Models\WhatsappEvoMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsAppMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $companyId;

    public function __construct(WhatsappEvoMessage $message)
    {
        // Load only necessary relationships - no N+1 queries
        $this->message = $message->load([
            'chat:id,company_id,whatsapp_instance_id,remote_jid,lead_id,phone_number,last_message_at,profile_pic_url',
            'chat.instance:id,instance_name,company_id',
            'chat.lead:id,company_id,first_name,last_name,phone,funnel_id,stage_id',
            'chat.lead.funnel:id,name',
            'chat.lead.stage:id,name',
            'user:id,name',
        ]);
        
        $this->companyId = $message->chat->company_id;
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
        return 'whatsapp.message.received';
    }

    /**
     * Data sent to the frontend
     * Optimized structure - only the new message + chat context
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id'                   => $this->message->id,
                'whatsapp_evo_chat_id' => $this->message->whatsapp_evo_chat_id,
                'user_id'              => $this->message->user_id,
                'wa_message_id'        => $this->message->wa_message_id,
                'direction'            => $this->message->direction,
                'message_mode'         => $this->message->message_mode,
                'type'                 => $this->message->type,
                'text'                 => $this->message->text,
                'media_url'            => $this->message->media_url,
                'media_mime'           => $this->message->media_mime,
                'media_size'           => $this->message->media_size,
                'latitude'             => $this->message->latitude,
                'longitude'            => $this->message->longitude,
                'metadata'             => $this->message->metadata,
                'status'               => $this->message->status,
                'sent_at'              => $this->message->sent_at,
                'created_at'           => $this->message->created_at,
                'updated_at'           => $this->message->updated_at,
                'user'                 => $this->message->user
                    ? ['id' => $this->message->user->id, 'name' => $this->message->user->name]
                    : null,
            ],
            'chat' => [
                'id' => $this->message->chat->id,
                'company_id' => $this->message->chat->company_id,
                'whatsapp_instance_id' => $this->message->chat->whatsapp_instance_id,
                'remote_jid' => $this->message->chat->remote_jid,
                'lead_id' => $this->message->chat->lead_id,
                'phone_number' => $this->message->chat->phone_number,
                'last_message_at' => $this->message->chat->last_message_at,
                'profile_pic_url' => $this->message->chat->profile_pic_url,
                'instance' => [
                    'id' => $this->message->chat->instance->id,
                    'instance_name' => $this->message->chat->instance->instance_name,
                    'company_id' => $this->message->chat->instance->company_id,
                ],
                'lead' => [
                    'id' => $this->message->chat->lead->id,
                    'company_id' => $this->message->chat->lead->company_id,
                    'first_name' => $this->message->chat->lead->first_name,
                    'last_name' => $this->message->chat->lead->last_name,
                    'phone' => $this->message->chat->lead->phone,
                    'funnel_id' => $this->message->chat->lead->funnel_id,
                    'stage_id' => $this->message->chat->lead->stage_id,
                    'funnel' => [
                        'id' => $this->message->chat->lead->funnel->id,
                        'name' => $this->message->chat->lead->funnel->name,
                    ],
                    'stage' => [
                        'id' => $this->message->chat->lead->stage->id,
                        'name' => $this->message->chat->lead->stage->name,
                    ],
                ],
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}