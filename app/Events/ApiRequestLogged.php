<?php

namespace App\Events;

use App\Models\ApiRequestLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a completed/failed API request has been persisted to
 * api_request_logs - lets the API Logs dashboard replace the matching
 * "started" placeholder with a real row.
 *
 * Deliberately lean: Reverb/Pusher rejects event payloads above its size
 * limit, and request/response headers+bodies (capped up to 50KB each) blow
 * well past it. The dashboard fetches full request/response detail on demand
 * (GET /api/api-logs/{id}) only when a row is expanded.
 */
class ApiRequestLogged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly ApiRequestLog $log)
    {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('company.' . $this->log->company_id);
    }

    public function broadcastAs(): string
    {
        return 'api-log.logged';
    }

    public function broadcastWith(): array
    {
        return [
            'log' => [
                'id'                 => $this->log->id,
                'request_id'         => $this->log->request_id,
                'company_id'         => $this->log->company_id,
                'api_token_id'       => $this->log->api_token_id,
                'api_token_name'     => $this->log->apiToken?->name,
                'user_id'            => $this->log->user_id,
                'method'             => $this->log->method,
                'endpoint'           => $this->log->endpoint,
                'response_status'    => $this->log->response_status,
                'duration_ms'        => $this->log->duration_ms,
                'status'             => $this->log->status,
                // Carbon serializes to UTC in a broadcast payload, which would
                // make a live-streamed row disagree with the same row after a
                // refresh. Match the REST format explicitly.
                'created_at'         => $this->log->created_at?->toIso8601String(),
            ],
        ];
    }
}
