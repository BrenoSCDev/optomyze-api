<?php
namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Product;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppointmentController extends Controller
{
    // Internal: list appointments for a lead
    public function index(Request $request, $leadId)
    {
        $appointments = Appointment::where('lead_id', $leadId)
            ->with(['appointmentType', 'user:id,name,email', 'products:id,name,sku,base_price,type'])
            ->orderBy('scheduled_at')
            ->get();
        return response()->json($appointments);
    }

    // Internal: list all company appointments (for calendar)
    public function companyIndex(Request $request)
    {
        $companyId = Auth::user()->company_id;
        $appointments = Appointment::where('company_id', $companyId)
            ->with(['appointmentType', 'user:id,name,email', 'lead:id,first_name,last_name', 'products:id,name,sku,base_price,type'])
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
            'product_ids'          => 'sometimes|array',
            'product_ids.*'        => 'integer|exists:products,id',
        ]);

        $data['company_id'] = Auth::user()->company_id;
        $data['user_id']    = Auth::id();

        if ($request->has('lead_timezone')) {
            Lead::where('id', $data['lead_id'])->update(['timezone' => $request->lead_timezone]);
        }

        $productIds = $data['product_ids'] ?? [];
        $appointment = Appointment::create(collect($data)->except('product_ids')->toArray());

        if (!empty($productIds)) {
            $appointment->products()->sync($productIds);
        }

        $appointment->load(['appointmentType', 'user:id,name,email', 'products:id,name,sku,base_price,type']);

        $lead  = Lead::find($appointment->lead_id);
        $actor = Auth::user();
        app(NotificationService::class)->dispatch('appointment_scheduled', $appointment->company_id, [
            'lead_owner_id'      => $lead?->assigned_to,
            'actor_id'           => $actor->id,
            'actor_name'         => $actor->name,
            'lead'               => $lead ? ['id' => $lead->id, 'name' => trim("{$lead->first_name} {$lead->last_name}")] : null,
            'appointment'        => [
                'title'         => $appointment->title,
                'scheduled_at'  => $appointment->scheduled_at
                    ->setTimezone($appointment->timezone ?? 'UTC')
                    ->format('d/m/Y \a\t H:i'),
                'assigned_user' => $appointment->user?->name,
            ],
            '_appointment_model' => $appointment,
            '_actor_model'       => $actor,
        ]);

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
            'product_ids'         => 'sometimes|array',
            'product_ids.*'       => 'integer|exists:products,id',
        ]);

        if (array_key_exists('product_ids', $data)) {
            $appointment->products()->sync($data['product_ids']);
        }

        $appointment->update(collect($data)->except('product_ids')->toArray());
        return response()->json($appointment->fresh(['appointmentType', 'user:id,name,email', 'products:id,name,sku,base_price,type']));
    }

    // Internal: sync products on an appointment
    public function syncProducts(Request $request, Appointment $appointment)
    {
        $request->validate([
            'product_ids'   => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $appointment->products()->sync($request->product_ids);
        return response()->json([
            'message'  => 'Products updated.',
            'products' => $appointment->products()->get(['products.id', 'name', 'sku', 'base_price', 'type']),
        ]);
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
            ->with(['appointmentType', 'user:id,name,email', 'lead:id,first_name,last_name', 'products:id,name,sku,base_price,type']);

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
            ->with(['appointmentType', 'user:id,name,email', 'lead:id,first_name,last_name', 'products:id,name,sku,base_price,type'])
            ->find($id);

        if (!$appointment) return response()->json(['message' => 'Appointment not found'], 404);
        return response()->json(['data' => $appointment]);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

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
            'product_ids'          => 'sometimes|array',
            'product_ids.*'        => 'integer|exists:products,id',
        ]);

        $productIds  = $validated['product_ids'] ?? [];
        $appointment = Appointment::create([
            ...collect($validated)->except('product_ids')->toArray(),
            'company_id' => $company->id,
        ]);

        if (!empty($productIds)) {
            $appointment->products()->sync($productIds);
        }

        $appointment->load(['appointmentType', 'lead:id,first_name,last_name', 'user:id,name', 'products:id,name,sku,base_price,type']);

        app(NotificationService::class)->dispatch('appointment_scheduled', $appointment->company_id, [
            'lead_owner_id'      => $appointment->lead?->assigned_to,
            'actor_name'         => null,
            'lead'               => $appointment->lead ? ['id' => $appointment->lead->id, 'name' => trim("{$appointment->lead->first_name} {$appointment->lead->last_name}")] : null,
            'appointment'        => [
                'title'         => $appointment->title,
                'scheduled_at'  => $appointment->scheduled_at
                    ->setTimezone($appointment->timezone ?? 'UTC')
                    ->format('d/m/Y \a\t H:i'),
                'assigned_user' => $appointment->user?->name,
            ],
        ]);

        return response()->json([
            'message' => 'Appointment created successfully',
            'data'    => $appointment,
        ], 201);
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
            'product_ids'         => 'sometimes|array',
            'product_ids.*'       => 'integer|exists:products,id',
        ]);

        if (array_key_exists('product_ids', $validated)) {
            $appointment->products()->sync($validated['product_ids']);
        }

        $appointment->update(collect($validated)->except('product_ids')->toArray());

        return response()->json([
            'message' => 'Appointment updated successfully',
            'data'    => $appointment->fresh(['appointmentType', 'lead:id,first_name,last_name', 'products:id,name,sku,base_price,type']),
        ]);
    }

    public function apiSyncProducts(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $appointment = Appointment::where('company_id', $company->id)->find($id);
        if (!$appointment) return response()->json(['message' => 'Appointment not found'], 404);

        $request->validate([
            'product_ids'   => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $appointment->products()->sync($request->product_ids);

        return response()->json([
            'message'  => 'Products updated.',
            'products' => $appointment->products()->get(['products.id', 'name', 'sku', 'base_price', 'type']),
        ]);
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
