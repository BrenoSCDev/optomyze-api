<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The authenticated user's own preferences — a singleton resource, so there is
 * no id in the route and no way to address anyone else's row.
 */
class UserPreferenceController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->preferencesFor(Auth::id())]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(UserPreference::validationRules());

        $preferences = $this->preferencesFor(Auth::id());

        // Only ever writes keys the user actually sent, so a partial update
        // (one toggle) cannot reset the rest of the row to defaults.
        $preferences->update($validated);

        return response()->json([
            'message' => 'Preferences updated',
            'data'    => $preferences->fresh(),
        ]);
    }

    /**
     * Reset to the shipped defaults.
     */
    public function destroy(): JsonResponse
    {
        $preferences = $this->preferencesFor(Auth::id());
        $preferences->update(UserPreference::DEFAULTS);

        return response()->json([
            'message' => 'Preferences reset',
            'data'    => $preferences->fresh(),
        ]);
    }

    /**
     * The row is created on first read rather than at signup, so users who
     * predate this feature get one the moment they load the app.
     */
    private function preferencesFor(int $userId): UserPreference
    {
        return UserPreference::firstOrCreate(
            ['user_id' => $userId],
            UserPreference::DEFAULTS,
        );
    }
}
