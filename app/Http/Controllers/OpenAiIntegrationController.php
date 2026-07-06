<?php

namespace App\Http\Controllers;

use App\Models\OpenAiIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class OpenAiIntegrationController extends Controller
{
    private const OPENAI_API_BASE = 'https://api.openai.com/v1';

    /**
     * Chat-capable models offered in the AI Agent form. The /models endpoint
     * returns dozens of fine-tune/embedding ids, so we curate.
     */
    private const SUPPORTED_MODELS = [
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4.1',
        'gpt-4.1-mini',
        'gpt-4.1-nano',
    ];

    /**
     * Create the (single, per-company) OpenAI integration row.
     */
    public function createIntegration(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $existing = OpenAiIntegration::fromCompany($user->company_id)->first();

        if ($existing) {
            return response()->json([
                'message' => 'OpenAI integration already exists.',
                'integration' => $this->formatIntegration($existing),
            ], 200);
        }

        try {
            $integration = OpenAiIntegration::create([
                'company_id' => $user->company_id,
                'is_active' => true,
            ]);

            return response()->json([
                'message' => 'OpenAI integration created successfully.',
                'integration' => $this->formatIntegration($integration),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create OpenAI integration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return the current state of the company's OpenAI integration.
     */
    public function show(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = OpenAiIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        return response()->json($this->formatIntegration($integration), 200);
    }

    /**
     * Save the OpenAI API key and immediately test it.
     */
    public function configure(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = OpenAiIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            $validated = OpenAiIntegration::validateConfig($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $integration->update([
            'api_key' => $validated['api_key'],
            'organization_id' => $validated['organization_id'] ?? null,
            'connection_status' => OpenAiIntegration::CONNECTION_NOT_CONFIGURED,
        ]);

        return $this->testConnection($request);
    }

    /**
     * Test the saved API key against the OpenAI API.
     */
    public function testConnection(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = OpenAiIntegration::fromCompany($user->company_id)->first();

        if (!$integration || !$integration->api_key) {
            return response()->json([
                'message' => 'OpenAI API key is not configured.'
            ], 422);
        }

        try {
            $response = $this->openAiRequest($integration)->get(self::OPENAI_API_BASE . '/models');

            if ($response->failed()) {
                $integration->update([
                    'connection_status' => OpenAiIntegration::CONNECTION_ERROR,
                ]);

                return response()->json([
                    'message' => 'Failed to connect to OpenAI. Please check your API key.',
                    'error' => $response->json('error.message') ?? $response->body(),
                    'integration' => $this->formatIntegration($integration),
                ], 422);
            }

            $integration->update([
                'connection_status' => OpenAiIntegration::CONNECTION_CONNECTED,
                'last_tested_at' => now(),
            ]);

            return response()->json([
                'message' => 'OpenAI connected successfully.',
                'integration' => $this->formatIntegration($integration),
                'models' => $this->filterSupportedModels($response->json('data') ?? []),
            ], 200);
        } catch (\Exception $e) {
            $integration->update([
                'connection_status' => OpenAiIntegration::CONNECTION_ERROR,
            ]);

            return response()->json([
                'message' => 'Something went wrong while connecting to OpenAI.',
                'error' => $e->getMessage(),
                'integration' => $this->formatIntegration($integration),
            ], 422);
        }
    }

    /**
     * List the chat models available to the connected account, for the
     * AI Agent form's model dropdown.
     */
    public function listModels(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = OpenAiIntegration::fromCompany($user->company_id)->first();

        if (!$integration || !$integration->api_key) {
            return response()->json([
                'message' => 'OpenAI API key is not configured.'
            ], 422);
        }

        try {
            $response = $this->openAiRequest($integration)->get(self::OPENAI_API_BASE . '/models');

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to fetch models from OpenAI.',
                    'error' => $response->json('error.message') ?? $response->body(),
                ], 422);
            }

            return response()->json([
                'models' => $this->filterSupportedModels($response->json('data') ?? []),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong while fetching OpenAI models.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove the stored API key so the company can connect a different
     * account. Agents using this provider stop working until reconfigured.
     */
    public function disconnect(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = OpenAiIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        $integration->update([
            'api_key' => null,
            'organization_id' => null,
            'connection_status' => OpenAiIntegration::CONNECTION_NOT_CONFIGURED,
            'last_tested_at' => null,
        ]);

        return response()->json([
            'message' => 'OpenAI disconnected successfully.',
            'integration' => $this->formatIntegration($integration),
        ], 200);
    }

    private function openAiRequest(OpenAiIntegration $integration)
    {
        $request = Http::withToken($integration->api_key)->timeout(30);

        if ($integration->organization_id) {
            $request = $request->withHeaders(['OpenAI-Organization' => $integration->organization_id]);
        }

        return $request;
    }

    private function filterSupportedModels(array $models): array
    {
        $available = collect($models)->pluck('id')->all();

        return array_values(array_filter(
            self::SUPPORTED_MODELS,
            fn (string $model) => in_array($model, $available, true)
        ));
    }

    private function formatIntegration(OpenAiIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'has_api_key' => !empty($integration->api_key),
            'api_key_hint' => $integration->apiKeyHint(),
            'organization_id' => $integration->organization_id,
            'connection_status' => $integration->connection_status,
            'last_tested_at' => $integration->last_tested_at,
            'is_active' => $integration->is_active,
        ];
    }
}
