<?php
namespace App\Http\Controllers;

use App\Events\AppointmentLiveUpdate;
use App\Jobs\FireIntegrationWebhook;
use App\Models\Appointment;
use App\Models\Calendar;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Record;
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
            // Scoping to one calendar is what makes switching mean anything.
            // Omitting the filter returns the whole company, which is both the
            // old behaviour and what an overlay view will want.
            ->when($request->filled('calendar_id'), function ($query) use ($request, $companyId) {
                $ids = array_filter((array) $request->query('calendar_id'));

                // Verified against this company before use: an id from the
                // query string must never reach another tenant's calendar.
                $ownIds = Calendar::fromCompany($companyId)->whereIn('id', $ids)->pluck('id');

                $query->whereIn('calendar_id', $ownIds);
            })
            ->with(['appointmentType', 'user:id,name,email', 'lead:id,first_name,last_name', 'products:id,name,sku,base_price,type'])
            ->orderBy('scheduled_at')
            ->get();

        return response()->json($appointments);
    }

    // Internal: create
    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id'              => 'nullable|exists:leads,id',
            'calendar_id'          => 'nullable|integer',
            'appointment_type_id'  => 'nullable|exists:appointment_types,id',
            'title'                => 'required|string|max:255',
            'scheduled_at'         => 'required|date',
            'end_at'               => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'             => 'sometimes|string|max:100',
            'notes'                => 'nullable|string',
            'status'               => 'sometimes|in:draft,scheduled,completed,cancelled,no_show',
            'product_ids'          => 'sometimes|array',
            'product_ids.*'        => 'integer|exists:products,id',
        ]);

        $user = Auth::user();
        $data['company_id'] = $user->company_id;
        $data['user_id']    = Auth::id();
        $data['timezone']   = $data['timezone'] ?? $user->company?->timezone ?? 'UTC';

        // Always lands on a calendar: the one asked for when it belongs to this
        // company, the company default otherwise. Callers that predate
        // calendars therefore keep working unchanged.
        $data['calendar_id'] = $this->resolveCalendarId($data['calendar_id'] ?? null, $user->company_id);

        if ($request->has('lead_timezone')) {
            if (!empty($data['lead_id'])) {
                Lead::where('id', $data['lead_id'])->update(['timezone' => $request->lead_timezone]);
            }
        }

        $productIds = $data['product_ids'] ?? [];
        $appointment = Appointment::create(collect($data)->except('product_ids')->toArray());

        if (!empty($productIds)) {
            $appointment->products()->sync($productIds);
        }

        $appointment->load(['appointmentType', 'user:id,name,email', 'lead:id,company_id,first_name,last_name,funnel_id,assigned_to', 'products:id,name,sku,base_price,type']);

        $lead  = $appointment->lead;
        $actor = Auth::user();

        if ($lead) {
            Record::createAppointmentCreated($appointment, $lead, $actor);
        }

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

        broadcast(new AppointmentLiveUpdate('created', $appointment));

        FireIntegrationWebhook::dispatch('appointment_created', [
            'id'                  => $appointment->id,
            'company_id'          => $appointment->company_id,
            'lead_id'             => $appointment->lead_id,
            'appointment_type_id' => $appointment->appointment_type_id,
            'user_id'             => $appointment->user_id,
            'title'               => $appointment->title,
            'scheduled_at'        => $appointment->scheduled_at?->toISOString(),
            'end_at'              => $appointment->end_at?->toISOString(),
            'timezone'            => $appointment->timezone,
            'status'              => $appointment->status,
            'created_at'          => $appointment->created_at?->toISOString(),
        ]);

        return response()->json($appointment, 201);
    }

    // Internal: update
    public function update(Request $request, Appointment $appointment)
    {
        $data = $request->validate([
            'title'               => 'sometimes|string|max:255',
            'calendar_id'         => 'sometimes|nullable|integer',
            'appointment_type_id' => 'nullable|exists:appointment_types,id',
            'scheduled_at'        => 'sometimes|date',
            'end_at'              => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'            => 'sometimes|string|max:100',
            'notes'               => 'nullable|string',
            'status'              => 'sometimes|in:draft,scheduled,completed,cancelled,no_show',
            'product_ids'         => 'sometimes|array',
            'product_ids.*'       => 'integer|exists:products,id',
        ]);

        $wasCompleted = $appointment->status === 'completed';

        // Moving between calendars is an ordinary edit, but the target still
        // has to belong to this appointment's company.
        if (array_key_exists('calendar_id', $data)) {
            $data['calendar_id'] = $this->resolveCalendarId($data['calendar_id'], $appointment->company_id);
        }

        if (array_key_exists('product_ids', $data)) {
            $appointment->products()->sync($data['product_ids']);
        }

        $appointment->update(collect($data)->except('product_ids')->toArray());
        $fresh = $appointment->fresh(['appointmentType', 'lead:id,company_id,first_name,last_name,funnel_id', 'user:id,name,email', 'products:id,name,sku,base_price,type']);

        if ($fresh->lead) {
            $justCompleted = ($data['status'] ?? null) === 'completed' && !$wasCompleted;
            if ($justCompleted) {
                Record::createAppointmentCompleted($fresh, $fresh->lead, Auth::user());
            } else {
                Record::createAppointmentUpdated($fresh, $fresh->lead, Auth::user());
            }
        }

        broadcast(new AppointmentLiveUpdate('updated', $fresh));
        return response()->json($fresh);
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
        broadcast(new AppointmentLiveUpdate('deleted', $appointment));
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

        // A lead is optional now, but when one is supplied it must belong to
        // the calling company — otherwise this would leak cross-tenant ids.
        if ($request->filled('lead_id')) {
            $lead = Lead::where('company_id', $company->id)->find($request->lead_id);
            if (!$lead) return response()->json(['message' => 'Lead not found'], 404);
        }

        $validated = $request->validate([
            'lead_id'              => 'nullable|exists:leads,id',
            'calendar_id'          => 'nullable|integer',
            'appointment_type_id'  => 'nullable|exists:appointment_types,id',
            'title'                => 'required|string|max:255',
            'scheduled_at'         => 'required|date',
            'end_at'               => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'             => 'sometimes|string|max:100',
            'notes'                => 'nullable|string',
            'status'               => 'sometimes|in:draft,scheduled,completed,cancelled,no_show',
            'product_ids'          => 'sometimes|array',
            'product_ids.*'        => 'integer|exists:products,id',
        ]);

        $productIds  = $validated['product_ids'] ?? [];
        $appointment = Appointment::create([
            ...collect($validated)->except('product_ids')->toArray(),
            'company_id'  => $company->id,
            'status'      => $validated['status'] ?? 'draft',
            'timezone'    => $validated['timezone'] ?? $company->timezone ?? 'UTC',
            'calendar_id' => $this->resolveCalendarId($validated['calendar_id'] ?? null, $company->id),
        ]);

        if (!empty($productIds)) {
            $appointment->products()->sync($productIds);
        }

        $appointment->load(['appointmentType', 'lead:id,company_id,first_name,last_name,funnel_id,assigned_to', 'user:id,name,email', 'products:id,name,sku,base_price,type']);

        if ($appointment->lead) {
            Record::createAppointmentCreated($appointment, $appointment->lead, null);
        }

        broadcast(new AppointmentLiveUpdate('created', $appointment));

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
            '_appointment_model' => $appointment,
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
            'calendar_id'         => 'sometimes|nullable|integer',
            'appointment_type_id' => 'nullable|exists:appointment_types,id',
            'scheduled_at'        => 'sometimes|date',
            'end_at'              => 'nullable|date|after_or_equal:scheduled_at',
            'timezone'            => 'sometimes|string|max:100',
            'notes'               => 'nullable|string',
            'status'              => 'sometimes|in:draft,scheduled,completed,cancelled,no_show',
            'product_ids'         => 'sometimes|array',
            'product_ids.*'       => 'integer|exists:products,id',
        ]);

        $wasCompleted = $appointment->status === 'completed';

        if (array_key_exists('calendar_id', $validated)) {
            $validated['calendar_id'] = $this->resolveCalendarId($validated['calendar_id'], $appointment->company_id);
        }

        if (array_key_exists('product_ids', $validated)) {
            $appointment->products()->sync($validated['product_ids']);
        }

        $appointment->update(collect($validated)->except('product_ids')->toArray());

        $fresh = $appointment->fresh(['appointmentType', 'lead:id,company_id,first_name,last_name', 'products:id,name,sku,base_price,type']);

        if ($fresh->lead) {
            $justCompleted = ($validated['status'] ?? null) === 'completed' && !$wasCompleted;
            if ($justCompleted) {
                Record::createAppointmentCompleted($fresh, $fresh->lead, null);
            } else {
                Record::createAppointmentUpdated($fresh, $fresh->lead, null);
            }
        }

        return response()->json([
            'message' => 'Appointment updated successfully',
            'data'    => $fresh,
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

    /**
     * The calendar an appointment belongs on.
     *
     * A requested calendar is honoured only when it belongs to the same
     * company; anything else — null, an unknown id, another tenant's id —
     * falls back to that company's default calendar. Falling back rather than
     * failing keeps every non-UI creation path (webhooks, the public API,
     * automations) working without knowing calendars exist.
     */
    private function resolveCalendarId(?int $requested, int $companyId): int
    {
        if ($requested) {
            $owned = Calendar::fromCompany($companyId)->whereKey($requested)->value('id');

            if ($owned) {
                return $owned;
            }
        }

        return Calendar::defaultFor($companyId)->id;
    }
}
