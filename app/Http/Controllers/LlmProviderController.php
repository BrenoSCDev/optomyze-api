<?php

namespace App\Http\Controllers;

use App\Models\LlmProviderAccount;
use App\Services\Ai\LlmProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Connect a company to an LLM vendor. Provider-neutral: {provider} is a driver
 * key from config/llm.php, so a new vendor is reachable here the moment it is
 * registered — no method, route or frontend branch per vendor.
 */
class LlmProviderController extends Controller
{
    /**
     * Every registered vendor plus this company's account for each — what the
     * App Store modal and the AI Agent form both read.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        return response()->json([
            'default_provider' => LlmProviderRegistry::defaultKey(),
            'providers' => LlmProviderRegistry::catalog($user->company_id),
        ]);
    }

    /**
     * Install the integration — creates the (single, per-company) account row
     * with no credentials yet. This is what the App Store card's Install
     * button hits.
     */
    public function install(Request $request, string $provider)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        // The route constraint already rejects unregistered keys; this is the
        // second lock on the one method here that writes a provider key.
        if (!LlmProviderRegistry::supports($provider)) {
            return response()->json(['message' => "Unsupported provider [{$provider}]."], 404);
        }

        $account = LlmProviderAccount::firstOrCreate(
            ['company_id' => $user->company_id, 'provider' => $provider],
            ['is_active' => true],
        );

        return response()->json([
            'message' => LlmProviderRegistry::config($provider, 'label') . ' integration installed.',
            'account' => LlmProviderRegistry::formatAccount($account),
        ], $account->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $provider)
    {
        $account = $this->account($provider);

        if (!$account) {
            return response()->json(['message' => 'Integration not found for this company.'], 404);
        }

        return response()->json(LlmProviderRegistry::formatAccount($account));
    }

    /**
     * Save the vendor credentials and immediately verify them — a key that is
     * stored but never tested is indistinguishable from a working one until an
     * agent fails in front of a customer.
     */
    public function configure(Request $request, string $provider)
    {
        $account = $this->account($provider);

        if (!$account) {
            return response()->json(['message' => 'Integration not found for this company.'], 404);
        }

        try {
            $validated = LlmProviderAccount::validateConfig($provider, $request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $account->update(array_merge(
            ['connection_status' => LlmProviderAccount::CONNECTION_NOT_CONFIGURED],
            array_intersect_key($validated, array_flip(['api_key', 'organization_id'])),
        ));

        return $this->testConnection($request, $provider);
    }

    /**
     * Verify the stored key against the vendor by listing its models.
     */
    public function testConnection(Request $request, string $provider)
    {
        $account = $this->account($provider);

        if (!$account || !$account->api_key) {
            return response()->json(['message' => 'API key is not configured.'], 422);
        }

        try {
            $result = $this->fetchModels($provider, $account);
        } catch (\Throwable $e) {
            $account->update(['connection_status' => LlmProviderAccount::CONNECTION_ERROR]);

            return response()->json([
                'message' => 'Something went wrong while connecting.',
                'error' => $e->getMessage(),
                'account' => LlmProviderRegistry::formatAccount($account),
            ], 422);
        }

        if ($result['error'] !== null) {
            $account->update(['connection_status' => LlmProviderAccount::CONNECTION_ERROR]);

            return response()->json([
                'message' => 'Failed to connect. Please check your API key.',
                'error' => $result['error'],
                'account' => LlmProviderRegistry::formatAccount($account),
            ], 422);
        }

        $account->update([
            'connection_status' => LlmProviderAccount::CONNECTION_CONNECTED,
            'last_tested_at' => now(),
        ]);

        return response()->json([
            'message' => LlmProviderRegistry::config($provider, 'label') . ' connected successfully.',
            'account' => LlmProviderRegistry::formatAccount($account),
            'models' => $result['models'],
        ]);
    }

    /**
     * The chat models this company's key can actually reach — the agent form's
     * model dropdown.
     */
    public function models(Request $request, string $provider)
    {
        $account = $this->account($provider);

        if (!$account || !$account->api_key) {
            return response()->json(['message' => 'API key is not configured.'], 422);
        }

        try {
            $result = $this->fetchModels($provider, $account);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong while fetching models.',
                'error' => $e->getMessage(),
            ], 422);
        }

        if ($result['error'] !== null) {
            return response()->json([
                'message' => 'Failed to fetch models.',
                'error' => $result['error'],
            ], 422);
        }

        return response()->json(['models' => $result['models']]);
    }

