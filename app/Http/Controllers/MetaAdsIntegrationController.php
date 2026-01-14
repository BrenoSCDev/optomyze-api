<?php

namespace App\Http\Controllers;

use App\Models\MetaAdsIntegration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MetaAdsIntegrationController extends Controller
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
        $randomName = 'meta_ads_' . Str::random(8);

        try {
            $integration = MetaAdsIntegration::create([
                'company_id' => $user->company_id,
                'name' => $randomName
            ]);

            return response()->json([
                'message' => 'Meta Ads integration created successfully.',
                'integration' => $integration
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create Meta Ads integration.',
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
        $integration = MetaAdsIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            // Validate incoming request using model’s validation method
            $validated = MetaAdsIntegration::validateConfig($request->all());

            // If account_id is provided, set act_id
            if (!empty($validated['account_id'])) {
                $validated['act_id'] = "act_{$validated['account_id']}";
            }

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

    public function fetchMetaData(Request $request)
    {
        try {
            $user = $request->user();

            // Retrieve the first integration for the user's company
            $integration = MetaAdsIntegration::where('company_id', $user->company_id)->first();

            if (!$integration || !$integration->act_id || !$integration->access_token) {
                return response()->json([
                    'message' => 'Meta Ads integration not configured for this company.'
                ], 404);
            }

            // Build dynamic 30-day date range
            $until = Carbon::today()->format('Y-m-d');
            $since = Carbon::today()->subDays(30)->format('Y-m-d');

            // Build Graph API URL
            $url = "https://graph.facebook.com/v23.0/{$integration->act_id}/insights";

            // Build query params
            $params = [
                'time_range' => json_encode([
                    'since' => $since,
                    'until' => $until,
                ]),
                'level' => 'campaign',
                'date_preset' => 'today',
                'fields' => 'campaign_id,campaign_name,impressions,reach,frequency,clicks,spend,ctr,cpc,cpm,actions,action_values',
                'time_increment' => 1,
                'limit' => 500,
                'access_token' => $integration->access_token,
            ];

            // Send request
            $response = Http::get($url, $params);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to fetch data from Meta Ads API.',
                    'error' => $response->json(),
                ], $response->status());
            }

            return response()->json([
                'message' => 'Meta Ads data fetched successfully.',
                'data' => $response->json(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong while fetching Meta Ads data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
