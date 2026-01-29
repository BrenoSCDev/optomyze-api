<?php

namespace App\Http\Controllers;

use App\Models\N8nAgent;
use App\Models\N8nIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class N8nAgentController extends Controller
{
    /**
     * Display all tags from the authenticated user's company.
     */
    public function index(Request $request)
    {
        // Get the authenticated user via Sanctum
        $user = Auth::user();

        // Assuming user has company_id column
        $agents = N8nAgent::where('company_id', $user->company_id)
            ->with('reports')
            ->get();

        return response()->json([
            'data' => $agents
        ], 200);
    }

    /**
     * Display all executions from n8n workflow.
     */
    public function fetchExecutions(N8nAgent $agent)
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
            $url = rtrim($integration->base_url, '/') . '/api/v1/executions?workflowId=' . $agent->workflow_id;

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

            $executions = $response->json()['data'] ?? [];

            return response()->json([
                'message' => 'Executions fetched successfully.',
                'executions' => $executions,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error communicating with n8n.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch AI Agent system prompt from n8n workflow.
     */
    public function fetchAiAgentSystemPrompt(N8nAgent $agent)
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
            $url = rtrim($integration->base_url, '/') . '/api/v1/workflows/' . $agent->workflow_id;

            $response = Http::withHeaders([
                'X-N8N-API-KEY' => $integration->api_key,
            ])->get($url);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to fetch workflow from n8n.',
                    'status' => $response->status(),
                    'error' => $response->json() ?? $response->body(),
                ], $response->status());
            }

            $workflow = $response->json();

            if (!isset($workflow['nodes']) || !is_array($workflow['nodes'])) {
                return response()->json([
                    'message' => 'Invalid workflow structure returned by n8n.'
                ], 500);
            }

            // 🔍 Find AI Agent node
            $aiAgentNode = collect($workflow['nodes'])->first(function ($node) {
                return ($node['name'] ?? null) === 'AI Agent'
                    && ($node['type'] ?? null) === '@n8n/n8n-nodes-langchain.agent';
            });

            if (!$aiAgentNode) {
                return response()->json([
                    'message' => 'AI Agent node not found in workflow.'
                ], 404);
            }

            // 🧠 Extract system prompt safely
            $systemPrompt = data_get(
                $aiAgentNode,
                'parameters.options.systemMessage'
            );

            return response()->json([
                'message' => 'AI Agent system prompt fetched successfully.',
                'workflow_id' => $workflow['id'] ?? $agent->workflow_id,
                'node_id' => $aiAgentNode['id'] ?? null,
                'node_name' => $aiAgentNode['name'] ?? 'AI Agent',
                'system_prompt' => $systemPrompt,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error communicating with n8n.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
