<?php

namespace App\Services\Ai;

use App\Events\InboxMessageUpdated;
use App\Models\AiAgent;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\Stage;
use App\Models\Tag;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\AiChatMessage;
use App\Services\Ai\Dto\AiChatRequest;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Assembles one LLM turn: agent system prompt + runtime context, bounded
 * conversation history, and the pending (debounced) customer messages
 * merged into a single multimodal user turn.
 */
class AiAgentTurnService
{
    private const HISTORY_LIMIT = 30;
    private const MAX_PENDING_IMAGES = 4;
    private const SIGNED_URL_MINUTES = 30;

    public function buildRequest(
        AiAgent $agent,
        InboxChat $chat,
        Collection $pending,
        ToolContext $context,
        AiProviderInterface $provider,
    ): AiChatRequest {
        $messages = $this->historyMessages($chat, $pending);
        $messages[] = AiChatMessage::user($this->pendingContent($pending, $provider));

        $tools = ToolRegistry::forAgent($agent, $context);

        return new AiChatRequest(
            $agent->model,
            $this->systemPrompt($agent, $chat, $context),
            $messages,
            ToolRegistry::definitions($tools, $context),
            $agent->temperature,
            $agent->max_tokens,
        );
    }

    /**
     * Agent-authored prompt + generated runtime context block. The runtime
     * block gives the model the CRM facts it must not invent.
     */
    private function systemPrompt(AiAgent $agent, InboxChat $chat, ToolContext $context): string
    {
        $lead = $context->lead;
        $company = $chat->company;

        $lines = [];
        $lines[] = '## Runtime Context';
        $lines[] = 'Company: ' . ($company?->name ?? 'Unknown');
        $lines[] = 'Current date/time: ' . now()->timezone($chat->getCompanyTimezone())->format('Y-m-d H:i (T)');
        $lines[] = 'Channel: ' . $chat->channel;

        if ($lead) {
            $leadName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
            $lines[] = 'Customer: ' . ($leadName !== '' ? $leadName : ($chat->contact_name ?? 'Unknown'));

            if ($lead->stage) {
                $lines[] = 'Current funnel stage: ' . $lead->stage->name;
            }

            if ($lead->assignedUser) {
                $lines[] = 'Assigned salesperson: ' . $lead->assignedUser->name;
            }

            if ($lead->funnel_id) {
                $stages = Stage::where('funnel_id', $lead->funnel_id)
                    ->orderBy('order')
                    ->pluck('name')
                    ->implode(' → ');

                if ($stages !== '') {
                    $lines[] = 'Funnel stages: ' . $stages;
                }
            }
        }

        $tagNames = Tag::fromCompany($chat->company_id)->pluck('name')->implode(', ');

        if ($tagNames !== '') {
            $lines[] = 'Available tags: ' . $tagNames;
        }

        $lines[] = '';
        $lines[] = '## Hard Rules';
        $lines[] = '- Reply in plain conversational text only: no markdown, no headers, no bullet lists.';
        $lines[] = '- Keep replies concise and natural for a messaging conversation.';
        $lines[] = '- Never invent prices, products or policies. Use the search_products tool for product questions when available.';
        $lines[] = '- Call the handover_to_human tool whenever the customer asks for a person, wants to buy or negotiate, becomes frustrated, or your instructions say to transfer. Do not announce the transfer yourself — the system sends the handover message.';
        $lines[] = '- If a tool returns an error or you cannot fulfill the request because of an internal/technical problem, call handover_to_human IMMEDIATELY. Never ask the customer for permission to transfer, never mention technical difficulties, and never promise to check later — the transfer is a system decision, not the customer\'s.';
        $lines[] = '- Reply in the language the customer is writing in.';

        $agentPrompt = trim((string) $agent->system_prompt);

        return ($agentPrompt !== '' ? $agentPrompt . "\n\n" : '') . implode("\n", $lines);
    }

    /**
     * The last HISTORY_LIMIT external messages before the pending window,
     * chronological. Everything the customer saw from us (human, welcome,
     * AI) becomes an assistant turn so the model stays consistent.
     *
     * @return AiChatMessage[]
     */
    private function historyMessages(InboxChat $chat, Collection $pending): array
    {
        $firstPendingId = $pending->min('id');

        $history = InboxMessage::where('inbox_chat_id', $chat->id)
            ->where('message_mode', 'external')
            ->when($firstPendingId, fn ($q) => $q->where('id', '<', $firstPendingId))
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse();

        $messages = [];

        foreach ($history as $message) {
            $text = $this->messageAsText($message);

            if ($text === '') {
                continue;
            }

            $messages[] = $message->direction === 'incoming'
                ? AiChatMessage::user($text)
                : AiChatMessage::assistant($text);
        }

        return $messages;
    }

