<?php
namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppointmentController extends Controller
{
    // Internal: list appointments for a lead
    public function index(Request $request, $leadId)
    {
        $appointments = Appointment::where('lead_id', $leadId)
            ->with(['appointmentType', 'user:id,name,email'])
            ->orderBy('scheduled_at')
            ->get();
        return response()->json($appointments);
    }

    // Internal: list all company appointments (for calendar)
    public function companyIndex(Request $request)
    {
        $companyId = Auth::user()->company_id;
        $appointments = Appointment::where('company_id', $companyId)
            ->with(['appointmentType', 'user:id,name,email', 'lead:id,first_name,last_name'])
            ->orderBy('scheduled_at')
            ->get();
        return response()->json($appointments);
    }

    // Internal: create
    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id'              => 'required|exists:leads,id',
            'appointment_type_id'  => 'nullable|exists:appointment_types,id',
            'title'                => 'required|string|max:255',
            'scheduled_at'         => 'required|date',
            'end_at'               => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'             => 'sometimes|string|max:100',
            'notes'                => 'nullable|string',
            'status'               => 'sometimes|in:scheduled,completed,cancelled,no_show',
        ]);

        $data['company_id'] = Auth::user()->company_id;
        $data['user_id']    = Auth::id();

        // Update lead timezone if provided
        if ($request->has('lead_timezone')) {
            Lead::where('id', $data['lead_id'])->update(['timezone' => $request->lead_timezone]);
        }

        $appointment = Appointment::create($data);
        $appointment->load(['appointmentType', 'user:id,name,email']);

        return response()->json($appointment, 201);
    }

    // Internal: update
    public function update(Request $request, Appointment $appointment)
    {
        $data = $request->validate([
            'title'               => 'sometimes|string|max:255',
            'appointment_type_id' => 'nullable|exists:appointment_types,id',
            'scheduled_at'        => 'sometimes|date',
            'end_at'              => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'            => 'sometimes|string|max:100',
            'notes'               => 'nullable|string',
            'status'              => 'sometimes|in:scheduled,completed,cancelled,no_show',
        ]);
        $appointment->update($data);
        return response()->json($appointment->fresh(['appointmentType', 'user:id,name,email']));
    }

    // Internal: delete
    public function destroy(Appointment $appointment)
    {
        $appointment->delete();
        return response()->noContent();
    }

    ////////////////// V1 API METHODS //////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $query = Appointment::where('company_id', $company->id)
            ->with(['appointmentType', 'user:id,name,email', 'lead:id,first_name,last_name']);

        if ($request->has('lead_id'))    $query->where('lead_id', $request->lead_id);
        if ($request->has('status'))     $query->where('status', $request->status);
        if ($request->has('start_from')) $query->where('scheduled_at', '>=', $request->start_from);
        if ($request->has('start_to'))   $query->where('scheduled_at', '<=', $request->start_to);

        $appointments = $query->orderBy('scheduled_at')->paginate($request->input('per_page', 20));
        return response()->json($appointments);
    }

    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $appointment = Appointment::where('company_id', $company->id)
            ->with(['appointmentType', 'user:id,name,email', 'lead:id,first_name,last_name'])
            ->find($id);

        if (!$appointment) return response()->json(['message' => 'Appointment not found'], 404);
        return response()->json(['data' => $appointment]);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        // Validate lead belongs to company
        $lead = Lead::where('company_id', $company->id)->find($request->lead_id);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $validated = $request->validate([
            'lead_id'              => 'required|exists:leads,id',
            'appointment_type_id'  => 'nullable|exists:appointment_types,id',
            'title'                => 'required|string|max:255',
            'scheduled_at'         => 'required|date',
            'end_at'               => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'             => 'sometimes|string|max:100',
            'notes'                => 'nullable|string',
            'status'               => 'sometimes|in:scheduled,completed,cancelled,no_show',
        ]);

        $appointment = Appointment::create([...$validated, 'company_id' => $company->id]);
        return response()->json(['message' => 'Appointment created successfully', 'data' => $appointment->load(['appointmentType', 'lead:id,first_name,last_name'])], 201);
    }

    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $appointment = Appointment::where('company_id', $company->id)->find($id);
        if (!$appointment) return response()->json(['message' => 'Appointment not found'], 404);

        $validated = $request->validate([
            'title'               => 'sometimes|string|max:255',
            'appointment_type_id' => 'nullable|exists:appointment_types,id',
            'scheduled_at'        => 'sometimes|date',
            'end_at'              => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'            => 'sometimes|string|max:100',
            'notes'               => 'nullable|string',
            'status'              => 'sometimes|in:scheduled,completed,cancelled,no_show',
        ]);

        $appointment->update($validated);
        return response()->json(['message' => 'Appointment updated successfully', 'data' => $appointment->fresh(['appointmentType', 'lead:id,first_name,last_name'])]);
    }

    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $appointment = Appointment::where('company_id', $company->id)->find($id);
        if (!$appointment) return response()->json(['message' => 'Appointment not found'], 404);

        $appointment->delete();
        return response()->json(['message' => 'Appointment deleted successfully']);
    }
}
