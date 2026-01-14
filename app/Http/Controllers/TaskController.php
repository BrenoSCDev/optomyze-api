<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    /**
     * company tasks
     */
    public function companyTasks()
    {
        $companyId = Auth::user()->company_id;

        $tasks = Task::query()
            ->whereHas('lead', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
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
            'lead_id' => 'required|exists:leads,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'reminder' => 'required',
            'status' => 'nullable|in:pending,in_progress,completed,canceled',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $task = Task::create([
            ...$validated,
            'created_by' => Auth::id(),
        ]);

        // Se já criada como completed
        if (($validated['status'] ?? null) === 'completed') {
            $task->markAsCompleted();
        }

        $task->load(['creator', 'assignee']);

        return response()->json([
            'message' => 'Tarefa criada com sucesso',
            'task' => $task
        ], 201);
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
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'reminder' => 'required',
            'status' => 'nullable|in:pending,in_progress,completed,cancelled',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $task->update($validated);

        if (($validated['status'] ?? null) === 'completed' && !$task->completed_at) {
            $task->markAsCompleted();
        }

        $task->load(['creator', 'assignee']);

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
}
