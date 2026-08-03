<?php

namespace App\Http\Controllers;

use App\Models\WidgetIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Install a widget type for the signed-in company.
 *
 * Same contract as the other integrations' createIntegration endpoints:
 * POST is idempotent, and installing something already installed is a 200 with
 * the existing record rather than an error — the frontend fires this from a
 * card that may already have been clicked.
 */
class WidgetIntegrationController extends Controller
{
    public function createIntegration(Request $request, string $integrationKey)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $type = WidgetIntegration::typeFromIntegrationKey($integrationKey);

        if (!$type) {
            return response()->json(['message' => 'Unknown widget type.'], 404);
        }

        $integration = WidgetIntegration::fromCompany($user->company_id)
            ->ofType($type)
            ->first();

        if ($integration) {
            // Re-installing something that was deactivated turns it back on.
            if (!$integration->is_active) {
                $integration->update(['is_active' => true]);
            }

            return response()->json([
                'message'     => 'Widget integration already exists.',
                'integration' => $integration,
            ], 200);
        }

        $integration = WidgetIntegration::create([
            'company_id' => $user->company_id,
            'type'       => $type,
            'is_active'  => true,
        ]);

        return response()->json([
            'message'     => 'Widget integration created successfully.',
            'integration' => $integration,
        ], 201);
    }
}
