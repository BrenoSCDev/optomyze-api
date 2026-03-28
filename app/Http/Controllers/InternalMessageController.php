<?php

namespace App\Http\Controllers;

use App\Events\InternalMessageSent;
use App\Events\MessagesDeleted;
use App\Events\PrivateMessageNotification;
use App\Models\Conversation;
use App\Models\InternalMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InternalMessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        abort_if(
            (int) $conversation->company_id !== $user->company_id ||
            !$conversation->participants()->where('user_id', $user->id)->exists(),
            403
        );

        $conversation->load('company');

        $messages = $conversation->messages()
            ->with('sender:id,name,profile_pic')
            ->orderBy('created_at')
            ->paginate(50);

        $messages->getCollection()->each(fn($m) => $m->setRelation('conversation', $conversation));

        return response()->json([
            'data' => $messages->getCollection()->map(fn($m) => $this->formatMessage($m)),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page'    => $messages->lastPage(),
                'per_page'     => $messages->perPage(),
                'total'        => $messages->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'type'            => 'required|in:text,image,audio',
            'body'            => 'required_if:type,text|nullable|string|max:5000',
            'file'            => 'required_if:type,image,audio|nullable|file|max:20480',
        ]);

        $conversation = Conversation::where('id', $validated['conversation_id'])
            ->where('company_id', $user->company_id)
            ->whereHas('participants', fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $filePath = null;

        if ($request->hasFile('file') && in_array($validated['type'], ['image', 'audio'])) {
            $folder   = $validated['type'] === 'image' ? 'chat/images' : 'chat/audio';
            $filePath = $request->file('file')->store("{$folder}/{$user->company_id}", 'public');
        }

        $message = InternalMessage::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $user->id,
            'type'            => $validated['type'],
            'body'            => $validated['body'] ?? null,
            'file_path'       => $filePath,
        ]);

        $conversation->update(['last_message_at' => now()]);

        $conversation->load('company');
        $message->setRelation('conversation', $conversation);
        $message->load('sender:id,name,profile_pic');

        broadcast(new InternalMessageSent($message));

        if ($conversation->type === 'private') {
            $conversation->load('participants:id');
            $recipient = $conversation->participants->firstWhere('id', '!=', $user->id);

            if ($recipient) {
                broadcast(new PrivateMessageNotification($message, $recipient->id));
            }
        }

        return response()->json(['data' => $this->formatMessage($message)], 201);
    }

    public function deleteMultiple(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'message_ids'   => 'required|array|min:1',
            'message_ids.*' => 'integer|exists:internal_messages,id',
        ]);

        $messages = InternalMessage::whereIn('id', $validated['message_ids'])
            ->where('user_id', $user->id)
            ->with('conversation:id,company_id')
            ->get();

        foreach ($messages->groupBy('conversation_id') as $conversationId => $group) {
            $conversation = $group->first()->conversation;

            abort_if(
                (int) $conversation->company_id !== $user->company_id ||
                !Conversation::where('id', $conversationId)
                    ->whereHas('participants', fn($q) => $q->where('user_id', $user->id))
                    ->exists(),
                403
            );
        }

        $deletedIds = $messages->pluck('id')->toArray();

        InternalMessage::whereIn('id', $deletedIds)->delete();

        foreach ($messages->groupBy('conversation_id') as $conversationId => $group) {
            broadcast(new MessagesDeleted($conversationId, $group->pluck('id')->toArray()));
        }

        return response()->json(['data' => ['deleted_ids' => $deletedIds]]);
    }

    private function formatMessage(InternalMessage $message): array
    {
        $sender = $message->sender;

        return [
            'id'              => $message->id,
            'conversation_id' => $message->conversation_id,
            'type'            => $message->type,
            'body'            => $message->body,
            'file_url'        => $message->file_path ? Storage::url($message->file_path) : null,
            'sender'          => [
                'id'              => $sender->id,
                'name'            => $sender->name,
                'profile_pic_url' => $sender->profile_pic
                    ? Storage::url($sender->profile_pic)
                    : null,
            ],
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }
}
