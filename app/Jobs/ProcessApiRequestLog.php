<?php

namespace App\Jobs;

use App\Events\ApiRequestLogged;
use App\Models\ApiRequestLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Persists a single API request log entry and broadcasts it. Dispatched from
 * LogApiRequest::terminate() so the DB write/broadcast happen off the
 * request-response cycle and never add latency for the calling system.
 */
class ProcessApiRequestLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public readonly array $payload)
    {
    }

    public function handle(): void
    {
        // firstOrCreate (keyed by the unique request_id), not create(): if a
        // retry happens for any reason after the row already landed, re-running
        // a plain create() would throw a duplicate-key error that masks
        // whatever actually failed on the previous attempt.
        $log = ApiRequestLog::firstOrCreate(
            ['request_id' => $this->payload['request_id']],
            $this->payload
        );

        $log->load('apiToken:id,name');

        try {
            broadcast(new ApiRequestLogged($log));
        } catch (\Throwable $e) {
            // The row is already safely persisted - a broadcast hiccup
            // shouldn't fail (and retry) the whole job.
            Log::warning('Failed to broadcast api-log.logged', ['error' => $e->getMessage()]);
        }
    }
}
