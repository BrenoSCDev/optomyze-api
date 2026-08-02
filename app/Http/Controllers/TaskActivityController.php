<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTaskScope;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The conversation and checklist inside a task detail view.
 */
class TaskActivityController extends Controller
{
    use ResolvesTaskScope;

    /*
    |--------------------------------------------------------------------------
    | Comments
    |--------------------------------------------------------------------------
    */

    public function comments($taskId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        return response()->json([
            'data' => $task->comments()->with('user:id,name,email,profile_pic')->get(),
        ]);
    }

    public function storeComment(Request $request, $taskId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $validated = $request->validate(['body' => 'required|string|max:10000']);

        $comment = DB::transaction(function () use ($task, $validated) {
            $mentions = TaskComment::parseMentions($validated['body'], $this->companyId());

            $comment = $task->comments()->create([
                'user_id'  => Auth::id(),
                'body'     => $validated['body'],
                'mentions' => $mentions ?: null,
            ]);

            // Commenting is an act of interest — mentioned people and the
            // author start watching, so they see what happens next.
            $task->watchers()->syncWithoutDetaching(array_unique([...$mentions, Auth::id()]));

            return $comment;
        });

        return response()->json(['data' => $comment->load('user:id,name,email,profile_pic')], 201);
    }

    public function updateComment(Request $request, $taskId, $commentId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $comment = $task->comments()->find($commentId);

        if (!$comment) {
            return response()->json(['message' => 'Comment not found'], 404);
        }

        // Only the author may rewrite their own words.
        if ($comment->user_id !== Auth::id()) {
            return response()->json(['message' => 'You can only edit your own comments'], 403);
        }

        $validated = $request->validate(['body' => 'required|string|max:10000']);

        $comment->update([
            'body'      => $validated['body'],
            'mentions'  => TaskComment::parseMentions($validated['body'], $this->companyId()) ?: null,
            'edited_at' => now(),
        ]);

        return response()->json(['data' => $comment->load('user:id,name,email,profile_pic')]);
    }

    public function destroyComment($taskId, $commentId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $comment = $task->comments()->find($commentId);

        if (!$comment) {
            return response()->json(['message' => 'Comment not found'], 404);
        }

        // Authors delete their own; managers can clear anything on the task.
        if ($comment->user_id !== Auth::id() && !Auth::user()->isManager()) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }

    /*
    |--------------------------------------------------------------------------
    | Checklist
    |--------------------------------------------------------------------------
    */

    public function storeChecklistItem(Request $request, $taskId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $validated = $request->validate(['title' => 'required|string|max:500']);

        $item = $task->checklistItems()->create([
            'title' => $validated['title'],
            'order' => ($task->checklistItems()->max('order') ?? -1) + 1,
        ]);

        return response()->json(['data' => $item], 201);
    }

    public function updateChecklistItem(Request $request, $taskId, $itemId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $item = $task->checklistItems()->find($itemId);

        if (!$item) {
            return response()->json(['message' => 'Checklist item not found'], 404);
        }

        $validated = $request->validate([
            'title'   => 'sometimes|required|string|max:500',
            'is_done' => 'sometimes|boolean',
        ]);

        if (array_key_exists('title', $validated)) {
            $item->update(['title' => $validated['title']]);
        }

        // Routed through setDone so is_done, completed_at and completed_by
        // cannot drift apart.
        if (array_key_exists('is_done', $validated)) {
            $item->setDone($validated['is_done'], Auth::id());
        }

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroyChecklistItem($taskId, $itemId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $item = $task->checklistItems()->find($itemId);

        if (!$item) {
            return response()->json(['message' => 'Checklist item not found'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Checklist item deleted']);
    }

    public function reorderChecklist(Request $request, $taskId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $validated = $request->validate([
            'item_ids'   => 'required|array',
            'item_ids.*' => 'integer',
        ]);

        $ids = $task->checklistItems()->whereIn('id', $validated['item_ids'])->pluck('id')->all();

        DB::transaction(function () use ($validated, $ids) {
            foreach ($validated['item_ids'] as $order => $id) {
                if (in_array($id, $ids)) {
                    TaskChecklistItem::where('id', $id)->update(['order' => $order]);
                }
            }
        });

        return response()->json(['message' => 'Reordered']);
    }

    /*
    |--------------------------------------------------------------------------
    | Watchers
    |--------------------------------------------------------------------------
    */

    /** Toggle the current user's own watch state. */
    public function toggleWatch($taskId): JsonResponse
    {
        $task = $this->findTask($taskId);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $result  = $task->watchers()->toggle([Auth::id()]);
        $watching = !empty($result['attached']);

        return response()->json(['watching' => $watching]);
    }
}
