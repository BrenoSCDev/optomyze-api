<?php

namespace App\Services\Signatures\Audit;

use App\Models\SignatureAuditLog;
use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the wire record for the signature module: every outbound vendor call
 * and every inbound delivery.
 *
 * Two rules, both learned from CompanyStorage:
 *
 *   - **Never throws into the caller.** Auditing describes work; it must not
 *     become the reason that work fails. A logger that can 500 a contract
 *     send is worse than no logger.
 *
 *   - **Redact before persisting.** Bodies here contain access tokens, webhook
 *     secrets, signer CPFs and whole base64 PDFs. The audit table is read by
 *     support staff during debugging, and it is backed up — so the secrets are
 *     stripped and the file bodies elided on the way in, not on the way out.
 */
class SignatureAuditLogger
{
    /** Keys whose values never reach the table, at any nesting depth. */
    private const REDACTED_KEYS = [
        'authorization',
        'access_token',
        'api_key',
        'apikey',
        'secret',
        'webhook_secret',
        'password',
        'token',
        'private_key',
        'content-hmac',
    ];

    /** Keys holding file bodies — replaced by a size marker. */
    private const ELIDED_KEYS = [
        'content_base64',
        'content',
        'file',
    ];

    /** Longer strings are truncated; nothing useful for debugging is past this. */
    private const MAX_STRING_LENGTH = 2000;

    public function outbound(
        ?SignatureProviderAccount $account,
        string $provider,
        string $action,
        string $method,
        string $endpoint,
        ?array $requestPayload,
        ?int $statusCode,
        ?array $responsePayload,
        int $durationMs,
        bool $success,
        ?string $error = null,
        ?SignatureRequest $request = null,
    ): void {
        $this->write([
            'company_id'            => $account?->company_id ?? $request?->company_id,
            'signature_provider_id' => $account?->id,
            'signature_request_id'  => $request?->id,
            'provider'              => $provider,
            'direction'             => SignatureAuditLog::DIRECTION_OUTBOUND,
            'action'                => $action,
            'method'                => strtoupper($method),
            'endpoint'              => Str::limit($endpoint, 500, ''),
            'status_code'           => $statusCode,
            'duration_ms'           => $durationMs,
            'success'               => $success,
            'request_payload'       => $this->sanitize($requestPayload),
            'response_payload'      => $this->sanitize($responsePayload),
            'error'                 => $error ? Str::limit($error, 2000) : null,
        ]);
    }

    /**
     * Record an inbound delivery.
     *
     * Called for *every* delivery, including ones we reject: an unmatched
     * callback id or a failed signature check is precisely what someone will
     * need to see when a vendor swears it sent something.
     */
    public function inbound(
        ?SignatureProviderAccount $account,
        string $provider,
        string $action,
        HttpRequest $httpRequest,
        bool $success,
        ?string $error = null,
        ?SignatureRequest $request = null,
        ?array $payload = null,
    ): void {
        $this->write([
            'company_id'            => $account?->company_id ?? $request?->company_id,
            'signature_provider_id' => $account?->id,
            'signature_request_id'  => $request?->id,
            'provider'              => $provider,
            'direction'             => SignatureAuditLog::DIRECTION_INBOUND,
            'action'                => $action,
            'method'                => $httpRequest->method(),
            'endpoint'              => Str::limit($httpRequest->fullUrl(), 500, ''),
            'status_code'           => $success ? 200 : null,
            'success'               => $success,
            'request_payload'       => $this->sanitize($payload ?? $this->decodeBody($httpRequest)),
            'error'                 => $error ? Str::limit($error, 2000) : null,
        ]);
    }

    private function write(array $attributes): void
    {
        try {
            SignatureAuditLog::create($attributes);
        } catch (\Throwable $e) {
            // The audit row is bookkeeping around the caller's real work.
            Log::warning('SignatureAuditLogger: failed to persist audit row', [
                'provider' => $attributes['provider'] ?? null,
                'action'   => $attributes['action'] ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function decodeBody(HttpRequest $httpRequest): ?array
    {
        $decoded = json_decode($httpRequest->getContent(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Recursively strip secrets, elide file bodies, truncate long strings. */
    private function sanitize(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            $normalized = is_string($key) ? strtolower($key) : $key;

            if (is_string($normalized) && in_array($normalized, self::REDACTED_KEYS, true)) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_string($normalized) && in_array($normalized, self::ELIDED_KEYS, true) && is_string($value)) {
                $clean[$key] = '[elided: ' . strlen($value) . ' bytes]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
                continue;
            }

            if (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
                $clean[$key] = substr($value, 0, self::MAX_STRING_LENGTH) . '…[truncated]';
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
