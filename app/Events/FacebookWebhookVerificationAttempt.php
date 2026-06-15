<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FacebookWebhookVerificationAttempt implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $companyId,
        public int $integrationId,
        public array $query,
        public string $receivedAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    public function broadcastAs(): string
    {
        return 'facebook.webhook.verification_attempt';
    }

    public function broadcastWith(): array
    {
        return [
            'integrationId' => $this->integrationId,
            'query' => $this->query,
            'receivedAt' => $this->receivedAt,
        ];
    }
}
