<?php

namespace App\Http\Controllers;

use App\Models\CompanyFile;
use App\Services\Storage\CompanyStorage;

use App\Events\ConversationRead;
use App\Events\GroupCreated;
use App\Events\GroupDeleted;
use App\Events\GroupMembersAdded;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::where('company_id', $user->company_id)
            ->whereHas('participants', fn($q) => $q->where('user_id', $user->id))
            ->with([
                'participants' => fn($q) => $q->select('users.id', 'users.name', 'users.profile_pic', 'users.email', 'users.role'),
                'lastMessage'  => fn($q) => $q->with('sender:id,name,profile_pic'),
            ])
            ->orderByDesc('last_message_at')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn($c) => $this->formatConversation($c, $user)),
        ]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();

        $contacts = User::where('company_id', $user->company_id)
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->select('id', 'name', 'email', 'role', 'profile_pic')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $contacts->map(fn($c) => $this->formatUser($c)),
        ]);
    }

    public function storePrivate(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = $request->user();

        if ((int) $request->user_id === $user->id) {
            return response()->json(['message' => 'Cannot create a conversation with yourself.'], 422);
        }

        $target = User::where('id', $request->user_id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $existing = Conversation::where('company_id', $user->company_id)
            ->where('type', 'private')
            ->whereHas('participants', fn($q) => $q->where('user_id', $user->id))
            ->whereHas('participants', fn($q) => $q->where('user_id', $target->id))
            ->with([
                'participants' => fn($q) => $q->select('users.id', 'users.name', 'users.profile_pic', 'users.email', 'users.role'),
                'lastMessage'  => fn($q) => $q->with('sender:id,name,profile_pic'),
            ])
            ->first();

        if ($existing) {
            return response()->json(['data' => $this->formatConversation($existing, $user)]);
        }

        $conversation = Conversation::create([
            'company_id' => $user->company_id,
            'type'       => 'private',
            'name'       => null,
            'created_by' => null,
        ]);

        $conversation->participants()->attach([$user->id, $target->id]);

        $conversation->load([
            'participants' => fn($q) => $q->select('users.id', 'users.name', 'users.profile_pic', 'users.email', 'users.role'),
        ]);

        return response()->json(['data' => $this->formatConversation($conversation, $user)], 201);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'users'       => 'required|array|min:1',
            'users.*'     => 'exists:users,id',
            'cover_image' => 'nullable|image|max:10240',
        ]);

        $user = $request->user();

        $userIds = User::whereIn('id', $request->users)
            ->where('company_id', $user->company_id)
            ->where('id', '!=', $user->id)
            ->pluck('id')
            ->toArray();

        $coverImagePath = null;

        if ($request->hasFile('cover_image')) {
            $coverImagePath = $request->file('cover_image')
                ->store('chat/groups/' . $user->company_id, 'local');
            CompanyStorage::record($user->company_id, $coverImagePath, CompanyFile::CATEGORY_CHAT_ATTACHMENT);
        }

        $conversation = Conversation::create([
            'company_id'  => $user->company_id,
            'type'        => 'group',
            'name'        => $request->name,
            'cover_image' => $coverImagePath,
            'created_by'  => $user->id,
        ]);

        // Creator is admin; all others are members
        // Use + instead of array_merge to preserve integer keys (array_merge re-indexes them)
        $participantsData = [$user->id => ['role' => 'admin']]
            + array_fill_keys($userIds, ['role' => 'member']);

        $conversation->participants()->attach($participantsData);

        $conversation->load([
            'participants' => fn($q) => $q->select('users.id', 'users.name', 'users.profile_pic', 'users.email', 'users.role'),
        ]);

        broadcast(new GroupCreated($conversation));

        return response()->json(['data' => $this->formatConversation($conversation, $user)], 201);
    }

    public function destroyGroup(Conversation $conversation): JsonResponse
    {
        $user = request()->user();

        abort_if($conversation->type !== 'group', 422, 'Only groups can be deleted.');
        abort_if((int) $conversation->company_id !== $user->company_id, 403);
        abort_if(!$this->isGroupAdmin($conversation, $user), 403);

        $messagePaths = $conversation->messages()
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->toArray();

        $coverImage = $conversation->cover_image;
        $groupId    = $conversation->id;
        $groupName  = $conversation->name;
        $companyId  = $conversation->company_id;

        $conversation->delete();

        if ($coverImage) {
            Storage::disk('local')->exists($coverImage)
                ? Storage::disk('local')->delete($coverImage)
                : Storage::disk('public')->delete($coverImage);

            // Forgotten on both disks: the file only ever exists on one, and
            // a path that was never recorded is ignored.
            CompanyStorage::forget($coverImage);
            CompanyStorage::forget($coverImage, 'public');
        }

        if (!empty($messagePaths)) {
            $localPaths  = array_filter($messagePaths, fn($p) => Storage::disk('local')->exists($p));
            $publicPaths = array_values(array_diff($messagePaths, $localPaths));
            if (!empty($localPaths))  Storage::disk('local')->delete(array_values($localPaths));
            if (!empty($publicPaths)) Storage::disk('public')->delete($publicPaths);

            CompanyStorage::forget(array_values($localPaths));
            CompanyStorage::forget($publicPaths, 'public');
        }

        broadcast(new GroupDeleted($groupId, $groupName, $companyId));

        return response()->json(null, 204);
    }

    public function addMembers(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'users'   => 'required|array|min:1',
            'users.*' => 'exists:users,id',
        ]);

        $user = $request->user();

        abort_if($conversation->type !== 'group', 422, 'Only groups support adding members.');
        abort_if((int) $conversation->company_id !== $user->company_id, 403);
        abort_if(!$this->isGroupAdmin($conversation, $user), 403);

        $existingIds = $conversation->participants()->pluck('users.id')->toArray();

        $newUserIds = User::whereIn('id', $request->users)
            ->where('company_id', $user->company_id)
            ->whereNotIn('id', $existingIds)
            ->pluck('id')
            ->toArray();

        if (!empty($newUserIds)) {
            $conversation->participants()->attach(
                array_fill_keys($newUserIds, ['role' => 'member'])
            );
        }

        $conversation->load([
            'participants' => fn($q) => $q->select('users.id', 'users.name', 'users.profile_pic', 'users.email', 'users.role'),
        ]);

        if (!empty($newUserIds)) {
            broadcast(new GroupMembersAdded($conversation, $newUserIds));
        }

        return response()->json(['data' => $this->formatConversation($conversation, $user)]);
    }

    public function promoteAdmin(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = $request->user();

        abort_if($conversation->type !== 'group', 422, 'Only groups have admins.');
        abort_if((int) $conversation->company_id !== $user->company_id, 403);
        abort_if(!$this->isGroupAdmin($conversation, $user), 403);

        $isMember = $conversation->participants()
            ->where('users.id', $request->user_id)
            ->exists();

        abort_if(!$isMember, 422, 'User is not a member of this group.');

        $conversation->participants()->updateExistingPivot($request->user_id, ['role' => 'admin']);

        $conversation->load([
            'participants' => fn($q) => $q->select('users.id', 'users.name', 'users.profile_pic', 'users.email', 'users.role'),
        ]);

        return response()->json(['data' => $this->formatConversation($conversation, $user)]);
    }

    private function isGroupAdmin(Conversation $conversation, User $user): bool
    {
        return $conversation->participants()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    /**
     * Mark a conversation read for the caller.
     *
     * Called when the thread is opened and whenever a message lands while it
     * is open. Idempotent and silent when there was nothing unread, so
     * clicking through the sidebar doesn't broadcast on every row.
     */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($conversation->company_id !== $user->company_id
            || !$conversation->participants()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        if (!$conversation->markReadFor($user)) {
            return response()->json(['unread_count' => 0, 'changed' => false]);
        }

        broadcast(new ConversationRead($conversation, $user->id, now()->toISOString()));

        return response()->json(['unread_count' => 0, 'changed' => true]);
    }

    private function formatConversation(Conversation $conversation, User $authUser): array
    {
        $lastMessage = $conversation->lastMessage;

        return [
            'id'              => $conversation->id,
            'type'            => $conversation->type,
            'name'            => $conversation->type === 'private'
                ? $conversation->participants->firstWhere('id', '!=', $authUser->id)?->name
                : $conversation->name,
            'cover_image_url' => $conversation->cover_image,
            'participants'    => $conversation->participants->map(fn($p) => array_merge(
                $this->formatUser($p),
                ['conversation_role' => $p->pivot->role ?? 'member']
            ))->values(),
            'last_message'    => $lastMessage ? [
                'id'         => $lastMessage->id,
                'type'       => $lastMessage->type,
                'body'       => $lastMessage->body,
                'file_url'   => $lastMessage->file_url,
                'sender'     => $lastMessage->sender ? [
                    'id'              => $lastMessage->sender->id,
                    'name'            => $lastMessage->sender->name,
                    'profile_pic_url' => $lastMessage->sender->profile_pic,
                ] : null,
                'created_at' => $lastMessage->created_at?->toISOString(),
            ] : null,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            // Per-participant: everyone in a group has their own count.
            'unread_count'    => $conversation->unreadCountFor($authUser),
            'created_at'      => $conversation->created_at->toISOString(),
        ];
    }

    private function formatUser(User $user): array
    {
        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'role'            => $user->role,
            'profile_pic_url' => $user->profile_pic,
        ];
    }
}