    /**
     * Render a history message as plain text (media become placeholders,
     * transcribed audio is inlined).
     */
    private function messageAsText(InboxMessage $message): string
    {
        return match ($message->type) {
            'text' => trim((string) $message->text),
            'audio' => $message->transcription
                ? '[voice message] ' . $message->transcription
                : '[voice message]',
            'image' => trim((string) $message->text) !== ''
                ? '[image] ' . trim((string) $message->text)
                : '[image received]',
            'video' => '[video received]',
            'document' => '[document received]',
            'sticker' => '[sticker received]',
            'location' => '[location shared]',
            'contact' => '[contact card shared]',
            default => trim((string) $message->text),
        };
    }

    /**
     * Merge the pending messages into one multimodal user turn: text parts,
     * signed-URL image parts (vision), transcribed audio as text.
     */
    private function pendingContent(Collection $pending, AiProviderInterface $provider): array
    {
        $parts = [];
        $imageCount = 0;
        $visionAvailable = $this->publicUrlAvailable();

        // Only the newest images get vision slots.
        $visionEligibleIds = $pending
            ->filter(fn (InboxMessage $m) => $m->type === 'image' && $m->media_url)
            ->sortByDesc('id')
            ->take(self::MAX_PENDING_IMAGES)
            ->pluck('id')
            ->all();

        foreach ($pending as $message) {
            switch ($message->type) {
                case 'text':
                    $text = trim((string) $message->text);
                    if ($text !== '') {
                        $parts[] = ['type' => 'text', 'text' => $text];
                    }
                    break;

                case 'image':
                    if ($message->media_url && $visionAvailable && in_array($message->id, $visionEligibleIds, true)) {
                        $parts[] = [
                            'type' => 'image_url',
                            'image_url' => ['url' => $this->signedMediaUrl($message)],
                        ];
                        $imageCount++;
                    } else {
                        $parts[] = ['type' => 'text', 'text' => '[image received]'];
                    }

                    $caption = trim((string) $message->text);
                    if ($caption !== '') {
                        $parts[] = ['type' => 'text', 'text' => $caption];
                    }
                    break;

                case 'audio':
                    $transcription = $this->transcription($message, $provider);
                    $parts[] = [
                        'type' => 'text',
                        'text' => $transcription
                            ? '[voice message] ' . $transcription
                            // Phrased as an internal failure so the hard rule
                            // routes the model to handover_to_human instead of
                            // apologizing that it "couldn't hear" the customer.
                            : '[internal problem: the customer sent a voice message but transcription failed — you cannot know its content]',
                    ];
                    break;

                default:
                    $parts[] = ['type' => 'text', 'text' => $this->messageAsText($message) ?: '[message received]'];
            }
        }

        if (empty($parts)) {
            $parts[] = ['type' => 'text', 'text' => '[message received]'];
        }

        return $parts;
    }

    /**
     * Cached transcription, or transcribe now and persist it (mirrors
     * InboxController::transcribeMessage so the inbox UI benefits too).
     */
    private function transcription(InboxMessage $message, AiProviderInterface $provider): ?string
    {
        if ($message->transcription) {
            return $message->transcription;
        }

        if (!$message->media_url || !Storage::disk('local')->exists($message->media_url)) {
            Log::warning('AiAgentTurnService: audio media missing for transcription', [
                'message_id' => $message->id,
                'media_url' => $message->media_url,
            ]);

            return null;
        }

        $text = $provider->transcribe(
            Storage::disk('local')->path($message->media_url),
            $message->media_mime ?? 'audio/ogg'
        );

        if ($text) {
            $message->update(['transcription' => $text]);
            broadcast(new InboxMessageUpdated($message))->toOthers();
        }

        return $text;
    }

    /**
     * Short-lived public URL for a stored media file, using the same
     * signed-route pattern as the Instagram outbound media flow.
     */
    private function signedMediaUrl(InboxMessage $message): string
    {
        $signedPath = URL::temporarySignedRoute(
            'files.signed',
            now()->addMinutes(self::SIGNED_URL_MINUTES),
            ['path' => $message->media_url, 'mime' => $message->media_mime],
            false
        );

        return URL::to($signedPath);
    }

    /**
     * OpenAI can only fetch image URLs when APP_URL is publicly reachable —
     * a localhost APP_URL degrades vision to text placeholders.
     */
    private function publicUrlAvailable(): bool
    {
        $appUrl = (string) config('app.url');

        $isLocal = $appUrl === ''
            || str_contains($appUrl, 'localhost')
            || str_contains($appUrl, '127.0.0.1');

        if ($isLocal) {
            Log::warning('AiAgentTurnService: APP_URL is not public — image inputs degraded to placeholders.');
        }

        return !$isLocal;
    }
}
