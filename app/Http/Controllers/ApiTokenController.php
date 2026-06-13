<?php

namespace App\Http\Controllers;

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

        $tokens = ApiToken::where('company_id', $user->company_id)->get();

        return response()->json($tokens);
    }

    /**
     * Create a new API token, respecting the company's api_tokens_limit.
     */
    public function generate(Request $request)
    {
        $user = Auth::user();
        $company = $user->company;

        $request->validate([
            'name' => 'required|string|max:255',
            'expires_at' => 'nullable|date|after:now',
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

        $token = ApiToken::generateToken($user->company_id, $request->name, $request->expires_at);

        return response()->json($token);
    }

    /**
     * Delete a token by ID.
     */
    public function destroy(Request $request, $id)
    {
        $token = ApiToken::findOrFail($id);
        $token->delete();

        return response()->json(['message' => 'Token deleted successfully']);
    }
}
