<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\ApiToken;

class VerifyApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tokenValue = $matches[1];
        $token = ApiToken::where('token', $tokenValue)->with(['company', 'allowedOrigins'])->first();

        if (!$token) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        if ($token->isExpired()) {
            return response()->json(['message' => 'Token expired'], 401);
        }

        // Enforce per-token rate limit (requests per minute)
        if ($token->rate_limit_per_minute !== null) {
            $rateLimitKey = 'api_token:' . $token->id;

            if (RateLimiter::tooManyAttempts($rateLimitKey, $token->rate_limit_per_minute)) {
                return response()->json(['message' => 'Too many requests'], 429)
                    ->header('Retry-After', RateLimiter::availableIn($rateLimitKey));
            }

            RateLimiter::hit($rateLimitKey, 60);
        }

        if ($token->allowedOrigins->isNotEmpty()) {
            $requestOrigin = $request->header('Origin') ?: $request->header('Referer');
            $normalizedRequestOrigin = $requestOrigin ? $this->normalizeOrigin($requestOrigin) : null;

            $allowed = $token->allowedOrigins->contains(function ($allowedOrigin) use ($normalizedRequestOrigin) {
                return $normalizedRequestOrigin !== null
                    && $normalizedRequestOrigin === $this->normalizeOrigin($allowedOrigin->url);
            });

            if (!$allowed) {
                return response()->json(['message' => 'Origin not allowed for this token'], 403);
            }
        }

        $company = $token->company;

        // Enforce monthly api_requests_limit
        if ($company->api_requests_limit !== null) {
            // Reset counter on new month
            if ($company->api_requests_reset_at === null || now()->gte($company->api_requests_reset_at)) {
                $company->update([
                    'api_requests_count'    => 0,
                    'api_requests_reset_at' => now()->addMonthNoOverflow()->startOfMonth(),
                ]);
            }

            if ($company->api_requests_count >= $company->api_requests_limit) {
                return response()->json(['message' => 'Monthly API request limit exceeded'], 429);
            }

            $company->increment('api_requests_count');
        }

        $request->merge(['company' => $company, 'api_token' => $token]);

        return $next($request);
    }

    /**
     * Normalize a URL down to its scheme://host[:port] form for comparison.
     */
    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url($url);

        if (!isset($parts['scheme']) || !isset($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return strtolower($origin);
    }
}
