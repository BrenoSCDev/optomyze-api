<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskSpace;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\Auth;

/**
 * Every task-module lookup goes through here.
 *
 * Route-model binding (`public function show(Task $task)`) resolves by primary
 * key alone, which is how the previous TaskController let any authenticated
 * user read, edit or delete another company's tasks. These helpers take a raw
 * id and refuse to return anything outside the caller's company, so a handler
 * physically cannot reach across tenants.
 */
trait ResolvesTaskScope
{
    protected function companyId(): ?int
    {
        return Auth::user()?->company_id;
    }

    /** 403 payload for a caller with no company. */
    protected function noCompanyResponse()
    {
        return response()->json(['message' => 'User does not belong to a company'], 403);
    }

    protected function findSpace($id): ?TaskSpace
    {
        return TaskSpace::where('company_id', $this->companyId())->find($id);
    }

    protected function findList($id): ?TaskList
    {
        return TaskList::where('company_id', $this->companyId())->find($id);
    }

    protected function findStatus($id): ?TaskStatus
    {
        return TaskStatus::where('company_id', $this->companyId())->find($id);
    }

    /**
     * A task the caller may act on. Visibility (assignee/creator/manager) is
     * applied on top of company scope, so an agent cannot edit a colleague's
     * task by guessing its id.
     */
    protected function findTask($id, bool $respectVisibility = true): ?Task
    {
        $query = Task::where('tasks.company_id', $this->companyId());

        if ($respectVisibility && ($user = Auth::user())) {
            $query->visibleToUser($user);
        }

        return $query->find($id);
    }

    /**
     * Confirm a status belongs to the given list — otherwise a task could be
     * put into a column from an unrelated board.
     */
    protected function statusBelongsToList($statusId, $listId): bool
    {
        return TaskStatus::where('id', $statusId)
            ->where('task_list_id', $listId)
            ->where('company_id', $this->companyId())
            ->exists();
    }

    /**
     * Restrict a set of user ids to real members of the caller's company,
     * preserving the order they were sent in.
     *
     * The order is load-bearing: Task::syncAssignees() promotes the first id to
     * `assigned_to`, the primary assignee every legacy consumer reads. A plain
     * pluck() returns database order, which would quietly reassign the task to
     * whoever happens to have the lowest user id.
     */
    protected function scopedUserIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $allowed = \App\Models\User::whereIn('id', $ids)
            ->where('company_id', $this->companyId())
            ->pluck('id')
            ->all();

        $ordered = array_values(array_unique(array_map('intval', $ids)));

        return array_values(array_filter($ordered, fn ($id) => in_array($id, $allowed, false)));
    }

    /** Restrict a set of tag ids to the caller's company. */
    protected function scopedTagIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return \App\Models\Tag::whereIn('id', $ids)
            ->where('company_id', $this->companyId())
            ->pluck('id')
            ->all();
    }
}
