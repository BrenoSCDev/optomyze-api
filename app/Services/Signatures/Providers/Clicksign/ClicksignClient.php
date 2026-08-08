<?php

namespace App\Services\Signatures\Providers\Clicksign;

use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use App\Services\Signatures\Audit\SignatureAuditLogger;
use App\Services\Signatures\Exceptions\ProviderNotConfiguredException;
use App\Services\Signatures\Exceptions\SignatureProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP transport for Clicksign API 3.0.
 *
 * Only this class knows Clicksign speaks JSON:API. It handles the wire
 * concerns — the two required headers, the `{"data": {...}}` envelope, error
 * translation, rate limiting and audit logging — and hands the driver plain
 * arrays.
 *
 * Header notes, both from the docs and both easy to get wrong:
 *   - `Authorization` carries the raw access token, with NO "Bearer " prefix.
 *   - `Content-Type` must be `application/vnd.api+json`, not `application/json`.
 *
 * Rate limits are per *account*: 50 requests / 10s in production, 20/10s in
 * sandbox. One signature request costs six-plus calls, so 429 is a normal
 * operating condition here, not an exception — it is translated into a
 * retryable error carrying the reset time so the queue can wait it out.
 * https://developers.clicksign.com/docs/limite-de-requisicoes
 */
class ClicksignClient
{
    public function __construct(
        private readonly SignatureProviderAccount $account,
        private readonly SignatureAuditLogger $audit,
    ) {
    }

    public function get(string $path, string $action, array $query = [], ?SignatureRequest $context = null): array
    {
        return $this->send('GET', $path, null, $action, $context, $query);
    }

    public function post(string $path, array $payload, string $action, ?SignatureRequest $context = null): array
    {
        return $this->send('POST', $path, $payload, $action, $context);
    }

    public function patch(string $path, array $payload, string $action, ?SignatureRequest $context = null): array
    {
        return $this->send('PATCH', $path, $payload, $action, $context);
    }

    public function delete(string $path, string $action, ?SignatureRequest $context = null): array
    {
        return $this->send('DELETE', $path, null, $action, $context);
    }

    /**
     * Fetch a binary file from an absolute URL.
     *
     * Separate from send() because the response is not JSON:API and must not
     * be parsed as such — and because the file body must never reach the
     * audit table.
     *
     * The credentials are deliberately NOT sent unless the URL points at
     * Clicksign itself. Signed files are served from presigned S3 links that
     * carry their own authorisation in the query string, and S3 rejects a
     * request that also presents an Authorization header:
     *
     *   400 InvalidArgument — "Only one auth mechanism allowed"
     *
     * Sending our token to a third-party host would also leak it.
     */
    public function download(string $url, string $action, ?SignatureRequest $context = null): string
    {
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders($this->downloadHeaders($url))
                ->timeout($this->timeout())
                ->get($url);
        } catch (ConnectionException $e) {
            $this->audit->outbound(
                $this->account, ClicksignProvider::KEY, $action, 'GET', $url, null,
                null, null, $this->elapsed($startedAt), false, $e->getMessage(), $context
            );

            throw SignatureProviderException::retryable(
                "Could not reach Clicksign to download the signed file: {$e->getMessage()}"
            );
        }

        $success = $response->successful();

        $this->audit->outbound(
            $this->account, ClicksignProvider::KEY, $action, 'GET', $url, null,
            $response->status(),
            $success ? ['bytes' => strlen($response->body())] : ['body' => $response->body()],
            $this->elapsed($startedAt),
            $success,
            $success ? null : "HTTP {$response->status()}",
            $context
        );

        if (!$success) {
            throw $this->toException($response, "downloading the signed file from {$url}");
        }

