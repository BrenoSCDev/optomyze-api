<?php

namespace App\Events;

use App\Models\InboxMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast for in-place updates to an existing inbox message
 * (e.g. a reaction was added/removed, or the message was edited).
 *
 * Unlike InboxMessageReceived, this does not represent a new message
 * in the conversation - the frontend should merge these fields into
 * the existing message by id rather than appending a new bubble.
 */
class InboxMessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $companyId;

    public function __construct(InboxMessage $message)
    {
        $this->message = $message->load('chat:id,company_id');
        $this->companyId = $this->message->chat->company_id;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    public function broadcastAs(): string
    {
        return 'inbox.message.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id'            => $this->message->id,
                'inbox_chat_id' => $this->message->inbox_chat_id,
                'text'          => $this->message->text,
                'reactions'     => $this->message->reactions,
                'is_edited'     => $this->message->is_edited,
                'status'        => $this->message->status,
                'updated_at'    => $this->message->updated_at?->toIso8601String(),
            ],
        ];
    }
}
