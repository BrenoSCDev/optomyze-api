<?php
// app/Http/Controllers/EventController.php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $companyId = Auth::user()->company_id;

        $tasks = Task::query()
            ->whereHas('lead', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->with([
                'lead:id,first_name,last_name,email,funnel_id',
                'assignee:id,name,email',
                'creator:id,name,email',
            ])
            ->orderBy('due_date')
            ->get();
        
        $events = Event::where('company_id', $companyId)
            ->orderBy('start_at')
            ->get();
        
        return response()->json([
            'tasks' => $tasks,
            'events' => $events
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['boolean'],
            'reminder' => ['boolean'],
        ]);

        $data['company_id'] = Auth::user()->company_id;

        return Event::create($data);
    }

    public function show(Event $event)
    {
        return $event;
    }

    public function update(Request $request, Event $event)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'start_at' => ['sometimes', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['boolean'],
            'reminder' => ['boolean'],
        ]);

        $event->update($data);

        return $event;
    }

    public function destroy(Event $event)
    {
        $event->delete();

        return response()->noContent();
    }
}
