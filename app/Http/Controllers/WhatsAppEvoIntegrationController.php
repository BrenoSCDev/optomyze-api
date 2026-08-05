<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppEvoIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppEvoIntegrationController extends Controller
{
    public function createIntegration(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        try {
            // Installing WhatsApp no longer means "now go and fetch your
            // Evolution credentials" — the integration is born pointed at the
            // server we run, and the customer goes straight to connecting a
            // number. forCompany() is idempotent, so installing twice is safe.
            $integration = WhatsAppEvoIntegration::forCompany($user->company_id);

            return response()->json([
                'message' => 'WhatsApp integration created successfully.',
                'integration' => $integration
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create WhatsApp integration.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Point a company at its own Evolution server.
     *
     * Nothing in the CRM calls this any more — customers connect through the
     * server we host and are never shown a credentials form. It stays because
     * it is the whole custom-server story: writing base_url and api_key here
     * moves one tenant onto their own deployment, and every Evolution call
     * already reads those columns off the integration.
     */
    public function configure(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        // Fetch integration for this company
        $integration = WhatsAppEvoIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            // Validate incoming request using model’s validation method
            $validated = WhatsAppEvoIntegration::validateConfig($request->all());

            // Update integration
            $integration->update($validated);

            return response()->json([
                'message' => 'Integration configured successfully.',
                'integration' => $integration
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

}
