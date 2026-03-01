<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyManagerKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-Manager-Key');

        if (!$key || $key !== config('app.manager_key')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
