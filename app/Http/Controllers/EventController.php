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

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $query = Event::where('company_id', $company->id);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('start_from')) {
            $query->where('start_at', '>=', $request->start_from);
        }
        if ($request->has('start_to')) {
            $query->where('start_at', '<=', $request->start_to);
        }

        $events = $query->orderBy('start_at')->paginate($request->input('per_page', 20));

        return response()->json($events);
    }

    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $event = Event::where('company_id', $company->id)->find($id);

        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        return response()->json(['data' => $event]);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'type'        => 'nullable|string|max:50',
            'start_at'    => 'required|date',
            'end_at'      => 'nullable|date|after_or_equal:start_at',
            'all_day'     => 'boolean',
            'reminder'    => 'boolean',
        ]);

        $event = Event::create([
            ...$validated,
            'company_id' => $company->id,
        ]);

        return response()->json([
            'message' => 'Event created successfully',
            'data'    => $event,
        ], 201);
    }

    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $event = Event::where('company_id', $company->id)->find($id);
        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        $validated = $request->validate([
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'type'        => 'nullable|string|max:50',
            'start_at'    => 'nullable|date',
            'end_at'      => 'nullable|date|after_or_equal:start_at',
            'all_day'     => 'boolean',
            'reminder'    => 'boolean',
        ]);

        $event->update($validated);

        return response()->json([
            'message' => 'Event updated successfully',
            'data'    => $event->fresh(),
        ]);
    }

    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $event = Event::where('company_id', $company->id)->find($id);
        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        $event->delete();

        return response()->json(['message' => 'Event deleted successfully']);
    }
}
