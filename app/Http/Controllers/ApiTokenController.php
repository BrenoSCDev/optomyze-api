<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiTokenController extends Controller
{
    /**
     * List all tokens for a company
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $tokens = ApiToken::where('company_id', $user->company_id)->get();

        return response()->json($tokens);
    }

    /**
     * Create a new API token
     */
    public function generate(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $token = ApiToken::generateToken($user->company_id, $request->name);

        return response()->json($token);
    }

    /**
     * Delete a token by ID
     */
    public function destroy(Request $request, $id)
    {
        $token = ApiToken::findOrFail($id);
        $token->delete();

        return response()->json(['message' => 'Token deleted successfully']);
    }
}
