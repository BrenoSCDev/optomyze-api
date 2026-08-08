<?php

namespace App\Services\Signatures\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A vendor call failed.
 *
 * `retryable` is the whole point of this class: the queued jobs need to tell
 * "the vendor is busy / had a blip" (retry) apart from "the payload is wrong"
 * (retrying forever will never fix it). Drivers decide which is which,
 * because only they know their vendor's error semantics.
 */
class SignatureProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        public readonly array $context = [],
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public static function retryable(
        string $message,
        ?int $statusCode = null,
        array $context = [],
        ?int $retryAfterSeconds = null,
    ): self {
        return new self($message, $statusCode, true, $context, $retryAfterSeconds);
    }

    public static function permanent(string $message, ?int $statusCode = null, array $context = []): self
    {
        return new self($message, $statusCode, false, $context);
    }
}
