<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Task;
use Carbon\Carbon;
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
            'status' => 'nullable|in:pending,in_progress,completed,cancelled',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        return DB::transaction(function () use ($validated) {

            $lead = Lead::findOrFail($validated['lead_id']);

            $task = Task::create([
                ...$validated,
                'created_by' => Auth::id(),
            ]);

            if (($validated['status'] ?? null) === 'completed') {
                $task->markAsCompleted();
            }

            $task->load(['creator', 'assignee']);

            // Build professional CRM-style description
            $description = "Task \"{$task->title}\" created by {$task->creator->name}";

            if ($task->assignee) {
                $description .= " assigned to {$task->assignee->name}";
            }

            if ($task->priority) {
                $description .= " (" . ucfirst($task->priority) . " priority)";
            }

            if ($task->due_date) {
                $description .= " – Due: " . Carbon::parse($task->due_date)->format('d/m/Y');
            }

            if ($task->status === 'completed') {
                $description .= " and marked as completed";
            }

            $transaction = $lead->transactions()->create([
                'company_id' => $lead->company_id,
                'user_id' => Auth::id(),
                'type' => 'task',
                'action' => 'created',
                'description' => $description,
            ]);

            $transaction->load('user');

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
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|string',
                'priority' => 'sometimes|in:low,medium,high,urgent',
                'reminder' => 'sometimes',
                'status' => 'sometimes',
                'due_date' => 'sometimes|date',
                'assigned_to' => 'sometimes|exists:users,id',
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
