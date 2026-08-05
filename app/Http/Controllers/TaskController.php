<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTaskScope;
use App\Models\Calendar;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\Record;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tasks in the workspace.
 *
 * Every handler resolves its subject through ResolvesTaskScope rather than
 * route-model binding. The previous version bound `Task $task` directly, which
 * meant show/update/destroy would happily operate on another company's task if
 * you knew its id.
 */
class TaskController extends Controller
{
    use ResolvesTaskScope;

    /** Relations the client needs to render a task row without an N+1. */
    private const ROW_RELATIONS = [
        'status:id,name,color,type,order',
        'list:id,name,color,task_space_id',
        'assignees:id,name,email,profile_pic',
        'tags:id,name,color',
        'lead:id,first_name,last_name,source_platform',
        'sale:id,title',
    ];

    private const PAGE_SIZES = [10, 15, 20, 25, 50, 100];

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Flat, filtered, paginated task feed — backs the List view and every
     * saved filter.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $query = $this->baseQuery($request);

        // Subtasks are rendered nested under their parent, so they are kept
        // out of the flat feed unless explicitly asked for.
        if (!$request->boolean('include_subtasks')) {
            $query->root();
        }

        $this->applySort($query, $request);

        $perPage = (int) $request->query('per_page', 25);
        if (!in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = 25;
        }

        $tasks = $query
            ->with(self::ROW_RELATIONS)
            ->withCount([
                'subtasks',
                'subtasks as completed_subtasks_count' => fn ($q) => $q->where('status', 'completed'),
                'comments',
                'checklistItems',
                'checklistItems as completed_checklist_count' => fn ($q) => $q->where('is_done', true),
            ])
            ->paginate($perPage);

