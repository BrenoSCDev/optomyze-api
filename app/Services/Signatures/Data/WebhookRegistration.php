<?php

namespace App\Services\Signatures\Data;

/**
 * What a vendor hands back when we register a callback endpoint with it.
 *
 * `secret` is the signing key for delivery verification. It is generally
 * shown once, at registration, so the caller must persist it immediately.
 */
class WebhookRegistration
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $secret,
        public readonly string $endpoint,
        public readonly array $events = [],
    ) {
    }
}
