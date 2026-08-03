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
 * Messages in a conversation have been marked read.
 *
 * Carries `direction` because the two cases mean opposite things to the UI:
 *
 *   incoming → a colleague opened the conversation, so everyone else's unread
 *              badge should clear
 *   outgoing → the website visitor read what we sent, so the agent's ticks
 *              should turn blue
 *
 * `message_ids` is included so an open thread can update just those bubbles
 * instead of refetching; the unread count covers the list on the left.
 */
class InboxMessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public InboxChat $chat,
        public string $direction,
        public array $messageIds = [],
        public int $unreadCount = 0,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->chat->company_id);
    }

    public function broadcastAs(): string
    {
        return 'inbox.messages.read';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'      => $this->chat->id,
            'direction'    => $this->direction,
            'message_ids'  => $this->messageIds,
            'unread_count' => $this->unreadCount,
            'timestamp'    => now()->toISOString(),
        ];
    }
}
