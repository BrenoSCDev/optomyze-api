<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\N8nAgent;
use App\Models\N8nIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class N8nIntegrationController extends Controller
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
        $randomName = 'n8n_' . Str::random(8);

        try {
            $integration = N8nIntegration::create([
                'company_id' => $user->company_id,
                'name' => $randomName,
                'webhook_url' => null,
                'api_key' => null,
                'base_url' => null,
                'settings' => [],
                'is_active' => true,
                'sync_status' => [],
            ]);

            return response()->json([
                'message' => 'N8n integration created successfully.',
                'integration' => $integration
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create n8n integration.',
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
        $integration = N8nIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            // Validate incoming request using model’s validation method
            $validated = N8nIntegration::validateConfig($request->only([
                'webhook_url',
                'base_url',
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
     * Update webhook_url and api_key configuration for n8n integration
     */
    public function configureData()
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        // Fetch integration for this company
        $integration = N8nIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        return response()->json([
            'integration' => $integration
        ], 200);
    }
    
    /**
     * Fetch workflows from n8n for the authenticated user's company
     */

    public function fetchWorkflows(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        // Get active integration
        $integration = N8nIntegration::where('company_id', $user->company_id)
            ->active()
            ->first();

        if (!$integration || !$integration->base_url || !$integration->api_key) {
            return response()->json([
                'message' => 'No configured n8n integration found for this company.'
            ], 404);
        }

        try {
            $url = rtrim($integration->base_url, '/') . '/api/v1/workflows';

            $response = Http::withHeaders([
                'X-N8N-API-KEY' => $integration->api_key,
            ])->get($url);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to fetch workflows from n8n.',
                    'status' => $response->status(),
                    'error' => $response->json() ?? $response->body(),
                ], $response->status());
            }

            $workflows = $response->json()['data'] ?? [];
            $createdAgents = [];
            $companyTagsFound = [];
            $companyNames = [];

            foreach ($workflows as $workflow) {
                // Find company tag
                $companyTag = collect($workflow['tags'] ?? [])
                    ->first(fn($tag) => Str::startsWith($tag['name'], 'company:'));

                if (!$companyTag) {
                    continue; // skip workflows without company tag
                }

                $companyName = trim(Str::after($companyTag['name'], 'company:'));
                $companyTagsFound[] = $companyName;

                // Find company in database
                $company = Company::where('name', $companyName)->first();
                $companyNames[] = $company->name;

                if (!$company) {
                    continue; // skip if company not found
                }

                // Find platform tag
                $platformTag = collect($workflow['tags'] ?? [])
                    ->first(fn($tag) => Str::startsWith($tag['name'], 'type:'));
                $platform = $platformTag ? trim(Str::after($platformTag['name'], 'type:')) : 'unknown';

                // Create or update N8nAgent
                $agent = N8nAgent::updateOrCreate(
                    [
                        'workflow_id' => $workflow['id'],
                        'n8n_integration_id' => $integration->id,
                    ],
                    [
                        'company_id' => $company->id,
                        'workflow_name' => $workflow['name'] ?? null,
                        'platform' => $platform,
                        'agent_name' => $workflow['name'] ?? 'Unnamed Agent',
                        'description' => $workflow['meta']['description'] ?? null,
                        'configuration' => json_encode($workflow['settings'] ?? []),
                        'status' => $workflow['active'] ? 'active' : 'inactive',
                    ]
                );

                $createdAgents[] = $agent;
            }

            return response()->json([
                'message' => 'Workflows fetched and agents created successfully.',
                'company_tags_found' => $companyTagsFound,
                'companyNames' => $companyNames,
                'agents_created' => $createdAgents,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error communicating with n8n.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}