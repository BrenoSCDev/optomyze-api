<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTaskScope;
use App\Models\TaskList;
use App\Models\TaskSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaskSpaceController extends Controller
{
    use ResolvesTaskScope;

    /**
     * The navigation tree the workspace sidebar renders: spaces, their lists,
     * and an open-task count per list.
     *
     * Counts are computed with withCount so the tree is a fixed number of
     * queries no matter how many lists exist.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $archived = $request->query('archived');

        $spaces = TaskSpace::fromCompany($this->companyId())
            ->archiveFilter($archived)
            ->with(['lists' => function ($q) use ($archived) {
                $q->archiveFilter($archived)
                  ->ordered()
                  ->withCount([
                      'tasks as open_tasks_count' => fn ($t) => $t->root()->notArchived()->open(),
                  ]);
            }])
            ->ordered()
            ->get();

        return response()->json(['data' => $spaces]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->companyId()) {
            return $this->noCompanyResponse();
        }

        $validated = $request->validate(TaskSpace::validationRules());

        $space = TaskSpace::create([
            ...$validated,
            'company_id' => $this->companyId(),
            'created_by' => Auth::id(),
            'order'      => $validated['order']
                ?? (TaskSpace::fromCompany($this->companyId())->max('order') + 1),
        ]);

        return response()->json(['data' => $space->load('lists')], 201);
    }

    public function show($id): JsonResponse
    {
        $space = $this->findSpace($id);

        if (!$space) {
            return response()->json(['message' => 'Space not found'], 404);
        }

        return response()->json(['data' => $space->load('lists')]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $space = $this->findSpace($id);

        if (!$space) {
            return response()->json(['message' => 'Space not found'], 404);
        }

        $validated = $request->validate([
            ...TaskSpace::validationRules(),
            'name' => 'sometimes|required|string|max:255',
        ]);

        $space->update($validated);

        return response()->json(['data' => $space->load('lists')]);
    }

    /**
     * Archive rather than delete. A space holds the history of everything its
     * lists ever tracked, so hiding it is almost always what was meant.
     */
    public function archive(Request $request, $id): JsonResponse
    {
        $space = $this->findSpace($id);

        if (!$space) {
            return response()->json(['message' => 'Space not found'], 404);
        }

        $archived = $request->boolean('archived', true);
        $stamp    = $archived ? now() : null;

        DB::transaction(function () use ($space, $stamp) {
            $space->update(['archived_at' => $stamp]);
            // Lists follow their space; leaving them visible would strand them
            // in a sidebar with no parent.
            TaskList::where('task_space_id', $space->id)->update(['archived_at' => $stamp]);
        });

        return response()->json(['data' => $space->fresh()->load('lists')]);
    }

    /**
     * Permanent delete. Cascades to lists, statuses and their tasks — the
     * client confirms before calling this.
     */
    public function destroy($id): JsonResponse
    {
        $space = $this->findSpace($id);

        if (!$space) {
            return response()->json(['message' => 'Space not found'], 404);
        }

        $space->delete();

        return response()->json(['message' => 'Space deleted']);
    }

    /** Persist a drag-reordered sidebar. */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'space_ids'   => 'required|array',
            'space_ids.*' => 'integer',
        ]);

        $ids = TaskSpace::fromCompany($this->companyId())
            ->whereIn('id', $validated['space_ids'])
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($validated, $ids) {
            foreach ($validated['space_ids'] as $order => $spaceId) {
                if (in_array($spaceId, $ids)) {
                    TaskSpace::where('id', $spaceId)->update(['order' => $order]);
                }
            }
        });

        return response()->json(['message' => 'Reordered']);
    }
}
