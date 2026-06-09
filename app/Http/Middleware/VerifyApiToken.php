<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $token = ApiToken::where('token', $tokenValue)->with('company')->first();

        if (!$token) {
            return response()->json(['message' => 'Invalid token'], 401);
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
}
