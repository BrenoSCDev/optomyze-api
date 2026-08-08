<?php

namespace App\Services\Signatures\Exceptions;

use RuntimeException;

/**
 * Asked a driver for something its vendor does not document.
 *
 * Thrown rather than emulated on purpose: a provider that cannot, say,
 * register its own webhooks should say so and let the UI tell the user what
 * to do manually, not have the module invent an undocumented workaround.
 */
class UnsupportedCapabilityException extends RuntimeException
{
    public static function make(string $provider, string $capability, string $detail = ''): self
    {
        $message = "Signature provider [{$provider}] does not support [{$capability}].";

        return new self($detail === '' ? $message : "{$message} {$detail}");
    }
}
