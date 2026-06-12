<?php

namespace App\Http\Controllers;

use App\Events\InboxMessageReceived;
use App\Events\LeadLiveUpdate;
use App\Events\InstagramWebhookSubscribed;
use App\Events\InstagramWebhookSubscriptionFailed;
use App\Events\InstagramWebhookVerificationAttempt;
use App\Events\InstagramWebhookVerificationFailed;
use App\Events\InstagramWebhookVerified;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\InstagramIntegration;
use App\Models\Lead;
use App\Models\LeadTransaction;
use App\Services\LeadQueueAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * outside auth:sanctum. Resolves the integration, then processes each
     * messaging event into InboxChat/InboxMessage records, mirroring
     * InboxController::handleEvolutionWebhook for the WhatsApp channel.
     */
    public function receiveWebhook(Request $request, string $webhookCallbackId)
    {
        $integration = InstagramIntegration::where('webhook_callback_id', $webhookCallbackId)->first();

        if (!$integration) {
            return response('EVENT_RECEIVED', 200);
        }

        if (!$integration->funnel) {
            Log::warning('Instagram webhook received but no funnel associated', [
                'integration_id' => $integration->id,
                'company_id' => $integration->company_id,
            ]);

            return response()->json(['message' => 'No funnel associated'], 422);
        }

        $companyId = $integration->company_id;

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['messaging'] ?? [] as $messaging) {
                if (!isset($messaging['message'])) {
                    continue;
                }

                $this->processInstagramMessage($integration, $messaging, $companyId);
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Process a single `messaging` entry from the Instagram webhook payload:
     * resolve/create the chat (and lead, for brand-new IGSIDs), map the
     * message to an InboxMessage, download any media, and broadcast it.
     */
    private function processInstagramMessage(InstagramIntegration $integration, array $messaging, int $companyId): void
    {
        $message = $messaging['message'];
        $isEcho  = $message['is_echo'] ?? false;

        $customerIgsid = $isEcho
            ? ($messaging['recipient']['id'] ?? null)
            : ($messaging['sender']['id'] ?? null);

        if (!$customerIgsid) {
            return;
        }

        $chat = InboxChat::where([
            'company_id'       => $companyId,
            'channel'          => InboxChat::CHANNEL_INSTAGRAM,
            'channelable_type' => InstagramIntegration::class,
            'channelable_id'   => $integration->id,
            'external_chat_id' => $customerIgsid,
        ])->first();

        if (!$chat) {
            $chat = $this->createInstagramChatAndLead($integration, $customerIgsid, $companyId);

            if (!$chat) {
                return;
            }
        }

        $mid = $message['mid'] ?? null;

        if (!$mid) {
            return;
        }

        $messageAttributes = [
            'inbox_chat_id'       => $chat->id,
            'external_message_id' => $mid,
        ];

        // Echo of a message the CRM already sent via sendInstagramTextMessage/
        // sendInstagramMediaMessage — already stored, ignore the delivery echo.
        if ($isEcho && InboxMessage::where($messageAttributes)->exists()) {
            return;
        }

        $messageValues = [
            'direction'    => $isEcho ? 'outgoing' : 'incoming',
            'message_mode' => 'external',
            'user_id'      => null,
            'status'       => $isEcho ? 'sent' : 'delivered',
            'sent_at'      => now(),
        ];

        $mediaUrl = null;

        if (isset($message['text'])) {
            $messageValues['type'] = 'text';
            $messageValues['text'] = $message['text'];
        } elseif (!empty($message['attachments'][0])) {
            $attachment = $message['attachments'][0];

            switch ($attachment['type'] ?? null) {
                case 'audio':
                    $messageValues['type']      = 'audio';
                    $messageValues['media_url'] = null;
                    break;
                case 'image':
                    // Stickers and GIFs are also delivered as 'image' attachments.
                    $messageValues['type']      = 'image';
                    $messageValues['media_url'] = null;
                    break;
                default:
                    Log::info('Instagram webhook: unrecognized attachment type', [
                        'integration_id'  => $integration->id,
                        'attachment_type' => $attachment['type'] ?? null,
                    ]);
                    return;
            }

            $mediaUrl = $attachment['payload']['url'] ?? null;
        } else {
            Log::info('Instagram webhook: unrecognized message shape', [
                'integration_id' => $integration->id,
                'mid'            => $mid,
            ]);
            return;
        }

        $inboxMessage = InboxMessage::firstOrCreate($messageAttributes, $messageValues);

        if ($inboxMessage->wasRecentlyCreated && $mediaUrl) {
            $this->downloadInstagramMedia($inboxMessage, $mediaUrl, $companyId);
        }

        $chat->update(['last_message_at' => now()]);

        broadcast(new InboxMessageReceived($inboxMessage))->toOthers();
    }

    /**
     * A brand-new IGSID becomes a Lead in the integration's associated funnel,
     * with name/avatar populated from the contact's Instagram profile —
     * mirrors the WhatsApp webhook's lead-creation flow.
     */
    private function createInstagramChatAndLead(InstagramIntegration $integration, string $igsid, int $companyId): ?InboxChat
    {
        $funnel = $integration->funnel;

        $entryStage = $funnel->stages()
            ->where('type', 'entry')
            ->orderBy('order')
            ->first();

        if (!$entryStage) {
            Log::warning('Instagram webhook: no entry stage found for funnel', [
                'integration_id' => $integration->id,
                'funnel_id'      => $funnel->id,
            ]);
            return null;
        }

        $profile = $this->fetchInstagramProfile($integration, $igsid);

        $name = trim((string) ($profile['name'] ?? $profile['username'] ?? $igsid));
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] !== '' ? $nameParts[0] : $igsid;
        $lastName  = $nameParts[1] ?? null;

        $lead = Lead::create([
            'company_id'      => $companyId,
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'phone'           => null,
            'source_type'     => 'chat_integration',
            'source_platform' => 'instagram',
            'funnel_id'       => $funnel->id,
            'stage_id'        => $entryStage->id,
        ]);

        LeadQueueAssignmentService::assign($lead);
        LeadTransaction::createLeadCreation($lead, null, LeadTransaction::SOURCE_WEBHOOK);

        Log::info("[LeadLive] Broadcasting created for lead {$lead->id} to funnel.{$lead->funnel_id}");
        broadcast(new LeadLiveUpdate('created', $lead));

        $picPath = !empty($profile['profile_pic'])
            ? $this->downloadInstagramProfilePicture($profile['profile_pic'], $igsid, $companyId)
            : null;

        $chat = InboxChat::create([
            'company_id'          => $companyId,
            'channel'             => InboxChat::CHANNEL_INSTAGRAM,
            'channelable_type'    => InstagramIntegration::class,
            'channelable_id'      => $integration->id,
            'external_chat_id'    => $igsid,
            'external_contact_id' => $igsid,
            'lead_id'             => $lead->id,
            'contact_name'        => $name,
            'profile_pic_url'     => $picPath,
            'is_group'            => false,
            'last_message_at'     => now(),
        ]);

        LeadTransaction::createInstagramChatStarted($lead, $chat, $integration);

        return $chat;
    }

    /**
     * Fetch the contact's name/username/profile picture from the Instagram
     * Graph API. Returns an empty array if the lookup fails for any reason.
     */
    private function fetchInstagramProfile(InstagramIntegration $integration, string $igsid): array
    {
        try {
            $response = Http::timeout(15)->get(self::GRAPH_API_BASE . "/{$igsid}", [
                'fields'       => 'name,username,profile_pic',
                'access_token' => $integration->access_token,
            ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Instagram webhook: failed to fetch contact profile', [
                'integration_id' => $integration->id,
                'igsid'          => $igsid,
                'error'          => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Download a contact's Instagram profile picture and store it on the
     * private `local` disk, mirroring fetchAndStoreProfilePicture for WhatsApp.
     */
    private function downloadInstagramProfilePicture(string $url, string $igsid, int $companyId): ?string
    {
        try {
            $response = Http::timeout(15)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $extension = InboxController::extensionFromMime($response->header('Content-Type') ?? 'image/jpeg');
            $folder    = "instagram/profile_pics/{$companyId}";
            $fileName  = "profile_{$igsid}_" . time() . ".{$extension}";

            Storage::disk('local')->put($folder . '/' . $fileName, $response->body());

            return $folder . '/' . $fileName;
        } catch (\Throwable $e) {
            Log::warning('Instagram webhook: failed to download profile picture', [
                'igsid' => $igsid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Download an inbound image/audio attachment from Meta's signed CDN URL
     * and store it on the private `local` disk, updating the message record.
     */
    private function downloadInstagramMedia(InboxMessage $message, string $url, int $companyId): void
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                return;
            }

            $mime      = $response->header('Content-Type') ?? 'application/octet-stream';
            $extension = InboxController::extensionFromMime($mime);
            $folder    = $message->type === 'audio'
                ? "instagram/audio/{$companyId}"
                : "instagram/images/{$companyId}";
            $path = $folder . '/' . Str::random(20) . ".{$extension}";

            Storage::disk('local')->put($path, $response->body());

            $message->update([
                'media_url'  => $path,
                'media_mime' => $mime,
                'media_size' => strlen($response->body()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Instagram webhook: failed to download media attachment', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Associate the company's Instagram integration with a funnel — new
     * Instagram contacts become leads in this funnel's entry stage.
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

        $integration = InstagramIntegration::fromCompany($user->company_id)->first();

        if (!$integration) {
            return response()->json([
                'message' => 'Integration not found for this company.'
            ], 404);
        }

        $integration->update(['funnel_id' => $data['funnel_id']]);

        return response()->json([
            'message' => 'Funnel linked to Instagram integration successfully.',
            'integration' => $this->formatIntegration($integration),
        ]);
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
        $integration->loadMissing('funnel:id,name');

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
            'funnel_id' => $integration->funnel_id,
            'funnel' => $integration->funnel ? [
                'id' => $integration->funnel->id,
                'name' => $integration->funnel->name,
            ] : null,
        ];
    }
}