        return $response->body();
    }

    private function send(
        string $method,
        string $path,
        ?array $payload,
        string $action,
        ?SignatureRequest $context = null,
        array $query = [],
    ): array {
        $url       = $this->url($path);
        $startedAt = microtime(true);

        try {
            $request = Http::withHeaders($this->headers())->timeout($this->timeout());

            $response = match (strtoupper($method)) {
                'GET'    => $request->get($url, $query),
                'POST'   => $request->withBody(json_encode($payload), 'application/vnd.api+json')->post($url),
                'PATCH'  => $request->withBody(json_encode($payload), 'application/vnd.api+json')->patch($url),
                'DELETE' => $request->delete($url),
                default  => throw SignatureProviderException::permanent("Unsupported HTTP method [{$method}]."),
            };
        } catch (ConnectionException $e) {
            $this->audit->outbound(
                $this->account, ClicksignProvider::KEY, $action, $method, $url, $payload,
                null, null, $this->elapsed($startedAt), false, $e->getMessage(), $context
            );

            // The vendor was unreachable — the request may never have been
            // seen, so this is worth another attempt.
            throw SignatureProviderException::retryable("Could not reach Clicksign: {$e->getMessage()}");
        }

        $decoded = $this->decode($response);
        $success = $response->successful();

        $this->audit->outbound(
            $this->account, ClicksignProvider::KEY, $action, $method, $url, $payload,
            $response->status(), $decoded, $this->elapsed($startedAt), $success,
            $success ? null : $this->errorMessage($response, $decoded), $context
        );

        if (!$success) {
            throw $this->toException($response, $action, $decoded);
        }

        return $decoded ?? [];
    }

    private function headers(): array
    {
        $token = $this->account->credential('access_token');

        if (empty($token)) {
            throw ProviderNotConfiguredException::missingCredentials(ClicksignProvider::KEY);
        }

        return [
            // Raw token, no scheme prefix — Clicksign rejects "Bearer <token>".
            'Authorization' => $token,
            'Content-Type'  => 'application/vnd.api+json',
            'Accept'        => 'application/vnd.api+json',
        ];
    }

    /**
     * Headers for a file fetch: authenticated only when the host is Clicksign's.
     *
     * Compared on host, not a prefix match — "sandbox.clicksign.com.evil.test"
     * must not be treated as Clicksign.
     */
    private function downloadHeaders(string $url): array
    {
        $apiHost  = parse_url($this->account->baseUrl() ?: '', PHP_URL_HOST);
        $fileHost = parse_url($url, PHP_URL_HOST);

        if ($apiHost && $fileHost && strcasecmp($apiHost, $fileHost) === 0) {
            return $this->headers();
        }

        return ['Accept' => '*/*'];
    }

    private function url(string $path): string
    {
        $base = $this->account->baseUrl();

        if (empty($base)) {
            throw ProviderNotConfiguredException::missingCredentials(ClicksignProvider::KEY);
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private function timeout(): int
    {
        return (int) config('signatures.providers.clicksign.timeout', 30);
    }

    private function decode(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Turn a failed response into an exception the jobs can act on.
     *
     * The retryable/permanent split is the important part: a 422 means the
     * payload is wrong and will be wrong on every retry, while a 429 or 5xx
     * means try again later.
     */
    private function toException(Response $response, string $action, ?array $decoded = null): SignatureProviderException
    {
        $status  = $response->status();
        $message = $this->errorMessage($response, $decoded ?? $this->decode($response));

        if ($status === 429) {
            // X-Rate-Limit-Reset is a unix timestamp; convert to a wait.
            $reset = (int) $response->header('X-Rate-Limit-Reset');
            $wait  = $reset > 0 ? max(1, $reset - time()) : 10;

            return SignatureProviderException::retryable(
                "Clicksign rate limit reached while {$action}. Retrying in {$wait}s.",
                $status,
                ['action' => $action],
                min($wait, 600)
            );
        }

        if ($status >= 500) {
            return SignatureProviderException::retryable(
                "Clicksign returned a server error while {$action}: {$message}",
                $status,
                ['action' => $action]
            );
        }

        // 503 with an "envelope feature not enabled" body is documented for
        // webhook creation; it is still a configuration problem, not a blip.
        return SignatureProviderException::permanent(
            "Clicksign rejected the request while {$action}: {$message}",
            $status,
            ['action' => $action]
        );
    }

    /**
     * JSON:API errors arrive as {"errors": [{"title": ..., "detail": ...}]}.
     * Flatten them into one readable line for the UI and the audit log.
     */
    private function errorMessage(Response $response, ?array $decoded): string
    {
        $errors = $decoded['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $parts = array_map(function ($error) {
                $title  = $error['title'] ?? null;
                $detail = $error['detail'] ?? null;

                return trim(implode(' — ', array_filter([$title, $detail]))) ?: json_encode($error);
            }, $errors);

            return implode('; ', $parts);
        }

        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return trim($response->body()) !== '' ? mb_substr($response->body(), 0, 500) : "HTTP {$response->status()}";
    }
}