    /**
     * Drop the stored key so the company can connect a different account.
     * Agents on this provider stop responding until it is reconfigured.
     */
    public function disconnect(Request $request, string $provider)
    {
        $account = $this->account($provider);

        if (!$account) {
            return response()->json(['message' => 'Integration not found for this company.'], 404);
        }

        $account->update([
            'api_key' => null,
            'organization_id' => null,
            'connection_status' => LlmProviderAccount::CONNECTION_NOT_CONFIGURED,
            'last_tested_at' => null,
        ]);

        return response()->json([
            'message' => LlmProviderRegistry::config($provider, 'label') . ' disconnected successfully.',
            'account' => LlmProviderRegistry::formatAccount($account),
        ]);
    }

    private function account(string $provider): ?LlmProviderAccount
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return null;
        }

        return LlmProviderAccount::fromCompany($user->company_id)
            ->forProvider($provider)
            ->first();
    }

    /**
     * List models at the vendor and intersect with the curated set we support.
     *
     * Doubles as the connection test: every vendor here authenticates the
     * listing call, so a bad key fails it. Returns the vendor's own error
     * message rather than throwing, so callers can surface it verbatim.
     *
     * @return array{models: string[], error: ?string}
     */
    private function fetchModels(string $provider, LlmProviderAccount $account): array
    {
        $base = rtrim((string) LlmProviderRegistry::config($provider, 'api_base'), '/');
        $timeout = (int) LlmProviderRegistry::config($provider, 'timeout', 30);

        switch ($provider) {
            case 'openai':
                $request = Http::withToken($account->api_key)->timeout($timeout);

                if ($account->organization_id) {
                    $request = $request->withHeaders(['OpenAI-Organization' => $account->organization_id]);
                }

                $response = $request->get("{$base}/models");
                $available = collect($response->json('data') ?? [])->pluck('id')->all();
                break;

            case 'gemini':
                $response = Http::withHeaders(['x-goog-api-key' => $account->api_key])
                    ->timeout($timeout)
                    ->get("{$base}/models");

                // Ids come back namespaced as "models/gemini-2.5-flash".
                $available = collect($response->json('models') ?? [])
                    ->map(fn ($model) => str_replace('models/', '', $model['name'] ?? ''))
                    ->all();
                break;

            case 'claude':
                $response = Http::withHeaders([
                    'x-api-key' => $account->api_key,
                    'anthropic-version' => LlmProviderRegistry::config($provider, 'api_version', '2023-06-01'),
                ])->timeout($timeout)->get("{$base}/models");

                $available = collect($response->json('data') ?? [])->pluck('id')->all();
                break;

            default:
                return ['models' => [], 'error' => "Unsupported provider [{$provider}]."];
        }

        if ($response->failed()) {
            return [
                'models' => [],
                'error' => $response->json('error.message') ?? $response->body(),
            ];
        }

        $supported = array_values(array_filter(
            LlmProviderRegistry::models($provider),
            fn (string $model) => in_array($model, $available, true)
        ));

        /*
         * A key that lists nothing we recognise still authenticated, so this is
         * a connected account — it just cannot reach our curated models (a
         * tier restriction, usually). Fall back to the full list so the form
         * is not empty and the vendor can report the real error at send time.
         */
        return [
            'models' => $supported ?: LlmProviderRegistry::models($provider),
            'error' => null,
        ];
    }
}
