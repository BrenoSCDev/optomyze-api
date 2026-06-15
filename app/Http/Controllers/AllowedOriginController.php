<?php

namespace App\Http\Controllers;

use App\Models\AllowedOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AllowedOriginController extends Controller
{
    /**
     * Display all allowed origins for the authenticated user's company.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $origins = AllowedOrigin::fromCompany($user->company_id)->get();

        return response()->json(['data' => $origins], 200);
    }

    /**
     * Store a new allowed origin.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate(AllowedOrigin::validationRules());

        if (AllowedOrigin::existsForCompany($user->company_id, $validated['url'])) {
            return response()->json([
                'message' => 'This origin already exists.'
            ], 422);
        }

        $origin = AllowedOrigin::create([
            'company_id' => $user->company_id,
            'url' => $validated['url'],
        ]);

        return response()->json([
            'message' => 'Allowed origin created successfully.',
            'data' => $origin,
        ], 201);
    }

    /**
     * Update an existing allowed origin's URL.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $origin = AllowedOrigin::fromCompany($user->company_id)->findOrFail($id);

        $validated = $request->validate(AllowedOrigin::updateValidationRules());

        if (
            strcasecmp($validated['url'], $origin->url) !== 0
            && AllowedOrigin::existsForCompany($user->company_id, $validated['url'])
        ) {
            return response()->json([
                'message' => 'This origin already exists.'
            ], 422);
        }

        $origin->update($validated);

        return response()->json([
            'message' => 'Allowed origin updated successfully.',
            'data' => $origin,
        ]);
    }

    /**
     * Remove the specified allowed origin.
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $origin = AllowedOrigin::fromCompany($user->company_id)->findOrFail($id);
        $origin->delete();

        return response()->json(['message' => 'Allowed origin deleted successfully']);
    }
}
