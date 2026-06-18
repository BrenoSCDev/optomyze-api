<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Lightweight, non-persisted signal fired the moment an external API request
 * is received - lets the API Logs dashboard show an "incoming" row before the
 * response (and the durable ApiRequestLog row) exists.
 */
class ApiRequestStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $companyId,
        public readonly string $requestId,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly string $startedAt,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->companyId);
    }

    public function broadcastAs(): string
    {
        return 'api-log.started';
    }

    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->requestId,
            'method'     => $this->method,
            'endpoint'   => $this->endpoint,
            'started_at' => $this->startedAt,
        ];
    }
}
