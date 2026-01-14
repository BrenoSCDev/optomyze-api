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
        $token = ApiToken::where('token', $tokenValue)->first();

        if (!$token) {
            return response()->json(['message' => 'Invalid token', 'token' => $token], 401);
        }

        // Attach company to request
        $request->merge(['company' => $token->company]);

        return $next($request);
    }
}
