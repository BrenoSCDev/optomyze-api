<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A participant caught up on an internal conversation.
 *
 * Broadcast on the conversation channel, which every participant is already
 * subscribed to, because it serves two audiences at once: the reader's own
 * other tabs clear their unread badge, and everyone else learns that this
 * person has now seen everything up to `read_at` — which is what turns the
 * "seen" marker on their own messages.
 *
 * Unread counts stay per-recipient, so the event carries no count of its own:
 * each client knows only whether *its* user was the reader.
 */
class ConversationRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public int $userId,
        public string $readAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('conversation.' . $this->conversation->id);
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'user_id'         => $this->userId,
            'read_at'         => $this->readAt,
        ];
    }
}
