<?php

namespace App\Services\Signatures\Data;

use Carbon\CarbonInterface;

/**
 * A request's state as the vendor currently sees it, for reconciliation.
 *
 * Webhooks are the design; this is the safety net for deliveries that never
 * arrived (endpoint down, webhook deregistered, vendor outage).
 */
class RemoteRequestState
{
    public function __construct(
        /** One of SignatureStatus::ALL. */
        public readonly string $status,

        /** @var array<int, RemoteSignerState> */
        public readonly array $signers = [],

        public readonly ?CarbonInterface $completedAt = null,

        public readonly ?string $signedFileUrl = null,

        /** Raw vendor response, for the audit log. */
        public readonly array $raw = [],
    ) {
    }
}
