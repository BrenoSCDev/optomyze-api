<?php

namespace App\Http\Controllers;

use App\Models\AllowedOrigin;
use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiTokenController extends Controller
{
    /**
     * List all tokens for a company.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $tokens = ApiToken::where('company_id', $user->company_id)
            ->with('allowedOrigins')
            ->get();

        return response()->json($tokens);
    }

    /**
     * Create a new API token, respecting the company's api_tokens_limit.
     */
    public function generate(Request $request)
    {
        $user = Auth::user();
        $company = $user->company;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'expires_at' => 'nullable|date|after:now',
            'allowed_origin_ids' => 'nullable|array',
            'allowed_origin_ids.*' => 'integer|exists:allowed_origins,id',
            'rate_limit_per_minute' => 'nullable|integer|min:1',
        ]);

        if ($company->api_tokens_limit !== null) {
            $tokenCount = ApiToken::where('company_id', $company->id)->count();

            if ($tokenCount >= $company->api_tokens_limit) {
                return response()->json([
                    'message' => 'API token limit reached for your subscription plan.',
                    'limit'   => $company->api_tokens_limit,
                    'current' => $tokenCount,
                ], 403);
            }
        }

        $token = ApiToken::generateToken(
            $user->company_id,
            $request->name,
            $request->expires_at,
            $validated['rate_limit_per_minute'] ?? null
        );

        if (!empty($validated['allowed_origin_ids'])) {
            $originIds = AllowedOrigin::fromCompany($user->company_id)
                ->whereIn('id', $validated['allowed_origin_ids'])
                ->pluck('id');

            $token->allowedOrigins()->sync($originIds);
        }

        $token->load('allowedOrigins');

        return response()->json($token);
    }

    /**
     * Update a token's allowed origins and/or rate limit.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $token = ApiToken::where('company_id', $user->company_id)->findOrFail($id);

        $validated = $request->validate([
            'allowed_origin_ids' => 'nullable|array',
            'allowed_origin_ids.*' => 'integer|exists:allowed_origins,id',
            'rate_limit_per_minute' => 'nullable|integer|min:1',
        ]);

        $originIds = AllowedOrigin::fromCompany($user->company_id)
            ->whereIn('id', $validated['allowed_origin_ids'] ?? [])
            ->pluck('id');

        $token->allowedOrigins()->sync($originIds);

        if (array_key_exists('rate_limit_per_minute', $validated)) {
            $token->update(['rate_limit_per_minute' => $validated['rate_limit_per_minute']]);
        }

        $token->load('allowedOrigins');

        return response()->json($token);
    }

    /**
     * Delete a token by ID.
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();

        $token = ApiToken::where('company_id', $user->company_id)->findOrFail($id);
        $token->delete();

        return response()->json(['message' => 'Token deleted successfully']);
    }
}
