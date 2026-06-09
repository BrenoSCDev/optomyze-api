<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FireIntegrationWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries  = 3;
    public int $backoff = 30;

    /**
     * @param string $eventKey  Key matching config/integrations.php webhooks (e.g. 'lead_created')
     * @param array  $payload   Entity data forwarded to the webhook
     */
    public function __construct(
        public readonly string $eventKey,
        public readonly array  $payload,
    ) {}

    public function handle(): void
    {
        $url = config("integrations.webhooks.{$this->eventKey}");

        if (!$url) {
            Log::debug("[Integration] No webhook URL configured for '{$this->eventKey}' — skipping.");
            return;
        }

        $response = Http::timeout(15)->post($url, $this->payload);

        if ($response->failed()) {
            Log::warning(
                "[Integration] Webhook HTTP {$response->status()} for '{$this->eventKey}'",
                ['payload' => $this->payload, 'body' => $response->body()]
            );

            throw new \RuntimeException(
                "Integration webhook '{$this->eventKey}' returned HTTP {$response->status()}"
            );
        }

        Log::info("[Integration] Webhook delivered for '{$this->eventKey}'", ['status' => $response->status()]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(
            "[Integration] Webhook permanently failed for '{$this->eventKey}'",
            ['error' => $exception->getMessage(), 'payload' => $this->payload]
        );
    }
}
