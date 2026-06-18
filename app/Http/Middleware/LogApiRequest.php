<?php

namespace App\Http\Middleware;

use App\Events\ApiRequestStarted;
use App\Jobs\ProcessApiRequestLog;
use App\Models\ApiRequestLog;
use App\Support\SensitiveDataMasker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Centralized observability layer for the external (API-token authenticated)
 * surface. Applied alongside `verify.api.token` so $request->company /
 * $request->api_token are already resolved.
 *
 * handle() only does cheap bookkeeping + a best-effort "started" broadcast
 * (wrapped in try/catch so a broadcaster hiccup never breaks the actual API
 * call). The expensive part - masking, capping, persisting, and the
 * "completed" broadcast - happens in terminate(), which Laravel runs after
 * the response has already been sent to the caller.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $startedAt = microtime(true);

        $request->attributes->set('__api_log_request_id', $requestId);
        $request->attributes->set('__api_log_started_at', $startedAt);

        $company = $request->get('company');

        if ($company) {
            try {
                broadcast(new ApiRequestStarted(
                    $company->id,
                    $requestId,
                    $request->method(),
                    optional($request->route())->uri() ?? $request->path(),
                    now()->toIso8601String(),
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast api-log.started', ['error' => $e->getMessage()]);
            }
        }

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    public function terminate(Request $request, $response): void
    {
        $requestId = $request->attributes->get('__api_log_request_id');
        $startedAt = $request->attributes->get('__api_log_started_at');
        $company   = $request->get('company');

        if (!$requestId || !$company) {
            return;
        }

        try {
            $apiToken = $request->get('api_token');
            $exception = $request->attributes->get('__api_log_exception');

            $durationMs = $startedAt ? (int) round((microtime(true) - $startedAt) * 1000) : null;
            $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;

            $isBinary = $response instanceof BinaryFileResponse || $response instanceof StreamedResponse;
            $responseBody = $isBinary ? ['__binary__' => true] : $this->decodeBody((string) $response->getContent());

            $isFailure = $exception !== null || ($statusCode !== null && $statusCode >= 400);

            // VerifyApiToken merges the resolved `company`/`api_token` models into
            // the request input - strip those (and query keys, captured separately
            // below) so request_body only reflects what the caller actually sent.
            $bodyParams = $request->except(array_merge(
                array_keys($request->query()),
                ['company', 'api_token']
            ));

            $payload = [
                'request_id'         => $requestId,
                'company_id'         => $company->id,
                'api_token_id'       => $apiToken?->id,
                'user_id'            => $request->user()?->id,
                'method'             => $request->method(),
                'endpoint'           => optional($request->route())->uri() ?? $request->path(),
                'full_url'           => substr($request->fullUrl(), 0, 2048),
                'ip_address'         => $request->ip(),
                'user_agent'         => substr((string) $request->userAgent(), 0, 512),
                'request_headers'    => SensitiveDataMasker::cap(SensitiveDataMasker::maskHeaders($request->headers->all())),
                'query_params'       => SensitiveDataMasker::cap(SensitiveDataMasker::maskArray($request->query())),
                'request_body'       => SensitiveDataMasker::cap(SensitiveDataMasker::maskArray($bodyParams)),
                'response_status'    => $statusCode,
                'response_headers'   => SensitiveDataMasker::cap(SensitiveDataMasker::maskHeaders($response->headers->all())),
                'response_body'      => SensitiveDataMasker::cap(is_array($responseBody) ? SensitiveDataMasker::maskArray($responseBody) : $responseBody),
                'duration_ms'        => $durationMs,
                'status'             => $isFailure ? ApiRequestLog::STATUS_FAILURE : ApiRequestLog::STATUS_SUCCESS,
                'exception_class'    => $exception ? get_class($exception) : null,
                'exception_message'  => $exception ? substr($exception->getMessage(), 0, 2000) : null,
                'exception_trace'    => $exception ? substr($exception->getTraceAsString(), 0, 5000) : null,
                'created_at'         => now(),
            ];

            ProcessApiRequestLog::dispatch($payload);
        } catch (\Throwable $e) {
            Log::error('Failed to queue API request log', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Decode a JSON response body, falling back to a truncated raw preview
     * for non-JSON content so we never choke on it.
     */
    private function decodeBody(string $content): mixed
    {
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : ['__raw__' => substr($content, 0, 2000)];
    }
}
