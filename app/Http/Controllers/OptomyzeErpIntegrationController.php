<?php

namespace App\Http\Controllers;

use App\Models\OptomyzeErpIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class OptomyzeErpIntegrationController extends Controller
{
    /**
     * Create a new optomyze erp integration instance for the authenticated user's company
     */
    public function createIntegration(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        // Generate a random, unique name for the integration
        $randomName = 'optomyze_erp_' . Str::random(8);

        try {
            $integration = OptomyzeErpIntegration::create([
                'company_id' => $user->company_id,
                'name' => $randomName,
                'api_key' => null,
                'is_active' => true,
            ]);

            return response()->json([
                'message' => 'Optomyze ERP integration created successfully.',
                'integration' => $integration
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create Optomyze ERP integration.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update api_key configuration for optomyze erp integration
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
        $integration = OptomyzeErpIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            // Validate incoming request using model’s validation method
            $validated = OptomyzeErpIntegration::validateConfig($request->only([
                'api_key',
            ]));

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

    /**
     * Get api_key from a optomyze erp integration
     */
    public function getApiKey(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        // Fetch integration for this company
        $integration = OptomyzeErpIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        return response()->json([
            'message' => 'Integration configuration retrieved successfully.',
            'api_key' => $integration->api_key
        ], 200);
    }
}