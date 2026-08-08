<?php

namespace App\Services\Signatures\Data;

use App\Services\Signatures\Support\SignatureEventType;
use Carbon\CarbonInterface;

/**
 * One vendor webhook delivery, translated into the CRM's vocabulary.
 *
 * Drivers return these from parseWebhook(); SignatureEventApplier consumes
 * them and knows nothing about any vendor.
 */
class NormalizedEvent
{
    public function __construct(
        /** One of SignatureEventType::ALL. */
        public readonly string $type,

        /** The vendor's own event name, preserved for the audit trail. */
        public readonly string $providerEventName,

        /** Vendor id of the request/envelope this concerns. */
        public readonly ?string $providerRequestId = null,

        /** Vendor id of the signer, when the event is about one. */
        public readonly ?string $providerSignerId = null,

        /**
         * Fallback identity for the signer when the vendor omits its own id
         * from the payload — matched against the stored signer's email.
         */
        public readonly ?string $signerEmail = null,

        public readonly ?CarbonInterface $occurredAt = null,

        /**
         * Link to the finished file, if the delivery carries one. May be
         * relative — the driver's downloader decides how to resolve it.
         */
        public readonly ?string $signedFileUrl = null,

        /** The untouched delivery body, stored on signature_events.payload. */
        public readonly array $payload = [],

        /**
         * Stable per-event identity used for idempotency. Vendors that send
         * one is used directly; otherwise the driver derives it from the
         * event's own content (never from receipt time, which would differ
         * on every redelivery and defeat the whole purpose).
         */
        public readonly ?string $externalId = null,
    ) {
    }

    public function isUnknown(): bool
    {
        return $this->type === SignatureEventType::UNKNOWN;
    }
}
