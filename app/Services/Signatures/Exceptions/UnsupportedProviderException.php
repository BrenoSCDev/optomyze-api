<?php

namespace App\Services\Signatures\Exceptions;

use InvalidArgumentException;

/** A provider key was asked for that is not registered in config/signatures.php. */
class UnsupportedProviderException extends InvalidArgumentException
{
    public static function forKey(string $key): self
    {
        return new self("Unsupported signature provider [{$key}].");
    }
}
