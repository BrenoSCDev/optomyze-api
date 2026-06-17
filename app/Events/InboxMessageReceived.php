<?php

namespace App\Events;

use App\Models\InboxMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InboxMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $companyId;

    public function __construct(InboxMessage $message)
    {
        // Load only necessary relationships - no N+1 queries
        $this->message = $message->load([
            'chat:id,company_id,channel,channelable_type,channelable_id,external_chat_id,lead_id,external_contact_id,last_message_at,profile_pic_url',
            'chat.channelable',
            'chat.lead:id,company_id,first_name,last_name,phone,username,funnel_id,stage_id',
            'chat.lead.funnel:id,name',
            'chat.lead.stage:id,name',
            'user:id,name,profile_pic',
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
        return 'inbox.message.received';
    }

    /**
     * Data sent to the frontend
     * Optimized structure - only the new message + chat context
     */
    public function broadcastWith(): array
    {
        $chat = $this->message->chat;
        $lead = $chat->lead;

        return [
            'message' => [
                'id'                  => $this->message->id,
                'inbox_chat_id'       => $this->message->inbox_chat_id,
                'user_id'             => $this->message->user_id,
                'external_message_id' => $this->message->external_message_id,
                'direction'           => $this->message->direction,
                'message_mode'        => $this->message->message_mode,
                'type'                => $this->message->type,
                'text'                => $this->message->text,
                'media_url'           => $this->message->media_url,
                'media_mime'          => $this->message->media_mime,
                'media_size'          => $this->message->media_size,
                'latitude'            => $this->message->latitude,
                'longitude'           => $this->message->longitude,
                'metadata'            => $this->message->metadata,
                'reply_to_message_id' => $this->message->reply_to_message_id,
                'reply_to_snapshot'   => $this->message->reply_to_snapshot,
                'reactions'           => $this->message->reactions,
                'is_edited'           => $this->message->is_edited,
                'status'              => $this->message->status,
                'sent_at'             => $this->message->sent_at,
                'created_at'          => $this->message->created_at,
                'updated_at'          => $this->message->updated_at,
                'user'                => $this->message->user
                    ? [
                        'id'          => $this->message->user->id,
                        'name'        => $this->message->user->name,
                        'profile_pic' => $this->message->user->profile_pic,
                    ]
                    : null,
            ],
            'chat' => [
                'id'                  => $chat->id,
                'company_id'          => $chat->company_id,
                'channel'             => $chat->channel,
                'channelable_id'      => $chat->channelable_id,
                'external_chat_id'    => $chat->external_chat_id,
                'lead_id'             => $chat->lead_id,
                'external_contact_id' => $chat->external_contact_id,
                'last_message_at'     => $chat->last_message_at,
                'profile_pic_url'     => $chat->profile_pic_url,
                'instance'            => $chat->instance ? [
                    'id'            => $chat->instance->id,
                    'instance_name' => $chat->instance->instance_name,
                    'company_id'    => $chat->instance->company_id,
                ] : null,
                'lead' => $lead ? [
                    'id'         => $lead->id,
                    'company_id' => $lead->company_id,
                    'first_name' => $lead->first_name,
                    'last_name'  => $lead->last_name,
                    'phone'      => $lead->phone,
                    'username'   => $lead->username,
                    'funnel_id'  => $lead->funnel_id,
                    'stage_id'   => $lead->stage_id,
                    'funnel'     => $lead->funnel ? [
                        'id'   => $lead->funnel->id,
                        'name' => $lead->funnel->name,
                    ] : null,
                    'stage' => $lead->stage ? [
                        'id'   => $lead->stage->id,
                        'name' => $lead->stage->name,
                    ] : null,
                ] : null,
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}
