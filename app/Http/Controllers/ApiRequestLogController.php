<?php

namespace App\Http\Controllers;

use App\Models\ApiRequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiRequestLogController extends Controller
{
    /**
     * Paginated, filtered list of API request logs for the authenticated
     * user's company. Powers the API Logs dashboard table.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'search'         => 'sometimes|string|max:255',
            'date_from'      => 'sometimes|date',
            'date_to'        => 'sometimes|date',
            'endpoint'       => 'sometimes|string|max:255',
            'method'         => 'sometimes|string|max:10',
            'status'         => 'sometimes|in:success,failure',
            'status_code'    => 'sometimes|integer',
            'api_token_id'   => 'sometimes|integer',
            'min_duration_ms' => 'sometimes|integer',
            'max_duration_ms' => 'sometimes|integer',
            'per_page'       => 'sometimes|integer|min:1|max:100',
            'page'           => 'sometimes|integer|min:1',
        ]);

        $query = ApiRequestLog::fromCompany($user->company_id)
            ->with('apiToken:id,name')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('endpoint', 'like', "%{$search}%")
                    ->orWhere('full_url', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('request_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('endpoint')) {
            $query->where('endpoint', $request->input('endpoint'));
        }

        if ($request->filled('method')) {
            $query->where('method', strtoupper($request->input('method')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('status_code')) {
            $query->where('response_status', $request->input('status_code'));
        }

        if ($request->filled('api_token_id')) {
            $query->where('api_token_id', $request->input('api_token_id'));
        }

        if ($request->filled('min_duration_ms')) {
            $query->where('duration_ms', '>=', $request->input('min_duration_ms'));
        }

        if ($request->filled('max_duration_ms')) {
            $query->where('duration_ms', '<=', $request->input('max_duration_ms'));
        }

        $perPage = $request->input('per_page', 25);
        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    /**
     * The distinct endpoints/methods this company has traffic for, to
     * populate the filter dropdowns without scanning the whole table client-side.
     */
    public function filterOptions(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $endpoints = ApiRequestLog::fromCompany($user->company_id)
            ->select('endpoint')
            ->distinct()
            ->orderBy('endpoint')
            ->pluck('endpoint');

        $methods = ApiRequestLog::fromCompany($user->company_id)
            ->select('method')
            ->distinct()
            ->orderBy('method')
            ->pluck('method');

        return response()->json([
            'endpoints' => $endpoints,
            'methods'   => $methods,
        ]);
    }

    /**
     * Full detail for a single log entry (request/response/metadata).
     */
    public function show(Request $request, int $id)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $log = ApiRequestLog::fromCompany($user->company_id)
            ->with('apiToken:id,name', 'user:id,name')
            ->find($id);

        if (!$log) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        return response()->json(['data' => $log]);
    }
}
