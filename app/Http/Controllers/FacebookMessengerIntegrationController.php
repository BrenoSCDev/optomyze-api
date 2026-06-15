<?php

namespace App\Http\Controllers;

use App\Events\FacebookWebhookSubscribed;
use App\Events\FacebookWebhookSubscriptionFailed;
use App\Events\FacebookWebhookVerificationAttempt;
use App\Events\FacebookWebhookVerificationFailed;
use App\Events\FacebookWebhookVerified;
use App\Models\FacebookMessengerIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FacebookMessengerIntegrationController extends Controller
{
    private const GRAPH_API_BASE = 'https://graph.facebook.com/v23.0';

    /**
     * Create the (single, per-company) Facebook Messenger integration row.
     */
    public function createIntegration(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $existing = FacebookMessengerIntegration::fromCompany($user->company_id)->first();

        if ($existing) {
            return response()->json([
                'message' => 'Facebook integration already exists.',
                'integration' => $this->formatIntegration($existing),
            ], 200);
        }

        try {
            $integration = FacebookMessengerIntegration::create([
                'name' => 'facebook_' . Str::random(8),
                'company_id' => $user->company_id,
                'is_active' => true,
            ]);

            return response()->json([
                'message' => 'Facebook integration created successfully.',
                'integration' => $this->formatIntegration($integration),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create Facebook integration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return the current state of the company's Facebook integration.
     */
    public function show(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = FacebookMessengerIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        return response()->json($this->formatIntegration($integration), 200);
    }

    /**
     * Save the Facebook Page access token provided by the user.
     */
    public function configure(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = FacebookMessengerIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            $validated = FacebookMessengerIntegration::validateConfig($request->all());

            $integration->update([
                'access_token' => $validated['access_token'],
                'connection_status' => FacebookMessengerIntegration::CONNECTION_NOT_CONFIGURED,
            ]);

            return response()->json([
                'message' => 'Integration configured successfully.',
                'integration' => $this->formatIntegration($integration),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Disconnect the currently connected Facebook Page (clears the access
     * token and page info) so the company can connect a different page.
     * The webhook configuration is kept as-is.
     */
    public function disconnectAccount(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = FacebookMessengerIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        $integration->update([
            'access_token' => null,
            'page_id' => null,
            'page_name' => null,
            'profile_picture_url' => null,
            'connection_status' => FacebookMessengerIntegration::CONNECTION_NOT_CONFIGURED,
        ]);

        return response()->json([
            'message' => 'Facebook page disconnected successfully.',
            'integration' => $this->formatIntegration($integration),
        ], 200);
    }

    /**
     * Test the saved access token against the Facebook Graph API and store
     * the connected page's profile info.
     */
    public function testConnection(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = FacebookMessengerIntegration::fromCompany($user->company_id)->first();

        if (!$integration || !$integration->access_token) {
            return response()->json([
                'message' => 'Facebook access token is not configured.'
            ], 422);
        }

        try {
            $response = Http::get(self::GRAPH_API_BASE . '/me', [
                'fields' => 'id,name,picture{url}',
                'access_token' => $integration->access_token,
            ]);

            if ($response->failed()) {
                $integration->update([
                    'connection_status' => FacebookMessengerIntegration::CONNECTION_ERROR,
                ]);

                return response()->json([
                    'message' => 'Failed to connect to Facebook. Please check your access token.',
                    'error' => $response->json('error.message') ?? $response->body(),
                    'integration' => $this->formatIntegration($integration),
                ], 422);
            }

            $data = $response->json();

            $integration->update([
                'page_id' => $data['id'] ?? null,
                'page_name' => $data['name'] ?? null,
                'profile_picture_url' => $data['picture']['data']['url'] ?? null,
                'connection_status' => FacebookMessengerIntegration::CONNECTION_CONNECTED,
            ]);

            return response()->json([
                'message' => 'Facebook page connected successfully.',
                'integration' => $this->formatIntegration($integration),
            ], 200);
        } catch (\Exception $e) {
            $integration->update([
                'connection_status' => FacebookMessengerIntegration::CONNECTION_ERROR,
            ]);

            return response()->json([
                'message' => 'Something went wrong while connecting to Facebook.',
                'error' => $e->getMessage(),
                'integration' => $this->formatIntegration($integration),
            ], 500);
        }
    }

    /**
     * Webhook verification handshake (GET). Called directly by Meta's
     * servers - no CRM session/JWT involved, must stay outside auth:sanctum.
     */
    public function verifyWebhook(Request $request, string $webhookCallbackId)
    {
        $integration = FacebookMessengerIntegration::where('webhook_callback_id', $webhookCallbackId)->first();

        if (!$integration) {
            return response('Not Found', 404);
        }

        // PHP converts dots in query string keys to underscores, so
        // `hub.mode` arrives as `hub_mode`. Fall back to the literal dotted
        // key just in case, but the underscore form is the one that's set.
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        event(new FacebookWebhookVerificationAttempt(
            $integration->company_id,
            $integration->id,
            $request->query(),
            now()->toIso8601String(),
        ));

        if ($mode === 'subscribe' && $token !== null && $token === $integration->webhook_verify_token) {
            $integration->update(['webhook_status' => FacebookMessengerIntegration::WEBHOOK_VERIFIED]);

            event(new FacebookWebhookVerified($integration->company_id, $integration->id));

            $this->subscribeToWebhookEvents($integration);

            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        event(new FacebookWebhookVerificationFailed(
            $integration->company_id,
            $integration->id,
            'Invalid hub.mode or hub.verify_token.',
        ));

        return response('Forbidden', 403);
    }

    /**
     * Webhook delivery (POST). Called directly by Meta's servers - must stay
     * outside auth:sanctum. Message processing is implemented in a later phase.
     */
    public function receiveWebhook(Request $request, string $webhookCallbackId)
    {
        $integration = FacebookMessengerIntegration::where('webhook_callback_id', $webhookCallbackId)->first();

        if (!$integration) {
            return response('EVENT_RECEIVED', 200);
        }

        Log::info('Facebook webhook received', [
            'integration_id' => $integration->id,
            'company_id' => $integration->company_id,
        ]);

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Associate the company's Facebook integration with a funnel — new
     * Messenger contacts will become leads in this funnel's entry stage.
     */
    public function updateFunnel(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $data = $request->validate([
            'funnel_id' => ['nullable', 'exists:funnels,id'],
        ]);

        $integration = FacebookMessengerIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        $integration->update(['funnel_id' => $data['funnel_id']]);

        return response()->json([
            'message' => 'Funnel linked to Facebook integration successfully.',
            'integration' => $this->formatIntegration($integration),
        ]);
    }

    /**
     * Subscribe the connected page to webhook events.
     */
    private function subscribeToWebhookEvents(FacebookMessengerIntegration $integration): void
    {
        try {
            $url = self::GRAPH_API_BASE . "/{$integration->page_id}/subscribed_apps?" . http_build_query([
                'subscribed_fields' => 'messages,messaging_postbacks,message_deliveries,message_reads',
                'access_token' => $integration->access_token,
            ]);

            $response = Http::post($url);

            if ($response->successful()) {
                event(new FacebookWebhookSubscribed($integration->company_id, $integration->id));
                return;
            }

            event(new FacebookWebhookSubscriptionFailed(
                $integration->company_id,
                $integration->id,
                $response->json('error.message') ?? $response->body(),
            ));
        } catch (\Throwable $e) {
            event(new FacebookWebhookSubscriptionFailed(
                $integration->company_id,
                $integration->id,
                $e->getMessage(),
            ));
        }
    }

    private function formatIntegration(FacebookMessengerIntegration $integration): array
    {
        $integration->loadMissing('funnel:id,name');

        return [
            'id' => $integration->id,
            'has_access_token' => !empty($integration->access_token),
            'page_id' => $integration->page_id,
            'page_name' => $integration->page_name,
            'profile_picture_url' => $integration->profile_picture_url,
            'connection_status' => $integration->connection_status,
            'webhook_callback_id' => $integration->webhook_callback_id,
            'webhook_verify_token' => $integration->webhook_verify_token,
            'webhook_status' => $integration->webhook_status,
            'webhook_callback_url' => rtrim(config('app.url'), '/') . '/api/integrations/facebook/webhook/' . $integration->webhook_callback_id,
            'funnel_id' => $integration->funnel_id,
            'funnel' => $integration->funnel ? [
                'id' => $integration->funnel->id,
                'name' => $integration->funnel->name,
            ] : null,
        ];
    }
}
