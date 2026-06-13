<?php

namespace App\Http\Controllers;

use App\Models\MetaAdsIntegration;
use App\Traits\ResolvesMetaAdsDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaAdsIntegrationController extends Controller
{
    use ResolvesMetaAdsDateRange;

    /**
     * Campaign-level insight fields - covers spend efficiency, audience
     * (reach/frequency/unique CTR), link-click quality, and conversion actions.
     */
    private const CAMPAIGN_FIELDS = 'campaign_id,campaign_name,objective,impressions,reach,frequency,clicks,inline_link_clicks,unique_clicks,unique_ctr,spend,ctr,cpc,cpm,cost_per_inline_link_click,cpp,actions,action_values,cost_per_action_type';

    /**
     * Ad set-level insight fields - lighter field set for the ad set breakdown.
     */
    private const ADSET_FIELDS = 'adset_id,adset_name,campaign_id,campaign_name,impressions,reach,frequency,clicks,spend,ctr,cpc,actions,action_values';

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
            $request->validate([
                'date_range' => 'nullable|in:today,yesterday,7_days,30_days,this_month,last_month,custom',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
            ]);

            $user = $request->user();

            // Retrieve the first integration for the user's company
            $integration = MetaAdsIntegration::where('company_id', $user->company_id)->first();

            if (!$integration || !$integration->act_id || !$integration->access_token) {
                return response()->json([
                    'message' => 'Meta Ads integration not configured for this company.'
                ], 404);
            }

            [$start, $end, $prevStart, $prevEnd] = $this->resolveMetaAdsDateRange($request);

            $url = "https://graph.facebook.com/v23.0/{$integration->act_id}/insights";
            $token = $integration->access_token;

            // Call 1 (critical) - current period, campaign-level, daily granularity.
            // Drives KPIs, trend charts, campaign intelligence, and insights.
            $currentResponse = Http::get($url, [
                'level' => 'campaign',
                'time_range' => json_encode(['since' => $start->format(self::META_ADS_DATE_FORMAT), 'until' => $end->format(self::META_ADS_DATE_FORMAT)]),
                'time_increment' => 1,
                'limit' => 500,
                'fields' => self::CAMPAIGN_FIELDS,
                'access_token' => $token,
            ]);

            if ($currentResponse->failed()) {
                return response()->json([
                    'message' => 'Failed to fetch data from Meta Ads API.',
                    'error' => $currentResponse->json(),
                ], $currentResponse->status());
            }

            // Call 2 - previous period, campaign-level, aggregated (one row per
            // campaign for the whole period) - used only for KPI deltas.
            $previousRows = $this->fetchInsightsSafely($url, [
                'level' => 'campaign',
                'time_range' => json_encode(['since' => $prevStart->format(self::META_ADS_DATE_FORMAT), 'until' => $prevEnd->format(self::META_ADS_DATE_FORMAT)]),
                'limit' => 500,
                'fields' => self::CAMPAIGN_FIELDS,
                'access_token' => $token,
            ]);

            // Call 3 - ad set level, aggregated for the current period.
            $adsetRows = $this->fetchInsightsSafely($url, [
                'level' => 'adset',
                'time_range' => json_encode(['since' => $start->format(self::META_ADS_DATE_FORMAT), 'until' => $end->format(self::META_ADS_DATE_FORMAT)]),
                'limit' => 500,
                'fields' => self::ADSET_FIELDS,
                'access_token' => $token,
            ]);

            return response()->json([
                'message' => 'Meta Ads data fetched successfully.',
                'range' => [
                    'date_range' => $request->query('date_range', '30_days'),
                    'current' => ['since' => $start->format(self::META_ADS_DATE_FORMAT), 'until' => $end->format(self::META_ADS_DATE_FORMAT)],
                    'previous' => ['since' => $prevStart->format(self::META_ADS_DATE_FORMAT), 'until' => $prevEnd->format(self::META_ADS_DATE_FORMAT)],
                ],
                'data' => [
                    'current' => $currentResponse->json('data', []),
                    'previous' => $previousRows,
                ],
                'adsets' => $adsetRows,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong while fetching Meta Ads data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch insight rows for a non-critical call. Returns an empty array
     * (instead of failing the whole request) if the Graph API call errors -
     * e.g. the access token lacks ad set read permissions.
     */
    private function fetchInsightsSafely(string $url, array $params): array
    {
        try {
            $response = Http::get($url, $params);

            if ($response->failed()) {
                Log::warning('Meta Ads insights call failed', ['params' => $params, 'error' => $response->json()]);
                return [];
            }

            return $response->json('data', []);
        } catch (\Exception $e) {
            Log::warning('Meta Ads insights call threw', ['params' => $params, 'error' => $e->getMessage()]);
            return [];
        }
    }

}
