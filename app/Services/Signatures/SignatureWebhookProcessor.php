<?php

namespace App\Services\Signatures;

use App\Models\SignatureProviderAccount;
use App\Services\Signatures\Audit\SignatureAuditLogger;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;

/**
 * Front door for inbound vendor deliveries.
 *
 * Resolves the tenant, authenticates the delivery, and hands the normalized
 * events to the applier. Vendor-specific work — signature scheme, payload
 * shape — happens inside the driver; this class only sequences it.
 *
 * Every outcome is audited, including the rejections. When a vendor insists
 * it delivered something, the row proving what arrived (and why it was turned
 * away) is the whole point.
 */
class SignatureWebhookProcessor
{
    public function __construct(
        private readonly SignatureProviderRegistry $registry,
        private readonly SignatureEventApplier $applier,
        private readonly SignatureAuditLogger $audit,
    ) {
    }

    /**
     * @return array{status: string, handled?: int, duplicates?: int, reason?: string}
     */
    public function handle(string $providerKey, string $callbackId, HttpRequest $httpRequest): array
    {
        if (!$this->registry->supports($providerKey)) {
            $this->audit->inbound(null, $providerKey, 'webhook_unknown_provider', $httpRequest, false,
                "No driver registered for provider [{$providerKey}].");

            return ['status' => 'ignored', 'reason' => 'unknown_provider'];
        }

        $account = SignatureProviderAccount::where('webhook_callback_id', $callbackId)
            ->where('provider', $providerKey)
            ->first();

        if (!$account) {
            // The callback id is the tenant's identity here; one that matches
            // nothing is either a stale registration or a probe.
            $this->audit->inbound(null, $providerKey, 'webhook_unmatched_callback', $httpRequest, false,
                'No provider account matches this callback id.');

            return ['status' => 'ignored', 'reason' => 'unknown_callback'];
        }

        $provider = $this->registry->get($providerKey);

        if (!$provider->verifyWebhook($account, $httpRequest)) {
            $this->audit->inbound($account, $providerKey, 'webhook_invalid_signature', $httpRequest, false,
                'Signature verification failed.');

            Log::warning('Signature webhook rejected: invalid signature', [
                'provider'   => $providerKey,
                'company_id' => $account->company_id,
            ]);

            return ['status' => 'rejected', 'reason' => 'invalid_signature'];
        }

        $events = $provider->parseWebhook($account, $httpRequest);

        if ($events === []) {
            $this->audit->inbound($account, $providerKey, 'webhook_no_events', $httpRequest, true);

            return ['status' => 'ok', 'handled' => 0];
        }

        $handled    = 0;
        $duplicates = 0;

        foreach ($events as $event) {
            try {
                $applied = $this->applier->apply($account, $event);

                $applied ? $handled++ : $duplicates++;
            } catch (\Throwable $e) {
                // One bad event must not cost us the rest of the batch, nor
                // turn into a 500 that makes the vendor redeliver everything
                // — including the events we already applied.
                Log::error('Signature webhook: failed to apply event', [
                    'provider'       => $providerKey,
                    'company_id'     => $account->company_id,
                    'provider_event' => $event->providerEventName,
                    'error'          => $e->getMessage(),
                ]);

                $this->audit->inbound($account, $providerKey, 'webhook_apply_failed', $httpRequest, false,
                    $e->getMessage());
            }
        }

        $this->audit->inbound($account, $providerKey, 'webhook_received', $httpRequest, true);

        return ['status' => 'ok', 'handled' => $handled, 'duplicates' => $duplicates];
    }
}
