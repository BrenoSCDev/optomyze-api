<?php

namespace App\Services\Signatures\Data;

/** Outcome of testing a company's stored credentials against its vendor. */
class ConnectionResult
{
    public function __construct(
        public readonly bool $connected,
        public readonly string $message,
        /** Anything worth showing the user — account name, plan, environment. */
        public readonly array $details = [],
    ) {
    }

    public static function ok(string $message, array $details = []): self
    {
        return new self(true, $message, $details);
    }

    public static function failed(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }
}
