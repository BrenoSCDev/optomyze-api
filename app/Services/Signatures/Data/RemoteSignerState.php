<?php

namespace App\Services\Signatures\Data;

use Carbon\CarbonInterface;

/** One signer's state at the vendor, as reported by a status fetch. */
class RemoteSignerState
{
    public function __construct(
        public readonly ?string $providerSignerId,
        /** One of SignerStatus::ALL. */
        public readonly string $status,
        public readonly ?string $email = null,
        public readonly ?CarbonInterface $signedAt = null,
    ) {
    }
}
