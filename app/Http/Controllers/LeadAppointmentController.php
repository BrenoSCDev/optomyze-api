<?php

namespace App\Http\Controllers;

use App\Events\AppointmentLiveUpdate;
use App\Events\LeadLiveUpdate;
use App\Jobs\FireIntegrationWebhook;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Calendar;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Record;
use App\Models\User;
use App\Services\LeadQueueAssignmentService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadAppointmentController extends Controller
{
    /**
     * Create an appointment for a lead identified by phone number, within
     * the API token's company. If no lead with that phone number exists
     * yet in this company, one is created first (using the `lead` payload)
     * and the appointment is attached to it; if a lead already exists, the
     * appointment is simply attached to that lead — no duplicate lead is
     * ever created for the same phone number within a company.
     */
    public function store(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'phone' => 'required|string|max:20',

            // Only used when a new lead needs to be created for this phone number.
            'lead' => 'nullable|array',
            'lead.funnel_id' => 'nullable|exists:funnels,id',
            'lead.stage_id' => 'nullable|exists:stages,id',
            'lead.assigned_to' => 'nullable|exists:users,id',
            'lead.external_id' => 'nullable|string|max:255',
            'lead.source_platform' => 'nullable|string|max:50',
            'lead.source_type' => 'nullable|string|max:50',
            'lead.first_name' => 'nullable|string|max:100',
            'lead.last_name' => 'nullable|string|max:100',
            'lead.email' => 'nullable|email|max:255',
            'lead.username' => 'nullable|string|max:100',
            'lead.priority' => 'nullable|in:low,medium,high,urgent',
            'lead.currency' => 'nullable|string|size:3',
            'lead.preferred_contact_method' => 'nullable|string|max:50',
            'lead.timezone' => 'nullable|string|max:50',
            'lead.language' => 'nullable|string|max:5',
            'lead.notes' => 'nullable|string',

            // Always used — the appointment is created on every call.
            'appointment' => 'required|array',
            'appointment.calendar_id' => 'nullable|integer',
            'appointment.appointment_type_id' => 'nullable|exists:appointment_types,id',
            'appointment.title' => 'required|string|max:255',
            'appointment.scheduled_at' => 'required|date',
            'appointment.end_at' => 'nullable|date|after_or_equal:appointment.scheduled_at',
            'appointment.timezone' => 'sometimes|string|max:100',
            'appointment.notes' => 'nullable|string',
            'appointment.status' => 'sometimes|in:draft,scheduled,completed,cancelled,no_show',
            'appointment.product_ids' => 'sometimes|array',
            'appointment.product_ids.*' => 'integer|exists:products,id',
        ]);

        $normalizedPhone = preg_replace('/\D/', '', $validated['phone']);
        if ($normalizedPhone === '') {
            return response()->json(['message' => 'Invalid phone number'], 422);
        }

        // findByPhone matches both old and new Brazilian mobile formats.
        $lead = Lead::findByPhone($company->id, $normalizedPhone);

        $leadWasCreated = false;

        if (!$lead) {
            $leadInput = $validated['lead'] ?? [];

            /*
             * Neither a funnel nor a name is required.
             *
             * A contact exists in its own right now — it is reachable from
             * Contacts with no pipeline at all — so demanding a funnel_id here
             * meant an integration that only wanted to book an appointment had
             * to know the CRM's pipeline layout first. A name was demanded for
             * no better reason: the phone number identifies the contact, and
             * display names already fall back to username, email, or Unknown.
             *
             * The whole `lead` payload can be omitted. What arrives is a
             * contact holding the phone number and an appointment against it.
             */
            $funnel = null;
            $stage  = null;

            if (!empty($leadInput['funnel_id'])) {
                $funnel = Funnel::where('company_id', $company->id)->find($leadInput['funnel_id']);
                if (!$funnel) {
                    return response()->json(['message' => 'Funnel not found'], 404);
                }

                // An explicit stage_id is honored if it belongs to this funnel;
                // otherwise fall back to the funnel's entry stage as before.
                $stage = !empty($leadInput['stage_id'])
                    ? $funnel->stages()->where('id', $leadInput['stage_id'])->first()
                    : null;

                if (!$stage) {
                    $stage = $funnel->stages()->where('type', 'entry')->first();
                }

                // Still an error, but only for a caller who asked for a funnel:
                // they named one that cannot receive a contact, and that is a
                // configuration problem worth reporting rather than silently
                // filing the contact somewhere else.
                if (!$stage) {
                    return response()->json(['message' => 'No entry stage found for this funnel'], 422);
                }
            }

            if (!empty($leadInput['assigned_to'])
                && !User::where('company_id', $company->id)->where('id', $leadInput['assigned_to'])->exists()) {
                return response()->json(['message' => 'assigned_to user not found'], 422);
            }

            $lead = DB::transaction(function () use ($leadInput, $normalizedPhone, $company, $funnel, $stage) {
                return Lead::create([
                    ...collect($leadInput)->except(['funnel_id', 'stage_id'])->toArray(),
                    'company_id' => $company->id,
                    'phone' => $normalizedPhone,
                    // Both or neither: a stage means nothing outside its funnel.
                    'funnel_id' => $stage ? $funnel->id : null,
                    'stage_id'  => $stage?->id,
                ]);
            });

            LeadQueueAssignmentService::assign($lead);
            $leadWasCreated = true;
        }

        $appointmentInput = $validated['appointment'];

        if (!empty($appointmentInput['appointment_type_id'])
            && !AppointmentType::where('company_id', $company->id)->where('id', $appointmentInput['appointment_type_id'])->exists()) {
            return response()->json(['message' => 'Appointment type not found'], 404);
        }

        $productIds = $appointmentInput['product_ids'] ?? [];

        $appointment = DB::transaction(function () use ($appointmentInput, $company, $lead, $productIds) {
            $appointment = Appointment::create([
                ...collect($appointmentInput)->except('product_ids')->toArray(),
                'company_id' => $company->id,
                'lead_id' => $lead->id,
                'status' => $appointmentInput['status'] ?? 'draft',
                'timezone' => $appointmentInput['timezone'] ?? $company->timezone ?? 'UTC',
                // Callers of this endpoint are integrations that generally know
                // nothing about calendars, so an omitted (or foreign) id files
                // the appointment on the company's default calendar instead of
                // leaving it unreachable from every calendar view.
                'calendar_id' => Calendar::resolveForCompany(
                    $appointmentInput['calendar_id'] ?? null,
                    $company->id
                ),
            ]);

            if (!empty($productIds)) {
                $appointment->products()->sync($productIds);
            }

            return $appointment;
        });

        $appointment->load(['appointmentType', 'lead:id,company_id,first_name,last_name,funnel_id,assigned_to', 'user:id,name,email', 'products:id,name,sku,base_price,type']);

        $apiToken = $request->get('api_token');

        if ($leadWasCreated) {
            Record::createLeadCreation(
                $lead,
                null,
                Record::SOURCE_API,
                [
                    'api_token_id' => $apiToken?->id,
                    'api_token_name' => $apiToken?->name,
                    'apiTokenName' => $apiToken?->name,
                ],
                $apiToken ? 'via_api_named' : 'via_api'
            );

            if ($lead->funnel_id) {
                broadcast(new LeadLiveUpdate('created', $lead));
            }

            FireIntegrationWebhook::dispatch('lead_created', [
                'id' => $lead->id,
                'company_id' => $lead->company_id,
                'funnel_id' => $lead->funnel_id,
                'stage_id' => $lead->stage_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'created_at' => $lead->created_at?->toISOString(),
            ]);
        }

        Record::createAppointmentCreated($appointment, $lead, null);

        broadcast(new AppointmentLiveUpdate('created', $appointment));

        app(NotificationService::class)->dispatch('appointment_scheduled', $company->id, [
            'lead_owner_id' => $lead->assigned_to,
            'actor_name' => null,
            // display_name, not the raw name parts: a contact created from this
            // endpoint need not have a name, and the notification would have
            // announced an appointment with nobody. It falls back to the phone
            // number, which is what identified the contact in the first place.
            'lead' => ['id' => $lead->id, 'name' => $lead->display_name],
            'appointment' => [
                'title' => $appointment->title,
                'scheduled_at' => $appointment->scheduled_at
                    ->setTimezone($appointment->timezone ?? 'UTC')
                    ->format('d/m/Y \a\t H:i'),
                'assigned_user' => $appointment->user?->name,
            ],
            '_appointment_model' => $appointment,
        ]);

        return response()->json([
            'message' => $leadWasCreated
                ? 'Lead and appointment created successfully'
                : 'Appointment created and associated with existing lead',
            'data' => [
                'lead' => $lead,
                'lead_created' => $leadWasCreated,
                'appointment' => $appointment,
            ],
        ], 201);
    }
}
