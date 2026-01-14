<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppEvoIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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

        // Generate a random, unique name for the integration
        $randomName = 'wpp_evo_' . Str::random(8);

        try {
            $integration = WhatsAppEvoIntegration::create([
                'name' => $randomName,
                'company_id' => $user->company_id,
                'api_key' => null,
                'base_url' => null,
                'is_active' => true,
            ]);

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
     * Update base_url and api_key configuration for n8n integration
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
