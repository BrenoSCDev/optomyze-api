<?php

namespace App\Http\Controllers;

use App\Events\LeadLiveUpdate;
use App\Jobs\FireIntegrationWebhook;
use App\Models\EntryRequirement;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use App\Models\Lead;
use App\Support\PhoneNormalizer;
use App\Models\LeadCompany;
use App\Models\LeadCustomField;
use App\Models\Record;
use App\Models\LostReason;
use App\Models\Product;
use App\Models\Stage;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\LeadQueueAssignmentService;

use App\Traits\ResolvesActivityDateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;

class LeadController extends Controller
{
    use ResolvesActivityDateRange;

    /**
     * Display a listing of leads.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Lead::fromCompany($user->company_id)->visibleToUser($user);

        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $leads = $query->paginate(20);

        return response()->json($leads);
    }

    /**
     * Store a newly created lead.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        $validated = $request->validate(Lead::validationRules());

        // The company comes from the session, never from the request. The
        // rules accept a company_id because other callers build the payload,
        // but honouring a client-supplied one would let any authenticated
        // user create a lead inside somebody else's company.
        $validated['company_id'] = $user->company_id;

        // A funnel, if named, has to be one of this company's — otherwise the
        // lead would land on another tenant's board.
        if (!empty($validated['funnel_id'])) {
            $funnel = \App\Models\Funnel::where('id', $validated['funnel_id'])
                ->where('company_id', $user->company_id)
                ->first();

            if (!$funnel) {
                throw ValidationException::withMessages([
                    'funnel_id' => ['Select a funnel that belongs to your company.'],
                ]);
            }

            if (!empty($validated['stage_id'])
                && !Stage::where('id', $validated['stage_id'])->where('funnel_id', $funnel->id)->exists()) {
                throw ValidationException::withMessages([
                    'stage_id' => ['Select a stage that belongs to the chosen funnel.'],
                ]);
            }
        }

        // Create the lead
        $lead = Lead::create($validated);

        // Replicate model-level lead custom fields to this lead
        $modelFields = LeadCustomField::where('company_id', $lead->company_id)
            ->where('type', 'model')
            ->get();

        foreach ($modelFields as $field) {
            LeadCustomField::create([
                'company_id'  => $lead->company_id,
                'lead_id'     => $lead->id,
                'type'        => 'lead',
                'field_key'   => $field->field_key,
                'field_type'  => $field->field_type,
                'field_value' => $field->field_value,
            ]);
        }

        // Auto-assign via funnel manager queue (round-robin)
        $this->applyQueueAssignment($lead);

        // Create a lead creation record
        Record::createLeadCreation(
            $lead,
            Auth::user(),
            Record::SOURCE_MANUAL,
            []
        );

        // Dispatch notification through the centralized service.
        // Checks: event enabled, quiet hours, channels, recipients.
        app(NotificationService::class)->dispatch('lead_created', $lead->company_id, [
            'lead_owner_id' => $lead->assigned_to,
            'actor_id'      => Auth::id(),
            'actor_name'    => Auth::user()->name,
            'lead'          => [
                'id'   => $lead->id,
                'name' => trim("{$lead->first_name} {$lead->last_name}"),
            ],
            '_lead_model'  => $lead,
            '_actor_model' => Auth::user(),
        ]);

        // Broadcast new lead live to funnel channel
        if ($lead->funnel_id) {
            Log::info("[LeadLive] Broadcasting created for lead {$lead->id} to funnel.{$lead->funnel_id}");
            broadcast(new LeadLiveUpdate('created', $lead));
        }

        // Fire integration webhooks (N8N / external automation)
        FireIntegrationWebhook::dispatch('lead_created', [
            'id'         => $lead->id,
            'company_id' => $lead->company_id,
            'funnel_id'  => $lead->funnel_id,
            'stage_id'   => $lead->stage_id,
            'first_name' => $lead->first_name,
            'last_name'  => $lead->last_name,
            'email'      => $lead->email,
            'phone'      => $lead->phone,
            'source'     => $lead->source,
            'created_at' => $lead->created_at?->toISOString(),
        ]);

        FireIntegrationWebhook::dispatch('lead_whatsapp', [
            'phone'      => $lead->phone,
            'name'       => trim("{$lead->first_name} {$lead->last_name}"),
            'email'      => $lead->email,
            'company_id' => $lead->company_id,
            'funnel_id'  => $lead->funnel_id,
            'created_at' => $lead->created_at?->toISOString(),
        ]);

        return response()->json([
            'message' => 'Lead created successfully',
            'data' => $lead
        ], 201);
    }

    /**
     * Display a specific lead.
     */
    public function show(Lead $lead)
    {
        $this->assertLeadAccess($lead, Auth::user());
        $lead->load([
            'assignedUser:id,name,email,role,profile_pic',
            'records' => function ($query) {
                $query->latest();
            },
            'records.user:id,name,email',
            'docs',
            'leadCompany:id,legal_name,industry,logo_path',
            'stage:id,name,funnel_id,order',
            'stage.funnel:id,name',
            'stage.funnel.stages:id,funnel_id,name,order,description,color,type',
            'stage.funnel.stages.entryRequirement',
            'whatsappInboxChat.messages' => function ($query) {
                $query->orderBy('sent_at');
            },
            'whatsappInboxChat.channelable',
            'inboxChats' => function ($query) {
                $query->with([
                    'messages' => function ($q) {
                        $q->orderBy('sent_at');
                    },
                    'channelable',
                ]);
            },
            'products:id,name,base_price,type,description',
            'tags',
            'tasks.assignee:id,name,email',
            'tasks.creator:id,name,email',
            'observations.user:id,name,email',
            'sales' => function ($query) {
                $query->with([
                    'user:id,name,email',
                    'items',
                    'charges',
                    'discounts',
                    'docs',
                    'funnel:id,name',
                    'stage:id,name,color',
                    'tags',
                ])->orderBy('created_at', 'desc');
            },
        ]);


        $lead->products->transform(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'base_price' => $product->base_price,
                'quantity' => (int) $product->pivot->quantity,
                'unit_price' => (float) $product->pivot->unit_price,
                'total_price' => (float) $product->pivot->total_price,
                'primary_image' => $product->primaryImage,
            ];
        });

        $user = Auth::user();

        $users = User::fromCompany($user->company_id)
            ->select('id', 'name', 'email')
            ->get();

        $products = Product::fromCompany($user->company_id)
            ->select('id', 'name')
            ->get();

        $companies = LeadCompany::where('company_id', $user->company_id)
            ->select('id', 'legal_name')
            ->get();
        
        $dealFunnels = \App\Models\Funnel::where('company_id', $user->company_id)
            ->with('stages:id,funnel_id,name,order')
            ->select('id', 'name')
            ->where('type', 'deal')
            ->get();

        // Estimated value from products of interest (not sales)
        $valueEstimated = $lead->products->sum('total_price');
        $lead->value_estimated = (float) $valueEstimated;

        return response()->json([
            'lead' => $lead,
            'users' => $users,
            'products' => $products,
            'companies' => $companies,
            'dealFunnels' => $dealFunnels,
        ]);
    }

    /**
     * Update an existing lead.
     */
    public function update(Request $request, Lead $lead)
    {
        $this->assertLeadAccess($lead, Auth::user());

        $validated = $request->validate(Lead::updateValidationRules());

        $lead->update($validated);

        return response()->json([
            'message' => 'Lead updated successfully',
            'data' => $lead
        ]);
    }

    /**
     * Remove a lead (soft delete).
     */
    public function destroy(Lead $lead)
    {
        $this->assertLeadAccess($lead, Auth::user());

        $lead->delete();

        return response()->json([
            'message' => 'Lead deleted successfully'
        ]);
    }

     /**
     * Move a lead to a new stage.
     */
    public function moveToStage(Request $request, Lead $lead)
    {
        $this->assertLeadAccess($lead, Auth::user());

        $validated = $request->validate([
            'stage_id' => 'required|exists:stages,id',
        ]);

        // Load target stage with its entry requirement before moving
        $targetStage = Stage::where('id', $validated['stage_id'])
            ->with('entryRequirement')
            ->firstOrFail();

        // Run entry requirement checks — bail early if any fail
        $errors = $this->checkEntryRequirements($lead, $targetStage->entryRequirement);

        if (!empty($errors)) {
            return response()->json([
                'message'                   => 'Lead does not meet the entry requirements for this stage.',
                'entry_requirement_errors'  => $errors,
            ], 422);
        }

        $oldStageId = $lead->stage_id;
        $lead->moveToStage($targetStage->id, Auth::user());

        $freshLead = $lead->fresh(['stage']);
        $isLost    = $freshLead->stage && $freshLead->stage->type === 'lost';

        // Broadcast stage change live to funnel channel
        if ($lead->funnel_id) {
            broadcast(new LeadLiveUpdate(
                'stage_changed',
                $freshLead,
                $oldStageId,
                $freshLead->stage?->type
            ));
        }

        return response()->json([
            'message'            => 'Lead moved successfully',
            'data'               => $freshLead,
            'prompt_lost_reason' => $isLost,
        ]);
    }

    private function checkEntryRequirements(Lead $lead, ?EntryRequirement $req): array
    {
        if (!$req) {
            return [];
        }

        $errors = [];

        if ($req->require_assigned_owner && empty($lead->assigned_to)) {
            $errors[] = [
                'field'   => 'require_assigned_owner',
                'message' => 'Lead must have an assigned owner.',
            ];
        }

        if ($req->require_contact_email && empty($lead->email)) {
            $errors[] = [
                'field'   => 'require_contact_email',
                'message' => 'Lead must have a contact email.',
            ];
        }

        if ($req->require_phone_number && empty($lead->phone)) {
            $errors[] = [
                'field'   => 'require_phone_number',
                'message' => 'Lead must have a phone number.',
            ];
        }

        if ($req->require_at_least_one_product && !$lead->products()->exists()) {
            $errors[] = [
                'field'   => 'require_at_least_one_product',
                'message' => 'Lead must have at least one product of interest.',
            ];
        }

        if ($req->require_deal_value && !$lead->sales()->exists()) {
            $errors[] = [
                'field'   => 'require_deal_value',
                'message' => 'Lead must have at least one deal.',
            ];
        }

        if ($req->require_recent_activity && $lead->updated_at->lt(now()->subDays(3))) {
            $errors[] = [
                'field'   => 'require_recent_activity',
                'message' => 'Lead must have had activity within the last 3 days.',
            ];
        }

        return $errors;
    }

    /**
     * Move a lead to a different funnel (placing it in that funnel's entry stage).
     */
    public function moveToFunnel(Request $request, Lead $lead)
    {
        $user = Auth::user();
        $this->assertLeadAccess($lead, $user);

        $validated = $request->validate([
            'funnel_id' => 'required|exists:funnels,id',
        ]);

        $funnel = \App\Models\Funnel::where('id', $validated['funnel_id'])
            ->where('company_id', $lead->company_id)
            ->firstOrFail();

        if ($funnel->id === $lead->funnel_id) {
            return response()->json([
                'message' => 'Lead is already in this funnel.',
            ], 422);
        }

        $targetStage = Stage::where('funnel_id', $funnel->id)
            ->where('type', 'entry')
            ->orderBy('order')
            ->first();

        if (!$targetStage) {
            return response()->json([
                'message' => 'The selected funnel has no entry stage configured.',
            ], 422);
        }

        $previousStage = $lead->stage;

        // Broadcast removal from the old funnel/stage before the lead moves
        broadcast(new LeadLiveUpdate('deleted', $lead));

        $lead->update([
            'funnel_id' => $funnel->id,
            'stage_id'  => $targetStage->id,
        ]);

        Record::createLeadFunnelChange($lead, $funnel, $previousStage, $targetStage, $user);

        $freshLead = $lead->fresh(['assignedUser']);

        // Broadcast creation into the new funnel/stage
        broadcast(new LeadLiveUpdate('created', $freshLead));

        return response()->json([
            'message' => 'Lead moved to funnel successfully',
            'data'    => $freshLead,
        ]);
    }

    public function recordLostReason(Request $request, Lead $lead)
    {
        $this->assertLeadAccess($lead, Auth::user());

        $validated = $request->validate([
            'lost_reason_id' => 'required|exists:lost_reasons,id',
        ]);

        $user       = Auth::user();
        $lostReason = LostReason::where('id', $validated['lost_reason_id'])
            ->where('company_id', $lead->company_id)
            ->firstOrFail();

        Record::createLeadLost($lead, $lostReason, $user);

        return response()->json(['message' => 'Lost reason recorded successfully']);
    }

    /**
     * Update tags for a specific lead.
     */
    public function updateTags(Request $request, $id)
    {
        $request->validate([
            'tags' => 'required|array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        $user = Auth::user();

        $lead = Lead::fromCompany($user->company_id)->visibleToUser($user)->findOrFail($id);

        $tagIds = Tag::fromCompany($user->company_id)
            ->whereIn('id', $request->input('tags'))
            ->pluck('id');

        $oldTagIds = $lead->tags()->pluck('tags.id')->all();

        $lead->tags()->sync($tagIds);

        $newTagIds = $tagIds->all();
        $addedIds = array_diff($newTagIds, $oldTagIds);
        $removedIds = array_diff($oldTagIds, $newTagIds);

        if (!empty($addedIds)) {
            foreach (Tag::whereIn('id', $addedIds)->get() as $tag) {
                Record::createLeadTagChange($lead, $tag, true, $user);
            }
        }

        if (!empty($removedIds)) {
            foreach (Tag::whereIn('id', $removedIds)->get() as $tag) {
                Record::createLeadTagChange($lead, $tag, false, $user);
            }
        }

        return response()->json([
            'message' => 'Tags updated successfully.',
            'lead' => $lead->load('tags'),
        ], 200);
    }

    public function assignUser(Request $request, Lead $lead)
    {
        $this->assertLeadAccess($lead, Auth::user());

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $oldUser = $lead->assigned_to ? User::find($lead->assigned_to) : null;
        $newUser = User::find($validated['user_id']);

        $lead->assigned_to = $validated['user_id'];
        $lead->save();

        Record::createLeadAssignment($lead, $oldUser, $newUser, Auth::user());

        app(NotificationService::class)->dispatch('lead_assigned', $lead->company_id, [
            'lead_owner_id' => $validated['user_id'],
            'actor_name'    => Auth::user()->name,
            'lead'          => [
                'id'   => $lead->id,
                'name' => trim("{$lead->first_name} {$lead->last_name}"),
            ],
        ]);

        return response()->json([
            'message' => 'User successfully assigned to lead',
            'data' => [
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to,
            ],
        ]);
    }

        public function search(Request $request, $products = false)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $request->validate([
            'q' => 'required|string|min:3',
        ]);

        $query = $request->input('q');

        $leads = Lead::where('company_id', $user->company_id)
            ->where('first_name', 'like', "%{$query}%")
            ->select('id', 'first_name', 'last_name', 'funnel_id')
            ->orderBy('first_name')
            ->limit(20)
            ->get();

        if($products) {
            $leads->load('products:id,name');
        }

        return response()->json($leads);
    }

    public function leadTransactions(Request $request)
    {
        $request->validate([
            'date_range' => 'nullable|in:today,yesterday,7_days,30_days,custom',
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date',
        ]);

        $user      = Auth::user();
        $companyId = $user->company_id;
        [$start, $end] = $this->resolveActivityDateRange($request);

        $query = Record::with([
                'recordable:id,first_name,last_name,email,company_id',
                'user:id,name,email'
            ])
            ->select('id', 'recordable_type', 'recordable_id', 'user_id', 'type', 'source', 'action', 'title', 'description', 'created_at')
            ->where('recordable_type', Record::RECORDABLE_LEAD)
            ->where('company_id', $companyId)
            ->whereHasMorph('recordable', [Lead::class], fn($q) => $q->visibleToUser($user))
            ->whereBetween('created_at', [$start, $end])
            ->latest();

        $total        = $query->count();
        $transactions = $query->limit(self::ACTIVITY_RESULT_CAP)->get();

        return response()->json([
            'transactions' => $transactions,
            'meta' => [
                'total'     => $total,
                'returned'  => $transactions->count(),
                'truncated' => $total > $transactions->count(),
            ],
        ]);
    }

    public function leadTasks()
    {
        $user      = Auth::user();
        $companyId = $user->company_id;

        $tasks = Task::with([
                'lead:id,first_name,last_name,email,company_id',
                'creator:id,name,email',
                'assignee:id,name,email'
            ])
            ->where(function ($query) use ($companyId, $user) {
                $query->whereHas('lead', fn ($q) => $q->where('company_id', $companyId)->visibleToUser($user))
                      ->orWhere(function ($q) use ($companyId, $user) {
                          $q->whereNull('lead_id');
                          if ($user->isAdmin()) {
                              $q->whereHas('creator', fn ($sub) => $sub->where('company_id', $companyId));
                          } else {
                              $ids = $user->visibleUserIds();
                              $q->whereHas('creator', fn ($sub) => $sub->where('company_id', $companyId)->whereIn('id', $ids));
                          }
                      });
            })
            ->latest()
            ->get();

        return response()->json([
            'tasks' => $tasks,
        ]);
    }

    public function addErpBudget(Request $request, Lead $lead)
    {
        $this->assertLeadAccess($lead, Auth::user());

        $request->validate([
            'erp_budget_id' => 'required|integer',
        ]);

        $erpBudgetId = $request->input('erp_budget_id');

        // Decode JSON string into array
        $currentIds = json_decode($lead->erp_budgets_ids, true) ?? [];

        if (!in_array($erpBudgetId, $currentIds)) {
            $currentIds[] = $erpBudgetId;
            $lead->erp_budgets_ids = json_encode($currentIds); // save as JSON string
            $lead->save();
        }

        return response()->json([
            'message' => 'ERP budget ID added successfully',
            'erp_budgets_ids' => $currentIds, // return as array
        ]);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    /**
     * List leads for the authenticated company via API token.
     */
    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $query = Lead::fromCompany($company->id);

        if ($request->has('funnel_id')) {
            $query->where('funnel_id', $request->funnel_id);
        }
        if ($request->has('stage_id')) {
            $query->where('stage_id', $request->stage_id);
        }
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->has('source_platform')) {
            $query->where('source_platform', $request->source_platform);
        }
        if ($request->has('external_id')) {
            $query->where('external_id', $request->external_id);
        }

        $leads = $query
            ->with(['stage:id,name,funnel_id', 'assignedUser:id,name,email'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($leads);
    }

    /**
     * Show a specific lead via API token authentication.
     */
    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $lead = Lead::fromCompany($company->id)
            ->with([
                'stage:id,name,funnel_id,order',
                'stage.funnel:id,name',
                'assignedUser:id,name,email',
                'products:id,name,base_price,type',
                'observations.user:id,name,email',
            ])
            ->find($id);

        if (!$lead) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        return response()->json(['data' => $lead]);
    }

    /**
     * Update a lead via API token authentication.
     */
    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $lead = Lead::fromCompany($company->id)->find($id);

        if (!$lead) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'external_id' => 'nullable|string|max:255',
            'source_platform' => 'nullable|string|max:50',
            'source_type' => 'nullable|string|max:50',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'username' => 'nullable|string|max:100',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'currency' => 'nullable|string|size:3',
            'preferred_contact_method' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'language' => 'nullable|string|max:5',
            'ai_score' => 'nullable|numeric|between:0,100',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'neighborhood' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'state_code' => 'nullable|string|max:10',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
        ]);

        $oldAssignedTo = $lead->assigned_to;

        $lead->update($validated);

        if (array_key_exists('assigned_to', $validated) && $validated['assigned_to'] != $oldAssignedTo) {
            Record::createLeadAssignment(
                $lead,
                $oldAssignedTo ? User::find($oldAssignedTo) : null,
                $validated['assigned_to'] ? User::find($validated['assigned_to']) : null,
                null
            );
        }

        return response()->json([
            'message' => 'Lead updated successfully',
            'data' => $lead->fresh(),
        ]);
    }

    /**
     * Delete a lead via API token authentication.
     */
    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $lead = Lead::fromCompany($company->id)->find($id);

        if (!$lead) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        $lead->delete();

        if ($lead->funnel_id) {
            broadcast(new LeadLiveUpdate('deleted', $lead));
        }

        return response()->json(['message' => 'Lead deleted successfully']);
    }

    /**
     * Store a newly created lead via API token authentication.
     */
    public function apiStore(Request $request)
    {
        // Validate only personal lead data + funnel_id
        $validated = $request->validate([
            'funnel_id' => 'required|exists:funnels,id',
            'assigned_to' => 'nullable|exists:users,id',
            'external_id' => 'nullable|string|max:255',
            'source_platform' => 'nullable|string|max:50',
            'source_type' => 'nullable|string|max:50',
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'username' => 'nullable|string|max:100',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'currency' => 'nullable|string|size:3',
            'preferred_contact_method' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'language' => 'nullable|string|max:5',
            'ai_score' => 'nullable|numeric|between:0,100',
            'notes' => 'nullable|string',
        ]);

        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = \App\Models\Funnel::findOrFail($validated['funnel_id']);
        $stage = $funnel->stages()->where('type', 'entry')->first();

        if (!$stage) {
            return response()->json([
                'message' => 'No entry stage found for this funnel'
            ], 422);
        }

        // 🔍 Check if lead already exists in this company
        $rawPhone     = $validated['phone'] ?? null;
        $phoneDigits  = $rawPhone !== null ? PhoneNormalizer::normalize($rawPhone) : null;
        $phoneVariants = $phoneDigits !== null && $phoneDigits !== ''
            ? PhoneNormalizer::variants($phoneDigits)
            : null;

        $existingLead = Lead::where('company_id', $company->id)
            ->where('first_name', $validated['first_name'] ?? null)
            ->where('last_name', $validated['last_name'] ?? null)
            ->where('email', $validated['email'] ?? null)
            ->when(
                $phoneVariants,
                fn($q) => $q->whereIn('phone', $phoneVariants),
                fn($q) => $q->whereNull('phone'),
            )
            ->first();

        if ($existingLead) {
            return response()->json([
                'message' => 'Duplicate lead detected',
                'data' => $existingLead
            ], 200); // returning 200 since it's not really an error
        }

        $leadData = array_merge($validated, [
            'company_id' => $company->id,
            'stage_id' => $stage->id,
        ]);

        $lead = Lead::create($leadData);

        LeadQueueAssignmentService::assign($lead);

        $apiToken = $request->get('api_token');
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
            'id'         => $lead->id,
            'company_id' => $lead->company_id,
            'funnel_id'  => $lead->funnel_id,
            'stage_id'   => $lead->stage_id,
            'first_name' => $lead->first_name,
            'last_name'  => $lead->last_name,
            'email'      => $lead->email,
            'phone'      => $lead->phone,
            'source'     => $lead->source,
            'created_at' => $lead->created_at?->toISOString(),
        ]);

        FireIntegrationWebhook::dispatch('lead_whatsapp', [
            'phone'      => $lead->phone,
            'name'       => trim("{$lead->first_name} {$lead->last_name}"),
            'email'      => $lead->email,
            'company_id' => $lead->company_id,
            'funnel_id'  => $lead->funnel_id,
            'created_at' => $lead->created_at?->toISOString(),
        ]);

        return response()->json([
            'message' => 'Lead created successfully',
            'data' => $lead
        ], 201);
    }

    private function applyQueueAssignment(Lead $lead): void
    {
        LeadQueueAssignmentService::assign($lead);
    }

    private function assertLeadAccess(Lead $lead, User $user): void
    {
        if ($user->isAdmin()) {
            abort_if($lead->company_id !== $user->company_id, 403, 'Unauthorized.');
            return;
        }

        $ids     = $user->visibleUserIds();
        $allowed = $lead->company_id === $user->company_id
            && (in_array($lead->assigned_to, $ids)
                || ($user->isManager() && is_null($lead->assigned_to)));

        abort_if(!$allowed, 403, 'Unauthorized access to this lead.');
    }

    public function apiMoveStage(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $lead = \App\Models\Lead::where('company_id', $company->id)->find($id);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $validated = $request->validate([
            'stage_id' => 'required|exists:stages,id',
        ]);

        $stage = \App\Models\Stage::find($validated['stage_id']);
        $oldStageId = $lead->stage_id;
        $lead->stage_id = $stage->id;
        $lead->save();

        if ($lead->funnel_id) {
            broadcast(new LeadLiveUpdate('stage_changed', $lead->fresh(), $oldStageId, $stage->type));
        }

        return response()->json(['message' => 'Lead moved to stage successfully', 'data' => $lead->fresh('stage')]);
    }

}
