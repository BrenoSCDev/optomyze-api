<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTaskScope;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaskListController extends Controller
{
    use ResolvesTaskScope;

    public function store(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $validated = $request->validate(TaskList::validationRules());

        $space = $this->findSpace($validated['task_space_id']);
        if (!$space) {
            return response()->json(['message' => 'Space not found'], 404);
        }

        $list = DB::transaction(function () use ($validated, $space) {
            $list = TaskList::create([
                ...$validated,
                'company_id' => $this->companyId(),
                'created_by' => Auth::id(),
                'color'      => $validated['color'] ?? $space->color,
                'order'      => $validated['order']
                    ?? (TaskList::where('task_space_id', $space->id)->max('order') + 1),
            ]);

            // A list with no statuses is an unusable board.
            $list->seedDefaultStatuses();

            return $list;
        });

        return response()->json(['data' => $list->load('statuses')], 201);
    }

    public function show($id): JsonResponse
    {
        $list = $this->findList($id);

        if (!$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        return response()->json(['data' => $list->load(['statuses', 'space'])]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $list = $this->findList($id);

        if (!$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        $validated = $request->validate([
            ...TaskList::validationRules(),
            'task_space_id' => 'sometimes|integer',
            'name'          => 'sometimes|required|string|max:255',
        ]);

        // Moving a list between spaces must stay inside the company.
        if (isset($validated['task_space_id']) && !$this->findSpace($validated['task_space_id'])) {
            return response()->json(['message' => 'Space not found'], 404);
        }

        $list->update($validated);

        return response()->json(['data' => $list->load('statuses')]);
    }

    public function archive(Request $request, $id): JsonResponse
    {
        $list = $this->findList($id);

        if (!$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        $list->update(['archived_at' => $request->boolean('archived', true) ? now() : null]);

        return response()->json(['data' => $list->fresh()]);
    }

    public function destroy($id): JsonResponse
    {
        $list = $this->findList($id);

        if (!$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        $list->delete();

        return response()->json(['message' => 'List deleted']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'list_ids'   => 'required|array',
            'list_ids.*' => 'integer',
        ]);

        $ids = TaskList::fromCompany($this->companyId())
            ->whereIn('id', $validated['list_ids'])
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($validated, $ids) {
            foreach ($validated['list_ids'] as $order => $listId) {
                if (in_array($listId, $ids)) {
                    TaskList::where('id', $listId)->update(['order' => $order]);
                }
            }
        });

        return response()->json(['message' => 'Reordered']);
    }

    /*
    |--------------------------------------------------------------------------
    | Statuses (board columns)
    |--------------------------------------------------------------------------
    */

    public function storeStatus(Request $request, $listId): JsonResponse
    {
        $list = $this->findList($listId);

        if (!$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        $validated = $request->validate(TaskStatus::validationRules());

        $status = DB::transaction(function () use ($validated, $list) {
            $status = $list->statuses()->create([
                ...$validated,
                'company_id' => $this->companyId(),
                'order'      => $validated['order'] ?? ($list->statuses()->max('order') + 1),
            ]);

            if (!empty($validated['is_default'])) {
                $this->makeSoleDefault($list, $status);
            }

            return $status;
        });

        return response()->json(['data' => $status], 201);
    }

    public function updateStatus(Request $request, $listId, $statusId): JsonResponse
    {
        $list   = $this->findList($listId);
        $status = $this->findStatus($statusId);

        if (!$list || !$status || $status->task_list_id !== $list->id) {
            return response()->json(['message' => 'Status not found'], 404);
        }

        $validated = $request->validate([
            ...TaskStatus::validationRules(),
            'name' => 'sometimes|required|string|max:60',
            'type' => 'sometimes|required|in:not_started,active,done,closed',
        ]);

        DB::transaction(function () use ($status, $validated, $list) {
            $status->update($validated);

            if (!empty($validated['is_default'])) {
                $this->makeSoleDefault($list, $status);
            }

            // Renaming is free, but changing the *type* changes what "done"
            // means for every task sitting in this column, so the derived
            // legacy status has to be recomputed for them.
            if (array_key_exists('type', $validated)) {
                $this->resyncTasksInStatus($status);
            }
        });

        return response()->json(['data' => $status->fresh()]);
    }

    /**
     * Delete a column, moving anything in it to `reassign_to` so no task is
     * left stranded without a status.
     */
    public function destroyStatus(Request $request, $listId, $statusId): JsonResponse
    {
        $list   = $this->findList($listId);
        $status = $this->findStatus($statusId);

        if (!$list || !$status || $status->task_list_id !== $list->id) {
            return response()->json(['message' => 'Status not found'], 404);
        }

        if ($list->statuses()->count() <= 1) {
            return response()->json(['message' => 'A list must keep at least one status'], 422);
        }

        $fallbackId = $request->input('reassign_to');
        $fallback   = $fallbackId ? $this->findStatus($fallbackId) : null;

        if (!$fallback || $fallback->task_list_id !== $list->id || $fallback->id === $status->id) {
            $fallback = $list->statuses()->where('id', '!=', $status->id)->orderBy('order')->first();
        }

        DB::transaction(function () use ($status, $fallback) {
            // Re-saved one by one so the model hook rewrites `status` and
            // `completed_at` for the new column's semantics.
            Task::where('task_status_id', $status->id)->get()->each(
                fn (Task $task) => $task->update(['task_status_id' => $fallback->id])
            );

            $status->delete();
        });

        return response()->json(['message' => 'Status deleted', 'reassigned_to' => $fallback->id]);
    }

    public function reorderStatuses(Request $request, $listId): JsonResponse
    {
        $list = $this->findList($listId);

        if (!$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        $validated = $request->validate([
            'status_ids'   => 'required|array',
            'status_ids.*' => 'integer',
        ]);

        $ids = $list->statuses()->whereIn('id', $validated['status_ids'])->pluck('id')->all();

        DB::transaction(function () use ($validated, $ids) {
            foreach ($validated['status_ids'] as $order => $id) {
                if (in_array($id, $ids)) {
                    TaskStatus::where('id', $id)->update(['order' => $order]);
                }
            }
        });

        return response()->json(['message' => 'Reordered']);
    }

    private function makeSoleDefault(TaskList $list, TaskStatus $status): void
    {
        $list->statuses()->where('id', '!=', $status->id)->update(['is_default' => false]);
        $status->update(['is_default' => true]);
    }

    /** Recompute the derived legacy status for every task in a column. */
    private function resyncTasksInStatus(TaskStatus $status): void
    {
        Task::where('task_status_id', $status->id)->get()->each(function (Task $task) use ($status) {
            $task->status = $status->legacyStatus();

            if ($status->type === 'done') {
                $task->completed_at ??= now();
            } elseif ($status->type !== 'closed') {
                $task->completed_at = null;
            }

            $task->save();
        });
    }
}
