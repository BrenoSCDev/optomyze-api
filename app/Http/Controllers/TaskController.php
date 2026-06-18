<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Record;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


class TaskController extends Controller
{
    /**
     * company tasks
     */
    public function companyTasks()
    {
        $companyId = Auth::user()->company_id;

        $tasks = Task::query()
            ->where(function ($query) use ($companyId) {
                $query->whereHas('lead', fn ($q) => $q->where('company_id', $companyId))
                      ->orWhere(function ($q) use ($companyId) {
                          $q->whereNull('lead_id')
                            ->whereHas('creator', fn ($sub) => $sub->where('company_id', $companyId));
                      });
            })
            ->with([
                'lead',
                'assignee',
                'creator',
            ])
            ->orderBy('due_date')
            ->get();

        return response()->json([
            'data' => $tasks
        ]);
    }

    /**
     * Criar tarefa
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'nullable|exists:leads,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'reminder' => 'required',
            'status' => 'nullable|in:pending,in_progress,completed,cancelled',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        return DB::transaction(function () use ($validated) {

            $task = Task::create([
                ...$validated,
                'lead_id'    => $validated['lead_id'] ?? null,
                'created_by' => Auth::id(),
            ]);

            if (($validated['status'] ?? null) === 'completed') {
                $task->markAsCompleted();
            }

            $task->load(['creator', 'assignee', 'lead']);

            $transaction = null;

            if (!empty($validated['lead_id'])) {
                $lead = Lead::find($validated['lead_id']);

                if ($lead) {
                    $transaction = Record::createTaskCreated($task, $lead, Auth::user());
                    $transaction->load('user');
                }
            }

            return response()->json([
                'message' => 'Tarefa criada com sucesso',
                'task' => $task,
                'transaction' => $transaction
            ], 201);
        });
    }

    /**
     * Exibir tarefa
     */
    public function show(Task $task)
    {
        return response()->json(
            $task->load(['lead', 'assignee', 'creator'])
        );
    }

    /**
     * Atualizar tarefa
     */
        public function update(Request $request, Task $task)
        {
            $validated = $request->validate([
                'lead_id'     => 'sometimes|nullable|exists:leads,id',
                'title'       => 'sometimes|required|string|max:255',
                'description' => 'sometimes|string',
                'priority'    => 'sometimes|in:low,medium,high,urgent',
                'reminder'    => 'sometimes',
                'status'      => 'sometimes',
                'due_date'    => 'sometimes|date',
                'assigned_to' => 'sometimes|exists:users,id',
            ]);

            $wasCompleted = $task->status === 'completed';

            $task->update($validated);

            $justCompleted = ($validated['status'] ?? null) === 'completed' && !$wasCompleted;

            if ($justCompleted && !$task->completed_at) {
                $task->markAsCompleted();
            }

            $task->load(['creator', 'assignee', 'lead']);

            if ($task->lead) {
                if ($justCompleted) {
                    Record::createTaskCompleted($task, $task->lead, Auth::user());
                } else {
                    Record::createTaskUpdated($task, $task->lead, Auth::user());
                }
            }

            return response()->json([
                'message' => 'Tarefa atualizada com sucesso',
                'task' => $task
            ]);
        }

    /**
     * Remover tarefa
     */
    public function destroy(Task $task)
    {
        $task->delete();

        return response()->json([
            'message' => 'Tarefa removida com sucesso'
        ]);
    }

    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|min:1']);

        $companyId = Auth::user()->company_id;
        $query     = $request->input('q');

        $tasks = Task::whereHas('lead', fn ($q) => $q->where('company_id', $companyId))
            ->where('title', 'like', "%{$query}%")
            ->select('id', 'title', 'status', 'priority', 'due_date', 'lead_id')
            ->orderBy('title')
            ->limit(20)
            ->get();

        return response()->json($tasks);
    }

    ////////////////// V1 API METHODS //////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $query = Task::whereHas('lead', fn($q) => $q->where('company_id', $company->id))
            ->with(['lead:id,first_name,last_name', 'assignee:id,name,email', 'creator:id,name,email']);

        if ($request->has('lead_id')) $query->where('lead_id', $request->lead_id);
        if ($request->has('status'))  $query->where('status', $request->status);
        if ($request->has('priority')) $query->where('priority', $request->priority);

        $tasks = $query->orderBy('due_date')->paginate($request->input('per_page', 20));
        return response()->json($tasks);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $lead = \App\Models\Lead::where('company_id', $company->id)->find($request->lead_id);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $validated = $request->validate([
            'lead_id'     => 'required|exists:leads,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'status'      => 'nullable|in:pending,in_progress,completed,cancelled',
            'due_date'    => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $task = Task::create([...$validated, 'reminder' => false]);
        return response()->json(['message' => 'Task created successfully', 'data' => $task], 201);
    }
}
