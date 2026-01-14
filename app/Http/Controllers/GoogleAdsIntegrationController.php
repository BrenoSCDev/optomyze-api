<?php

namespace App\Http\Controllers;

use App\Models\GoogleAdsIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class GoogleAdsIntegrationController extends Controller
{
        /**
     * Create a new n8n integration instance for the authenticated user's company
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
        $randomName = 'google_ads_' . Str::random(8);

        try {
            $integration = GoogleAdsIntegration::create([
                'company_id' => $user->company_id,
                'name' => $randomName
            ]);

            return response()->json([
                'message' => 'Google Ads integration created successfully.',
                'integration' => $integration
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create Google Ads integration.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update webhook_url and api_key configuration for n8n integration
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
        $integration = GoogleAdsIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            // Validate only the fields sent in the request
            $validatedData = GoogleAdsIntegration::validateConfig($request->all());

            // Update only the fields that are present
            foreach ($validatedData as $key => $value) {
                $integration->$key = $value;
            }

            $integration->save();

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

    public function fetchGoogleData(Request $request)
    {
        $user = $request->user();

        // Retrieve the first integration for the user's company
        $integration = GoogleAdsIntegration::where('company_id', $user->company_id)->first();

        if (
            !$integration ||
            !$integration->customer_id ||
            !$integration->manager_id ||
            !$integration->developer_token ||
            !$integration->webhook_url
        ) {
            return response()->json([
                'message' => 'Google Ads integration not configured for this company.'
            ], 404);
        }

        // Build Graph API URL (or external webhook endpoint)
        $url = $integration->webhook_url;

        // Build request body
        $body = [
            'developer_token' => $integration->developer_token,
            'customer_id'     => $integration->customer_id,
            'manager_id'      => $integration->manager_id,
        ];

        try {
            // Send POST request to webhook
            $response = Http::post($url, $body);

            // Check for success
            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to send data to Google Ads webhook.',
                    'error' => $response->body(),
                ], 500);
            }

            // Return response from webhook
            return response()->json([
                'message' => 'Request sent successfully.',
                'data' => $response->json(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error sending request to webhook.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
