<?php

namespace App\Services\Signatures\Exceptions;

use RuntimeException;

/**
 * The company has a provider row but it cannot be used yet — no credentials,
 * disabled, or never successfully tested.
 */
class ProviderNotConfiguredException extends RuntimeException
{
    public static function missingCredentials(string $provider): self
    {
        return new self("Signature provider [{$provider}] has no credentials configured for this company.");
    }

    public static function inactive(string $provider): self
    {
        return new self("Signature provider [{$provider}] is not active for this company.");
    }
}
