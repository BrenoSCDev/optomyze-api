<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ObservationController extends Controller
{
    /**
     * List observations by lead
     */
    public function index($leadId)
    {
        return Observation::where('lead_id', $leadId)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Store a new observation
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'note' => ['required', 'string'],
        ]);

        $observation = Observation::create([
            'lead_id' => $data['lead_id'],
            'note' => $data['note'],
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Observation created successfully.',
            'data' => $observation->load('user:id,name,email'),
        ], 201);
    }

    /**
     * Update observation
     */
    public function update(Request $request, Observation $observation)
    {
        $data = $request->validate([
            'note' => ['required', 'string'],
        ]);

        $observation->update($data);

        return response()->json([
            'message' => 'Observation updated successfully.',
            'data' => $observation->fresh('user:id,name,email'),
        ]);
    }

    /**
     * Delete observation
     */
    public function destroy(Observation $observation)
    {
        $observation->delete();

        return response()->json([
            'message' => 'Observation deleted successfully.',
        ]);
    }
}
