<?php

namespace App\Http\Middleware;

use App\Models\RolePermission;
use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $hasPermission = RolePermission::where('company_id', $user->company_id)
            ->where('role', $user->role)
            ->where('permission_key', $permission)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'message'    => 'Forbidden. You do not have permission to perform this action.',
                'permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
