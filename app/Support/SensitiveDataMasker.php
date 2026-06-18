<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Recursively redacts known-sensitive keys from arrays/headers before they're
 * persisted to api_request_logs, and caps oversized payloads so log rows stay
 * bounded. Errs on the side of over-redacting: a false-positive match on a
 * harmless field is a much smaller problem than a leaked secret.
 */
class SensitiveDataMasker
{
    private const REDACTED = '***REDACTED***';

    private const SENSITIVE_KEY_PATTERNS = [
        'password', 'secret', 'token', 'authorization', 'apikey',
        'accesstoken', 'refreshtoken', 'privatekey', 'clientsecret',
        'cardnumber', 'cvv', 'cvc', 'ssn', 'creditcard', 'cookie', 'auth',
    ];

    /**
     * Recursively redact sensitive values in a nested array (request/response bodies).
     */
    public static function maskArray(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $masked = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $masked[$key] = self::REDACTED;
                continue;
            }

            if ($value instanceof UploadedFile) {
                $masked[$key] = [
                    '__file__' => true,
                    'name'     => $value->getClientOriginalName(),
                    'mime'     => $value->getClientMimeType(),
                    'size'     => $value->getSize(),
                ];
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = self::maskArray($value);
                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    /**
     * Redact sensitive HTTP headers (each header value is an array of one or
     * more strings, matching Symfony's HeaderBag::all() shape).
     */
    public static function maskHeaders(array $headers): array
    {
        $masked = [];

        foreach ($headers as $key => $value) {
            $masked[$key] = self::isSensitiveKey((string) $key) ? [self::REDACTED] : $value;
        }

        return $masked;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z]/', '', $key) ?? '');

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cap the serialized size of a value so a single oversized request/response
     * body (e.g. a large file upload or bulk export) can't bloat the log table.
     */
    public static function cap(mixed $value, int $maxBytes = 51200): mixed
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value);

        if ($encoded === false) {
            return ['__unserializable__' => true];
        }

        if (strlen($encoded) <= $maxBytes) {
            return $value;
        }

        return [
            '__truncated__'        => true,
            'original_size_bytes'  => strlen($encoded),
            'preview'               => substr($encoded, 0, $maxBytes),
        ];
    }
}
