<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendExternalNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 3 times with 30-second back-off between attempts. */
    public int $tries = 3;
    public int $backoff = 30;

    private const WEBHOOK_EMAIL =
        'https://optomyze-n8n.kmfrpu.easypanel.host/webhook/06484f00-c9af-46c2-a719-940f4bba8c20';

    private const WEBHOOK_WHATSAPP =
        'https://optomyze-n8n.kmfrpu.easypanel.host/webhook/d7effdfe-4695-4426-a1f5-876b6ab77045';

    /**
     * @param string $channel   'email' | 'whatsapp'
     * @param array  $payload   Fields forwarded to the webhook
     * @param string $eventKey  Notification event name — used for logging only
     * @param int    $userId    Recipient user ID — used for logging only
     */
    public function __construct(
        public readonly string $channel,
        public readonly array  $payload,
        public readonly string $eventKey,
        public readonly int    $userId,
    ) {}

    public function handle(): void
    {
        $url = $this->channel === 'email' ? self::WEBHOOK_EMAIL : self::WEBHOOK_WHATSAPP;

        /** @var Response $response */
        $response = Http::timeout(15)->post($url, $this->payload);

        if ($response->failed()) {
            Log::warning(
                "[Notification] {$this->channel} webhook HTTP {$response->status()} "
                . "for user {$this->userId} / event '{$this->eventKey}'",
                ['payload' => $this->payload, 'body' => $response->body()]
            );

            // Throw so Laravel marks this attempt as failed and schedules a retry.
            throw new \RuntimeException(
                "n8n {$this->channel} webhook returned HTTP {$response->status()}"
            );
        }

        Log::info(
            "[Notification] {$this->channel} delivered — user {$this->userId} / event '{$this->eventKey}'",
            ['status' => $response->status()]
        );
    }

    /**
     * Called by the queue worker after all retry attempts are exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error(
            "[Notification] {$this->channel} permanently failed — user {$this->userId} / event '{$this->eventKey}'",
            ['error' => $exception->getMessage(), 'payload' => $this->payload]
        );
    }
}