        return response()->json([
            'data' => $tasks->items(),
            'meta' => [
                'total'       => $tasks->total(),
                'per_page'    => $tasks->perPage(),
                'current_page'=> $tasks->currentPage(),
                'last_page'   => $tasks->lastPage(),
                'page_sizes'  => self::PAGE_SIZES,
            ],
        ]);
    }

    /**
     * Board view: the list's columns plus the tasks in each.
     *
     * Columns are returned even when empty — an empty column is still a valid
     * drop target, so the client must know it exists.
     */
    public function board(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $list = $this->findList($request->query('list_id'));
        if (!$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        $tasks = $this->baseQuery($request)
            ->where('tasks.task_list_id', $list->id)
            ->root()
            ->with(self::ROW_RELATIONS)
            ->withCount([
                'subtasks',
                'subtasks as completed_subtasks_count' => fn ($q) => $q->where('status', 'completed'),
                'comments',
                'checklistItems',
                'checklistItems as completed_checklist_count' => fn ($q) => $q->where('is_done', true),
            ])
            ->orderBy('tasks.position')
            ->get()
            ->groupBy('task_status_id');

        $columns = $list->statuses->map(fn (TaskStatus $status) => [
            'status' => $status,
            'tasks'  => $tasks->get((string) $status->id, collect())->values(),
        ]);

        return response()->json([
            'data' => [
                'list'    => $list->only(['id', 'name', 'color', 'task_space_id', 'default_view']),
                'columns' => $columns,
            ],
        ]);
    }

    /**
     * Tasks with a due date inside a window — feeds both the module's own
     * calendar view and the main CRM Calendar, which shows tasks alongside
     * appointments.
     */
    public function calendar(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $validated = $request->validate([
            'start'       => 'required|date',
            'end'         => 'required|date|after_or_equal:start',
            'calendar_id' => 'sometimes|nullable|integer',
        ]);

        $tasks = $this->baseQuery($request)
            // Scoped to one calendar when asked. The id is checked against this
            // company first, so a crafted value returns nothing rather than
            // another tenant's tasks.
            ->when($request->filled('calendar_id'), function ($query) {
                $ownIds = Calendar::fromCompany($this->companyId())
                    ->whereIn('id', array_filter((array) request()->query('calendar_id')))
                    ->pluck('id');

                $query->whereIn('tasks.calendar_id', $ownIds);
            })
            ->whereNotNull('tasks.due_date')
            ->whereBetween('tasks.due_date', [$validated['start'], $validated['end']])
            ->with(self::ROW_RELATIONS)
            ->orderBy('tasks.due_date')
            ->limit(500)
            ->get();

        return response()->json(['data' => $tasks]);
    }

    public function show($id): JsonResponse
    {
        $task = $this->findTask($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        return response()->json([
            'data' => $task->load([
                ...self::ROW_RELATIONS,
                'watchers:id,name,email,profile_pic',
                'creator:id,name,email,profile_pic',
                'parent:id,title',
                'subtasks' => fn ($q) => $q->with([
                    'status:id,name,color,type',
                    'assignees:id,name,email,profile_pic',
                ]),
                'checklistItems',
                'comments.user:id,name,email,profile_pic',
            ]),
        ]);
    }

    /**
     * Counts for the workspace header: what is on this user's plate.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $mine = fn () => Task::fromCompany($this->companyId())
            ->visibleToUser(Auth::user())
            ->notArchived()
            ->root();

        return response()->json([
            'data' => [
                'open'      => (clone $mine())->open()->count(),
                'overdue'   => (clone $mine())->open()->overdue()->count(),
                'due_today' => (clone $mine())->open()->whereDate('due_date', today())->count(),
                'unscheduled' => (clone $mine())->open()->whereNull('due_date')->count(),
                'completed_this_week' => (clone $mine())
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', now()->startOfWeek())
                    ->count(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $validated = $request->validate(Task::validationRules());

        $list = isset($validated['task_list_id']) ? $this->findList($validated['task_list_id']) : null;
        if (isset($validated['task_list_id']) && !$list) {
            return response()->json(['message' => 'List not found'], 404);
        }

        // Sales linkage must stay inside the company.
        if (!empty($validated['lead_id']) && !$this->leadInCompany($validated['lead_id'])) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        if (!empty($validated['sale_id']) && !$this->saleInCompany($validated['sale_id'])) {
            return response()->json(['message' => 'Deal not found'], 404);
        }

        $statusId = $this->resolveStatusId($validated, $list);

        $parent = null;
        if (!empty($validated['parent_task_id'])) {
            $parent = $this->findTask($validated['parent_task_id']);
            if (!$parent) {
                return response()->json(['message' => 'Parent task not found'], 404);
            }
            // One level of nesting: a subtask of a subtask is re-parented to
            // the top-level task rather than silently building a deep tree.
            if ($parent->parent_task_id) {
                $validated['parent_task_id'] = $parent->parent_task_id;
            }
        }

        $task = DB::transaction(function () use ($validated, $list, $statusId, $request) {
            $assigneeIds = $this->scopedUserIds($validated['assignee_ids'] ?? []);

            $task = Task::create([
                ...collect($validated)->except(['assignee_ids', 'watcher_ids', 'tag_ids'])->all(),
                'company_id'     => $this->companyId(),
                // Tasks show up on the calendar grid, so every one is filed on
                // a calendar — the requested one when it is this company's,
                // the company default otherwise.
                'calendar_id'    => $this->resolveCalendarId($validated['calendar_id'] ?? null),
                'task_list_id'   => $list?->id,
                'task_status_id' => $statusId,
                'position'       => $this->nextPosition($list?->id, $statusId),
                'assigned_to'    => $assigneeIds[0] ?? null,
                'created_by'     => Auth::id(),
                'reminder'       => $request->boolean('reminder', true),
            ]);

            if ($assigneeIds) {
                $task->assignees()->sync($assigneeIds);
            }
            if ($watchers = $this->scopedUserIds($validated['watcher_ids'] ?? [])) {
                $task->watchers()->sync($watchers);
            }
            if ($tags = $this->scopedTagIds($validated['tag_ids'] ?? [])) {
                $task->tags()->sync($tags);
            }

            return $task;
        });

        // Keep the lead's activity timeline honest.
        $transaction = null;
        if ($task->lead_id && ($lead = Lead::find($task->lead_id))) {
            $transaction = Record::createTaskCreated($task, $lead, Auth::user());
            $transaction?->load('user');
        }

        return response()->json([
            'message'     => 'Task created',
            'data'        => $task->load(self::ROW_RELATIONS),
            'task'        => $task,          // legacy key, still read by TaskCreationModal
            'transaction' => $transaction,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $task = $this->findTask($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $validated = $request->validate([
            ...Task::validationRules(),
            'title' => 'sometimes|required|string|max:255',
        ]);

        if (array_key_exists('task_list_id', $validated) && $validated['task_list_id']) {
            if (!$this->findList($validated['task_list_id'])) {
                return response()->json(['message' => 'List not found'], 404);
            }
        }

        if (!empty($validated['lead_id']) && !$this->leadInCompany($validated['lead_id'])) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        if (!empty($validated['sale_id']) && !$this->saleInCompany($validated['sale_id'])) {
            return response()->json(['message' => 'Deal not found'], 404);
        }

        // A status must belong to the list the task is (or is moving) into.
        if (!empty($validated['task_status_id'])) {
            $targetList = $validated['task_list_id'] ?? $task->task_list_id;
            if (!$this->statusBelongsToList($validated['task_status_id'], $targetList)) {
                return response()->json(['message' => 'Status does not belong to that list'], 422);
            }
        }

        $wasCompleted = $task->status === 'completed';

        DB::transaction(function () use ($task, $validated) {
            $task->update(collect($validated)->except(['assignee_ids', 'watcher_ids', 'tag_ids'])->all());

            if (array_key_exists('assignee_ids', $validated)) {
                $task->syncAssignees($this->scopedUserIds($validated['assignee_ids'] ?? []));
            }
            if (array_key_exists('watcher_ids', $validated)) {
                $task->watchers()->sync($this->scopedUserIds($validated['watcher_ids'] ?? []));
            }
            if (array_key_exists('tag_ids', $validated)) {
                $task->tags()->sync($this->scopedTagIds($validated['tag_ids'] ?? []));
            }
        });

        $task->refresh();
        $justCompleted = $task->status === 'completed' && !$wasCompleted;

        if ($task->lead_id && ($lead = Lead::find($task->lead_id))) {
            $justCompleted
                ? Record::createTaskCompleted($task, $lead, Auth::user())
                : Record::createTaskUpdated($task, $lead, Auth::user());
        }

        return response()->json([
            'message' => 'Task updated',
            'data'    => $task->load(self::ROW_RELATIONS),
            'task'    => $task,   // legacy key
        ]);
    }

    /**
     * Drag-and-drop: move a task to a list/column and slot it between two
     * neighbours. Position is the midpoint of the gap, so only this row is
     * written even when a column holds thousands of tasks.
     */
    public function move(Request $request, $id): JsonResponse
    {
        $task = $this->findTask($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $validated = $request->validate([
            'task_list_id'   => 'nullable|integer',
            'task_status_id' => 'nullable|integer',
            'before_id'      => 'nullable|integer',
            'after_id'       => 'nullable|integer',
        ]);

        $listId = $validated['task_list_id'] ?? $task->task_list_id;

        if ($listId && !$this->findList($listId)) {
            return response()->json(['message' => 'List not found'], 404);
        }

        $statusId = $validated['task_status_id'] ?? $task->task_status_id;

        // Moving to another list invalidates the old column.
        if ($listId !== $task->task_list_id && $statusId === $task->task_status_id) {
            $statusId = $this->findList($listId)?->defaultStatus()?->id;
        }

        if ($statusId && !$this->statusBelongsToList($statusId, $listId)) {
            return response()->json(['message' => 'Status does not belong to that list'], 422);
        }

        // Neighbours are optional: dropping into an empty column, or moving a
        // task to another list wholesale, sends neither.
        $beforeId = $validated['before_id'] ?? null;
        $afterId  = $validated['after_id']  ?? null;

        $before = $beforeId ? $this->findTask($beforeId)?->position : null;
        $after  = $afterId  ? $this->findTask($afterId)?->position  : null;

        // With no neighbours given, append rather than collapsing onto 1000 —
        // otherwise every such move would stack tasks at the same position.
        if ($before === null && $after === null) {
            $position = $this->nextPosition($listId, $statusId);
        } else {
            $position = Task::positionBetween($before, $after);
        }

        $task->update([
            'task_list_id'   => $listId,
            'task_status_id' => $statusId,
            'position'       => $position,
        ]);

        return response()->json(['data' => $task->load(self::ROW_RELATIONS)]);
    }

    public function archive(Request $request, $id): JsonResponse
    {
        $task = $this->findTask($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $task->update(['archived_at' => $request->boolean('archived', true) ? now() : null]);

        return response()->json(['data' => $task->fresh()->load(self::ROW_RELATIONS)]);
    }

    public function destroy($id): JsonResponse
    {
        $task = $this->findTask($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $task->delete();

        return response()->json(['message' => 'Task deleted']);
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk actions
    |--------------------------------------------------------------------------
    */

    private const MAX_BULK = 500;

    public function bulk(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $validated = $request->validate([
            'task_ids'   => 'required|array|min:1|max:' . self::MAX_BULK,
            'task_ids.*' => 'integer',
            'action'     => 'required|in:archive,unarchive,delete,assign,tag,status,priority,move',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'integer',
            'tag_ids'    => 'nullable|array',
            'tag_ids.*'  => 'integer',
            'mode'           => 'sometimes|in:attach,replace',
            'task_status_id' => 'nullable|integer',
            'task_list_id'   => 'nullable|integer',
            'priority'       => 'nullable|in:low,medium,high,urgent',
        ]);

        $tasks = Task::fromCompany($this->companyId())
            ->visibleToUser(Auth::user())
            ->whereIn('tasks.id', $validated['task_ids'])
            ->get();

        if ($tasks->isEmpty()) {
            return response()->json(['message' => 'No matching tasks'], 404);
        }

        $count = DB::transaction(fn () => $this->runBulk($tasks, $validated));

        return response()->json(['updated' => $count]);
    }

    private function runBulk($tasks, array $v): int
    {
        switch ($v['action']) {
            case 'archive':
            case 'unarchive':
                return Task::whereIn('id', $tasks->pluck('id'))
                    ->update(['archived_at' => $v['action'] === 'archive' ? now() : null]);

            case 'delete':
                return Task::whereIn('id', $tasks->pluck('id'))->delete();

            case 'priority':
                return Task::whereIn('id', $tasks->pluck('id'))->update(['priority' => $v['priority']]);

            case 'assign':
                $userIds = $this->scopedUserIds($v['user_ids'] ?? []);
                $tasks->each(fn (Task $t) => $t->syncAssignees($userIds));
                return $tasks->count();

            case 'tag':
                $tagIds = $this->scopedTagIds($v['tag_ids'] ?? []);
                $mode   = $v['mode'] ?? 'attach';
                $tasks->each(fn (Task $t) => $mode === 'replace'
                    ? $t->tags()->sync($tagIds)
                    : $t->tags()->syncWithoutDetaching($tagIds));
                return $tasks->count();

            case 'status':
                // Re-saved individually so the model hook keeps the derived
                // legacy status and completed_at correct.
                $applied = 0;
                foreach ($tasks as $task) {
                    if ($this->statusBelongsToList($v['task_status_id'], $task->task_list_id)) {
                        $task->update(['task_status_id' => $v['task_status_id']]);
                        $applied++;
                    }
                }
                return $applied;

            case 'move':
                $list = $this->findList($v['task_list_id'] ?? null);
                if (!$list) {
                    return 0;
                }
                $defaultStatusId = $list->defaultStatus()?->id;
                foreach ($tasks as $task) {
                    $task->update([
                        'task_list_id'   => $list->id,
                        'task_status_id' => $defaultStatusId,
                    ]);
                }
                return $tasks->count();
        }

        return 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /** Company + visibility + every supported filter. */
    private function baseQuery(Request $request): Builder
    {
        $query = Task::fromCompany($this->companyId())
            ->visibleToUser(Auth::user())
            ->archiveFilter($request->query('archived'));

        if ($listId = $request->query('list_id')) {
            $query->where('tasks.task_list_id', $listId);
        }

        // A space filter spans all of its lists.
        if ($spaceId = $request->query('space_id')) {
            $query->whereIn(
                'tasks.task_list_id',
                TaskList::where('company_id', $this->companyId())
                    ->where('task_space_id', $spaceId)
                    ->select('id')
            );
        }

        if ($request->filled('status_id')) {
            $query->whereIn('tasks.task_status_id', (array) $request->query('status_id'));
        }

        // Semantic filter — "show me everything still open" regardless of what
        // each list calls its columns.
        if ($request->filled('status_type')) {
            $types = (array) $request->query('status_type');
            $query->whereHas('status', fn ($q) => $q->whereIn('type', $types));
        }

        if ($request->filled('priority')) {
            $query->whereIn('tasks.priority', (array) $request->query('priority'));
        }

        if ($request->filled('assignee_id')) {
            $ids = (array) $request->query('assignee_id');
            $query->whereHas('assignees', fn ($q) => $q->whereIn('users.id', $ids));
        }

        if ($request->boolean('mine')) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', Auth::id()));
        }

        if ($request->filled('tag_id')) {
            $ids = (array) $request->query('tag_id');
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $ids));
        }

        if ($request->filled('lead_id')) {
            $query->where('tasks.lead_id', $request->query('lead_id'));
        }

        if ($request->filled('sale_id')) {
            $query->where('tasks.sale_id', $request->query('sale_id'));
        }

        if ($request->filled('due_from')) {
            $query->whereDate('tasks.due_date', '>=', $request->query('due_from'));
        }
        if ($request->filled('due_to')) {
            $query->whereDate('tasks.due_date', '<=', $request->query('due_to'));
        }

        if ($request->boolean('overdue')) {
            $query->open()->overdue();
        }

        if ($request->filled('q')) {
            $term = $request->query('q');
            $query->where(function ($q) use ($term) {
                $q->where('tasks.title', 'like', "%{$term}%")
                  ->orWhere('tasks.description', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    private function applySort(Builder $query, Request $request): void
    {
        $dir = $request->query('sort_dir') === 'desc' ? 'desc' : 'asc';

        match ($request->query('sort_by')) {
            'title'    => $query->orderBy('tasks.title', $dir),
            'created'  => $query->orderBy('tasks.created_at', $dir),
            'updated'  => $query->orderBy('tasks.updated_at', $dir),
            // MySQL sorts NULL first ascending; undated tasks belong at the end.
            'due_date' => $query->orderByRaw("tasks.due_date IS NULL, tasks.due_date {$dir}"),
            // Urgent-first needs an explicit ranking, not the enum's storage
            // order. A CASE expression rather than MySQL's FIELD() so the same
            // query runs on SQLite under test.
            'priority' => $query->orderByRaw(
                "CASE tasks.priority
                    WHEN 'urgent' THEN 0 WHEN 'high' THEN 1
                    WHEN 'medium' THEN 2 ELSE 3 END {$dir}"
            ),
            default    => $query->orderBy('tasks.position')->orderBy('tasks.id'),
        };
    }

    /** Append to the end of the target column. */
    private function nextPosition(?int $listId, ?int $statusId): float
    {
        if (!$listId) {
            return 1000;
        }

        $max = Task::where('task_list_id', $listId)
            ->when($statusId, fn ($q) => $q->where('task_status_id', $statusId))
            ->max('position');

        return ($max ?? 0) + 1000;
    }

    /**
     * Pick the column a new task lands in: the one asked for if it is valid,
     * otherwise the list's default.
     */
    private function resolveStatusId(array $validated, ?TaskList $list): ?int
    {
        if (!$list) {
            return null;
        }

        $requested = $validated['task_status_id'] ?? null;

        if ($requested && $this->statusBelongsToList($requested, $list->id)) {
            return (int) $requested;
        }

        return $list->defaultStatus()?->id;
    }

    private function leadInCompany($leadId): bool
    {
        return Lead::where('id', $leadId)->where('company_id', $this->companyId())->exists();
    }

    private function saleInCompany($saleId): bool
    {
        return Sale::where('id', $saleId)->where('company_id', $this->companyId())->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Legacy endpoints — kept so existing consumers keep working
    |--------------------------------------------------------------------------
    */

    /**
     * Flat list of the company's tasks. Still used by the reports dashboard
     * and the lead-details panel, which expect an unpaginated `data` array.
     */
    public function companyTasks(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $tasks = Task::fromCompany($this->companyId())
            ->archiveFilter($request->query('archived'))
            ->with(['lead', 'assignee', 'creator', 'status:id,name,color,type', 'list:id,name'])
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => $tasks]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:1']);

        $tasks = Task::fromCompany($this->companyId())
            ->visibleToUser(Auth::user())
            ->notArchived()
            ->where('title', 'like', '%' . $request->input('q') . '%')
            ->select('id', 'title', 'status', 'priority', 'due_date', 'lead_id', 'task_list_id')
            ->orderBy('title')
            ->limit(20)
            ->get();

        return response()->json($tasks);
    }

    ////////////////// V1 API METHODS //////////////////

    public function apiIndex(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = Task::where('tasks.company_id', $company->id)
            ->with(['lead:id,first_name,last_name', 'assignee:id,name,email', 'creator:id,name,email']);

        if ($request->has('lead_id'))  $query->where('lead_id', $request->lead_id);
        if ($request->has('status'))   $query->where('status', $request->status);
        if ($request->has('priority')) $query->where('priority', $request->priority);

        return response()->json($query->orderBy('due_date')->paginate($request->input('per_page', 20)));
    }

    public function apiStore(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'lead_id'     => 'required|exists:leads,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'status'      => 'nullable|in:pending,in_progress,completed,cancelled',
            'due_date'    => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $lead = Lead::where('company_id', $company->id)->find($validated['lead_id']);
        if (!$lead) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        // Assignee must be in the same company.
        if (!empty($validated['assigned_to'])) {
            $inCompany = \App\Models\User::where('id', $validated['assigned_to'])
                ->where('company_id', $company->id)
                ->exists();

            if (!$inCompany) {
                return response()->json(['message' => 'User not found in this company'], 422);
            }
        }

        // File it into the company's default list so it appears in the
        // workspace rather than floating unattached.
        $list = TaskList::where('company_id', $company->id)->notArchived()->ordered()->first();

        $task = Task::create([
            ...$validated,
            'company_id'     => $company->id,
            'task_list_id'   => $list?->id,
            'task_status_id' => $list?->defaultStatus()?->id,
            'position'       => $this->nextPositionFor($list?->id),
            'reminder'       => false,
        ]);

        if (!empty($validated['assigned_to'])) {
            $task->assignees()->sync([$validated['assigned_to']]);
        }

        return response()->json(['message' => 'Task created successfully', 'data' => $task], 201);
    }

    private function nextPositionFor(?int $listId): float
    {
        if (!$listId) {
            return 1000;
        }

        return (Task::where('task_list_id', $listId)->max('position') ?? 0) + 1000;
    }

    /**
     * The calendar a task is filed on.
     *
     * Same contract as AppointmentController: honour the requested calendar
     * when it belongs to this company, otherwise fall back to the default, so
     * no creation path has to know calendars exist.
     */
    private function resolveCalendarId(?int $requested): ?int
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return null;
        }

        return Calendar::resolveForCompany($requested, $companyId);
    }
}
