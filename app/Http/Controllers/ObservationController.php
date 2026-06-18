<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Observation;
use App\Models\Record;
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

        $lead = Lead::find($data['lead_id']);
        if ($lead) {
            Record::createObservationCreated($observation, $lead, Auth::user());
        }

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

    ////////////////// V1 API METHODS //////////////////

    public function apiIndex(Request $request, int $leadId)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $lead = \App\Models\Lead::where('company_id', $company->id)->find($leadId);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $observations = Observation::where('lead_id', $leadId)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($observations);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $lead = \App\Models\Lead::where('company_id', $company->id)->find($request->lead_id);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'note'    => 'required|string',
        ]);

        $observation = Observation::create([...$validated, 'user_id' => null]);
        return response()->json(['message' => 'Observation created successfully', 'data' => $observation], 201);
    }
}
