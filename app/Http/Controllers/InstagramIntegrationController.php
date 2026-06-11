<?php

namespace App\Http\Controllers;

use App\Events\InstagramWebhookSubscribed;
use App\Events\InstagramWebhookSubscriptionFailed;
use App\Events\InstagramWebhookVerificationAttempt;
use App\Events\InstagramWebhookVerificationFailed;
use App\Events\InstagramWebhookVerified;
use App\Models\InstagramIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstagramIntegrationController extends Controller
{
    private const GRAPH_API_BASE = 'https://graph.instagram.com/v23.0';

    /**
     * Create the (single, per-company) Instagram integration row.
     */
    public function createIntegration(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $existing = InstagramIntegration::fromCompany($user->company_id)->first();

        if ($existing) {
            return response()->json([
                'message' => 'Instagram integration already exists.',
                'integration' => $this->formatIntegration($existing),
            ], 200);
        }

        try {
            $integration = InstagramIntegration::create([
                'name' => 'instagram_' . Str::random(8),
                'company_id' => $user->company_id,
                'is_active' => true,
            ]);

            return response()->json([
                'message' => 'Instagram integration created successfully.',
                'integration' => $this->formatIntegration($integration),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create Instagram integration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return the current state of the company's Instagram integration.
     */
    public function show(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = InstagramIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        return response()->json($this->formatIntegration($integration), 200);
    }

    /**
     * Save the Instagram access token provided by the user.
     */
    public function configure(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = InstagramIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        try {
            $validated = InstagramIntegration::validateConfig($request->all());

            $integration->update([
                'access_token' => $validated['access_token'],
                'connection_status' => InstagramIntegration::CONNECTION_NOT_CONFIGURED,
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
     * Disconnect the currently connected Instagram account (clears the
     * access token and profile info) so the company can connect a
     * different account. The webhook configuration is kept as-is.
     */
    public function disconnectAccount(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = InstagramIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        $integration->update([
            'access_token' => null,
            'instagram_user_id' => null,
            'username' => null,
            'display_name' => null,
            'profile_picture_url' => null,
            'connection_status' => InstagramIntegration::CONNECTION_NOT_CONFIGURED,
        ]);

        return response()->json([
            'message' => 'Instagram account disconnected successfully.',
            'integration' => $this->formatIntegration($integration),
        ], 200);
    }

    /**
     * Test the saved access token against the Instagram Graph API and store
     * the connected account's profile info.
     */
    public function testConnection(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $integration = InstagramIntegration::fromCompany($user->company_id)->first();

        if (!$integration || !$integration->access_token) {
            return response()->json([
                'message' => 'Instagram access token is not configured.'
            ], 422);
        }

        try {
            $response = Http::get(self::GRAPH_API_BASE . '/me', [
                'fields' => 'user_id,username,name,profile_picture_url,account_type',
                'access_token' => $integration->access_token,
            ]);

            if ($response->failed()) {
                $integration->update([
                    'connection_status' => InstagramIntegration::CONNECTION_ERROR,
                ]);

                return response()->json([
                    'message' => 'Failed to connect to Instagram. Please check your access token.',
                    'error' => $response->json('error.message') ?? $response->body(),
                    'integration' => $this->formatIntegration($integration),
                ], 422);
            }

            $data = $response->json();

            $integration->update([
                'instagram_user_id' => $data['user_id'] ?? null,
                'username' => $data['username'] ?? null,
                'display_name' => $data['name'] ?? null,
                'profile_picture_url' => $data['profile_picture_url'] ?? null,
                'connection_status' => InstagramIntegration::CONNECTION_CONNECTED,
            ]);

            return response()->json([
                'message' => 'Instagram account connected successfully.',
                'integration' => $this->formatIntegration($integration),
            ], 200);
        } catch (\Exception $e) {
            $integration->update([
                'connection_status' => InstagramIntegration::CONNECTION_ERROR,
            ]);

            return response()->json([
                'message' => 'Something went wrong while connecting to Instagram.',
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
        $integration = InstagramIntegration::where('webhook_callback_id', $webhookCallbackId)->first();

        if (!$integration) {
            return response('Not Found', 404);
        }

        // PHP converts dots in query string keys to underscores, so
        // `hub.mode` arrives as `hub_mode`. Fall back to the literal dotted
        // key just in case, but the underscore form is the one that's set.
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        event(new InstagramWebhookVerificationAttempt(
            $integration->company_id,
            $integration->id,
            $request->query(),
            now()->toIso8601String(),
        ));

        if ($mode === 'subscribe' && $token !== null && $token === $integration->webhook_verify_token) {
            $integration->update(['webhook_status' => InstagramIntegration::WEBHOOK_VERIFIED]);

            event(new InstagramWebhookVerified($integration->company_id, $integration->id));

            $this->subscribeToWebhookEvents($integration);

            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        event(new InstagramWebhookVerificationFailed(
            $integration->company_id,
            $integration->id,
            'Invalid hub.mode or hub.verify_token.',
        ));

        return response('Forbidden', 403);
    }

    /**
     * Webhook delivery (POST). Called directly by Meta's servers - must stay
     * outside auth:sanctum. Responds immediately; message processing
     * (InboxChat/InboxMessage) is implemented in a future phase.
     */
    public function receiveWebhook(Request $request, string $webhookCallbackId)
    {
        $integration = InstagramIntegration::where('webhook_callback_id', $webhookCallbackId)->first();

        if ($integration) {
            Log::info('Instagram webhook payload received', [
                'integration_id' => $integration->id,
                'company_id' => $integration->company_id,
                'payload' => $request->all(),
            ]);
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Subscribe the connected account to webhook events.
     */
    private function subscribeToWebhookEvents(InstagramIntegration $integration): void
    {
        try {
            $url = self::GRAPH_API_BASE . "/{$integration->instagram_user_id}/subscribed_apps?" . http_build_query([
                'subscribed_fields' => 'messages',
                'access_token' => $integration->access_token,
            ]);

            $response = Http::post($url);

            if ($response->successful()) {
                event(new InstagramWebhookSubscribed($integration->company_id, $integration->id));
                return;
            }

            event(new InstagramWebhookSubscriptionFailed(
                $integration->company_id,
                $integration->id,
                $response->json('error.message') ?? $response->body(),
            ));
        } catch (\Throwable $e) {
            event(new InstagramWebhookSubscriptionFailed(
                $integration->company_id,
                $integration->id,
                $e->getMessage(),
            ));
        }
    }

    private function formatIntegration(InstagramIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'has_access_token' => !empty($integration->access_token),
            'instagram_user_id' => $integration->instagram_user_id,
            'username' => $integration->username,
            'display_name' => $integration->display_name,
            'profile_picture_url' => $integration->profile_picture_url,
            'connection_status' => $integration->connection_status,
            'webhook_callback_id' => $integration->webhook_callback_id,
            'webhook_verify_token' => $integration->webhook_verify_token,
            'webhook_status' => $integration->webhook_status,
            'webhook_callback_url' => rtrim(config('app.url'), '/') . '/api/integrations/instagram/webhook/' . $integration->webhook_callback_id,
        ];
    }
}
