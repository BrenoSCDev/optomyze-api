<?php

namespace App\Http\Controllers;

use App\Models\Widget;
use App\Models\WidgetChatVisitor;
use App\Services\Widgets\ChatWidgetType;
use App\Services\Widgets\WebsiteChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Unauthenticated endpoints for the website chat widget.
 *
 * Every request is bounded by three things: the widget must be active, the
 * browser Origin must be one of the widget's allowed domains, and the visitor
 * token must belong to that widget. Nothing here can reach another company's
 * data even with a valid token from a different widget.
 */
class PublicWidgetChatController extends Controller
{
    public function __construct(private readonly WebsiteChatService $chat)
    {
    }

    /**
     * Open (or resume) a visitor session. Returns the browser token, the
     * identification state and any existing history, so a refresh continues
     * the same conversation.
     */
    public function session(Request $request, string $publicId)
    {
        [$widget, $error] = $this->resolveWidget($request, $publicId);

        if ($error) {
            return $error;
        }

        $token = $this->token($request);
        $visitor = $this->chat->resolveVisitor($widget, $token, $request);

        if (!$visitor) {
            // Unknown/foreign token: start clean rather than leaking whether
            // the token exists elsewhere.
            $visitor = $this->chat->resolveVisitor($widget, null, $request);
        }

        return response()->json([
            'data' => [
                'visitor_token' => $visitor->token,
                'visitor_id' => $visitor->visitor_id,
                'identified' => (bool) $visitor->identified,
                'has_conversation' => (bool) $visitor->inbox_chat_id,
                'contact' => [
                    'name' => $visitor->name,
                    'email' => $visitor->email,
                    'phone' => $visitor->phone,
                ],
                'messages' => $this->chat->visitorMessages($visitor),
            ],
        ]);
    }

    /**
     * Pre-chat identification. Validation is derived from the widget's own
     * configuration, so a tampered client can't skip a required field.
     */
    public function identify(Request $request, string $publicId)
    {
        [$widget, $error] = $this->resolveWidget($request, $publicId);

        if ($error) {
            return $error;
        }

        $visitor = $this->requireVisitor($request, $widget);

        if (!$visitor) {
            return response()->json(['message' => 'Session expired. Please reload the page.'], 401);
        }

        $fields = Arr::get($widget->config, 'identification.fields', []);
        $mode = Arr::get($widget->config, 'identification.mode', 'none');
        $offered = ChatWidgetType::MODE_FIELDS[$mode] ?? [];

        $rules = [];

        foreach ($offered as $field) {
            $state = $fields[$field] ?? 'hidden';

            if ($state === 'hidden') {
                continue;
            }

            $rules[$field] = [$state === 'required' ? 'required' : 'nullable', 'string', 'max:255'];

            if ($field === 'email') {
                $rules[$field][] = 'email';
            }
        }

        $data = $request->validate($rules);

        $visitor = $this->chat->identify($visitor, [
            'name' => isset($data['name']) ? strip_tags(trim($data['name'])) : null,
            'email' => $data['email'] ?? null,
            'phone' => isset($data['phone']) ? strip_tags(trim($data['phone'])) : null,
        ]);

        return response()->json([
            'data' => [
                'identified' => (bool) $visitor->identified,
                'contact' => [
                    'name' => $visitor->name,
                    'email' => $visitor->email,
                    'phone' => $visitor->phone,
                ],
            ],
        ]);
    }

    /** Visitor sends a message. Creates the lead + conversation on first contact. */
    public function send(Request $request, string $publicId)
    {
        [$widget, $error] = $this->resolveWidget($request, $publicId);

        if ($error) {
            return $error;
        }

        $visitor = $this->requireVisitor($request, $widget);

        if (!$visitor) {
            return response()->json(['message' => 'Session expired. Please reload the page.'], 401);
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
        ]);

        // Identification can be enforced before the first message.
        $mode = Arr::get($widget->config, 'identification.mode', 'none');

        if ($mode !== 'none' && !$visitor->identified && $this->hasRequiredIdentityFields($widget)) {
            return response()->json(['message' => 'identification_required'], 422);
        }

        $text = trim(strip_tags($data['text']));

        if ($text === '') {
            return response()->json(['message' => 'Message cannot be empty.'], 422);
        }

        try {
            $message = $this->chat->receiveVisitorMessage($widget, $visitor, $text);
        } catch (\Throwable $e) {
            Log::error('Website chat: failed to store visitor message', [
                'widget_id' => $widget->id,
                'visitor_id' => $visitor->visitor_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'We could not deliver your message. Please try again.'], 500);
        }

        return response()->json([
            'data' => [
                'id' => $message->id,
                'direction' => $message->direction,
                'text' => $message->text,
                'sent_at' => optional($message->sent_at)->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Poll for new messages. `after_id` keeps the payload to just what the
     * widget hasn't seen; the transport can move to websockets later without
     * changing this contract.
     */
    public function messages(Request $request, string $publicId)
    {
        [$widget, $error] = $this->resolveWidget($request, $publicId);

        if ($error) {
            return $error;
        }

        $visitor = $this->requireVisitor($request, $widget);

        if (!$visitor) {
            return response()->json(['message' => 'Session expired. Please reload the page.'], 401);
        }

        $afterId = $request->integer('after_id') ?: null;

        return response()->json([
            'data' => [
                'messages' => $this->chat->visitorMessages($visitor, $afterId),
                // Polling only asks for messages after the last one it saw, so
                // a read receipt on an older message would never reach the
                // widget. One high-water mark covers every earlier message
                // without re-sending any of them.
                'seen_until_id' => $this->chat->seenUntilId($visitor),
            ],
        ]);
    }

    /**
     * @return array{0: ?Widget, 1: ?\Illuminate\Http\JsonResponse}
     */
    private function resolveWidget(Request $request, string $publicId): array
    {
        // These endpoints are API-only: without this, a client that omits
        // Accept: application/json would get a 302 HTML redirect on validation
        // failure instead of a 422 payload it can render.
        $request->headers->set('Accept', 'application/json');

        $widget = Widget::where('public_id', $publicId)->where('type', 'chat')->active()->first();

        if (!$widget) {
            return [null, response()->json(['message' => 'Widget not found.'], 404)];
        }

        $origin = $request->header('Origin') ?: $request->header('Referer') ?: null;

        if ($origin === null || !$widget->allowsOrigin($origin)) {
            return [null, response()->json(['message' => 'This widget is not enabled for this domain.'], 403)];
        }

        return [$widget, null];
    }

    private function requireVisitor(Request $request, Widget $widget): ?WidgetChatVisitor
    {
        $token = $this->token($request);

        if (!$token) {
            return null;
        }

        return $this->chat->resolveVisitor($widget, $token, $request);
    }

    /** The visitor token travels in a header, never in the URL/query log. */
    private function token(Request $request): ?string
    {
        return $request->header('X-Visitor-Token') ?: $request->input('visitor_token');
    }

    private function hasRequiredIdentityFields(Widget $widget): bool
    {
        $mode = Arr::get($widget->config, 'identification.mode', 'none');
        $fields = Arr::get($widget->config, 'identification.fields', []);

        foreach (ChatWidgetType::MODE_FIELDS[$mode] ?? [] as $field) {
            if (($fields[$field] ?? 'hidden') === 'required') {
                return true;
            }
        }

        return false;
    }
}
