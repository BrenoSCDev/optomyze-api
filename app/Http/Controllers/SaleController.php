<?php

namespace App\Http\Controllers;

use App\Events\DealLiveUpdate;
use App\Jobs\FireIntegrationWebhook;
use App\Models\Lead;
use App\Models\Sale;
use App\Services\Storage\CompanyStorage;
use App\Models\SaleItem;
use App\Models\Record;
use App\Models\Stage;
use App\Models\Tag;
use App\Models\User;
use App\Services\NotificationService;
use App\Traits\ResolvesActivityDateRange;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SaleController extends Controller
{
    use ResolvesActivityDateRange;

    public function store(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            // Core sale data
            'pricing_model' => 'required|in:product,commission,hybrid',
            'notes' => 'nullable|string',
            'name' => 'required',

            // Products
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',

            // Commission
            'commission_type' => 'nullable|in:percentage,fixed',
            'commission_value' => 'nullable|numeric|min:0',
            'reference_value' => 'nullable|numeric|min:0',

            // Charges
            'charges' => 'nullable|array',
            'charges.*.name' => 'required|string',
            'charges.*.type' => 'required|in:percentage,fixed',
            'charges.*.value' => 'required|numeric|min:0',

            // Discounts
            'discounts' => 'nullable|array',
            'discounts.*.name' => 'required|string',
            'discounts.*.type' => 'required|in:percentage,fixed',
            'discounts.*.value' => 'required|numeric|min:0',

            // Manual value (when no products)
            'manual_value' => 'nullable|numeric|min:0',

            // Funnel & Stage
            'funnel_id' => 'exists:funnels,id',
            'stage_id' => 'exists:stages,id',
        ]);

        $user = Auth::user();

        /** -----------------------------------------
         * 1️⃣ Create Sale
         * ----------------------------------------*/
        $sale = Sale::create([
            'company_id' => $user->company_id,
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'funnel_id' => $validated['funnel_id'] ?? null,
            'stage_id' => $validated['stage_id'] ?? null,
            'name' => $validated['name'],
            'status' => 'pending',
            'pricing_model' => $validated['pricing_model'],
            'commission_type' => $validated['commission_type'] ?? null,
            'commission_value' => $validated['commission_value'] ?? null,
            'reference_value' => $validated['reference_value'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        /** -----------------------------------------
         * 2️⃣ Attach product snapshot
         * ----------------------------------------*/
        if (!empty($validated['product_ids'])) {
            $products = $lead->products()
                ->whereIn('products.id', $validated['product_ids'])
                ->get();

            foreach ($products as $product) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => (int) $product->pivot->quantity,
                    'unit_price' => (float) $product->pivot->unit_price,
                    'total_price' => (float) $product->pivot->total_price,
                ]);
            }
        }

        $sale->load('items');

        /** -----------------------------------------
         * 3️⃣ Calculate BASE subtotal
         * ----------------------------------------*/
        $baseSubtotal = 0;

        // Products
        if (in_array($sale->pricing_model, ['product', 'hybrid'])) {
            $baseSubtotal += $sale->items->sum('total_price');
            $baseSubtotal += $validated['manual_value'] ?? 0;
        }

        // Commission
        if (in_array($sale->pricing_model, ['commission', 'hybrid'])) {

            if (!$sale->commission_type || !$sale->commission_value) {
                abort(422, 'Commission data is required for this pricing model.');
            }

            $reference = $sale->reference_value ?? $baseSubtotal;

            $commissionAmount = $sale->commission_type === 'percentage'
                ? $reference * ($sale->commission_value / 100)
                : $sale->commission_value;

            $baseSubtotal += $commissionAmount;
        }

        $sale->update([
            'subtotal' => $baseSubtotal,
        ]);

        /** -----------------------------------------
         * 4️⃣ Charges (ADD on base subtotal)
         * ----------------------------------------*/
        $chargesTotal = 0;

        if (!empty($validated['charges'])) {
            foreach ($validated['charges'] as $chargeData) {

                $calculatedAmount = $chargeData['type'] === 'percentage'
                    ? $baseSubtotal * ($chargeData['value'] / 100)
                    : $chargeData['value'];

                $chargesTotal += $calculatedAmount;

                $sale->charges()->create([
                    'name' => $chargeData['name'],
                    'type' => $chargeData['type'],
                    'value' => $chargeData['value'],
                    'calculated_amount' => $calculatedAmount,
                ]);
            }
        }

        /** -----------------------------------------
         * 5️⃣ Discounts (SUBTRACT on base subtotal)
         * ----------------------------------------*/
        $discountsTotal = 0;

        if (!empty($validated['discounts'])) {
            foreach ($validated['discounts'] as $discountData) {

                $calculatedAmount = $discountData['type'] === 'percentage'
                    ? $baseSubtotal * ($discountData['value'] / 100)
                    : $discountData['value'];

                $discountsTotal += $calculatedAmount;

                $sale->discounts()->create([
                    'name' => $discountData['name'],
                    'type' => $discountData['type'],
                    'value' => $discountData['value'],
                    'calculated_amount' => $calculatedAmount,
                ]);
            }
        }

        /** -----------------------------------------
         * 6️⃣ Final total
         * ----------------------------------------*/
        $finalTotal = max(
            0,
            $baseSubtotal + $chargesTotal - $discountsTotal
        );

        $sale->update([
            'discount_total' => $discountsTotal,
            'total' => $finalTotal,
        ]);

        /** -----------------------------------------
         * 7️⃣ Log creation record
         * ----------------------------------------*/
        Record::createDealCreation($sale, $user);

        /** -----------------------------------------
         * 8️⃣ Notify — deal_created
         * ----------------------------------------*/
        app(NotificationService::class)->dispatch('deal_created', $sale->company_id, [
            'deal_owner_id' => $sale->user_id,
            'lead_owner_id' => $lead->assigned_to,
            'actor_name'    => $user->name,
            'sale'          => ['id' => $sale->id, 'name' => $sale->name, 'total' => $finalTotal],
            'lead'          => [
                'id'   => $lead->id,
                'name' => trim("{$lead->first_name} {$lead->last_name}"),
            ],
        ]);

        /** -----------------------------------------
         * 9️⃣ Broadcast + Integration Webhooks
         * ----------------------------------------*/
        if ($sale->funnel_id) {
            broadcast(new DealLiveUpdate('created', $sale));
        }

        FireIntegrationWebhook::dispatch('deal_created', [
            'id'            => $sale->id,
            'company_id'    => $sale->company_id,
            'lead_id'       => $sale->lead_id,
            'funnel_id'     => $sale->funnel_id,
            'stage_id'      => $sale->stage_id,
            'name'          => $sale->name,
            'status'        => $sale->status,
            'pricing_model' => $sale->pricing_model,
            'total'         => $sale->total,
            'created_at'    => $sale->created_at?->toISOString(),
        ]);

        /** -----------------------------------------
         * 🔟 Response
         * ----------------------------------------*/
        return response()->json([
            'message' => 'Sale created successfully',
            'sale' => $sale->load([
                'items',
                'charges',
                'discounts',
                'lead:id,first_name,last_name',
                'user:id,name,email',
            ]),
        ], 201);
    }

    public function update(Request $request, Sale $sale): JsonResponse
    {
        $user = Auth::user();

        $this->assertSaleAccess($sale, $user);

        $validated = $request->validate([
            'name'             => 'sometimes|required|string|max:255',
            'notes'            => 'nullable|string',
            'stage_id'         => 'sometimes|exists:stages,id',
            'pricing_model'    => 'sometimes|in:product,commission,hybrid',
            'commission_type'  => 'nullable|in:percentage,fixed',
            'commission_value' => 'nullable|numeric|min:0',
            'reference_value'  => 'nullable|numeric|min:0',
            'charges'          => 'nullable|array',
            'charges.*.name'   => 'required|string',
            'charges.*.type'   => 'required|in:percentage,fixed',
            'charges.*.value'  => 'required|numeric|min:0',
            'discounts'          => 'nullable|array',
            'discounts.*.name'   => 'required|string',
            'discounts.*.type'   => 'required|in:percentage,fixed',
            'discounts.*.value'  => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($sale, $validated, $user) {

            // Update basic scalar fields
            $scalarUpdate = array_filter([
                'name'             => $validated['name'] ?? null,
                'stage_id'         => $validated['stage_id'] ?? null,
                'pricing_model'    => $validated['pricing_model'] ?? null,
                'commission_type'  => $validated['commission_type'] ?? null,
                'commission_value' => $validated['commission_value'] ?? null,
                'reference_value'  => $validated['reference_value'] ?? null,
            ], fn ($v) => $v !== null);

            // notes can explicitly be null
            if (array_key_exists('notes', $validated)) {
                $scalarUpdate['notes'] = $validated['notes'];
            }

            if (!empty($scalarUpdate)) {
                $sale->update($scalarUpdate);
            }

            // Re-sync charges if provided
            if (array_key_exists('charges', $validated)) {
                $sale->charges()->delete();
                foreach ($validated['charges'] ?? [] as $c) {
                    $calculated = $c['type'] === 'percentage'
                        ? $sale->subtotal * ($c['value'] / 100)
                        : $c['value'];
                    $sale->charges()->create([
                        'name'              => $c['name'],
                        'type'              => $c['type'],
                        'value'             => $c['value'],
                        'calculated_amount' => $calculated,
                    ]);
                }
            }

            // Re-sync discounts if provided
            if (array_key_exists('discounts', $validated)) {
                $sale->discounts()->delete();
                foreach ($validated['discounts'] ?? [] as $d) {
                    $calculated = $d['type'] === 'percentage'
                        ? $sale->subtotal * ($d['value'] / 100)
                        : $d['value'];
                    $sale->discounts()->create([
                        'name'              => $d['name'],
                        'type'              => $d['type'],
                        'value'             => $d['value'],
                        'calculated_amount' => $calculated,
                    ]);
                }
            }

            // Recalculate totals
            $sale->load('charges', 'discounts');
            $chargesTotal   = $sale->charges->sum('calculated_amount');
            $discountsTotal = $sale->discounts->sum('calculated_amount');
            $finalTotal     = max(0, $sale->subtotal + $chargesTotal - $discountsTotal);

            $sale->update([
                'discount_total' => $discountsTotal,
                'total'          => $finalTotal,
            ]);

            // Log update record
            Record::createDealUpdate($sale, $user);

            return response()->json([
                'message' => 'Sale updated successfully.',
                'sale'    => $sale->fresh(['items', 'charges', 'discounts', 'lead:id,first_name,last_name', 'user:id,name,email', 'funnel:id,name', 'stage:id,name,color']),
            ]);
        });
    }

    /**
     * Update tags for a specific deal (sale).
     */
    public function updateTags(Request $request, Sale $sale): JsonResponse
    {
        $user = Auth::user();

        $this->assertSaleAccess($sale, $user);

        $request->validate([
            'tags' => 'required|array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        $tagIds = Tag::fromCompany($user->company_id)
            ->whereIn('id', $request->input('tags'))
            ->pluck('id');

        $oldTagIds = $sale->tags()->pluck('tags.id')->all();

        $sale->tags()->sync($tagIds);

        $newTagIds = $tagIds->all();
        $addedIds = array_diff($newTagIds, $oldTagIds);
        $removedIds = array_diff($oldTagIds, $newTagIds);

        if (!empty($addedIds)) {
            foreach (Tag::whereIn('id', $addedIds)->get() as $tag) {
                Record::createDealTagChange($sale, $tag, true, $user);
            }
        }

        if (!empty($removedIds)) {
            foreach (Tag::whereIn('id', $removedIds)->get() as $tag) {
                Record::createDealTagChange($sale, $tag, false, $user);
            }
        }

        return response()->json([
            'message' => 'Tags updated successfully.',
            'sale' => $sale->load('tags'),
        ]);
    }

    /**
     * Assign (or reassign) the user responsible for a deal.
     */
    public function assignUser(Request $request, Sale $sale): JsonResponse
    {
        $user = Auth::user();

        $this->assertSaleAccess($sale, $user);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $oldUser = $sale->user_id ? User::find($sale->user_id) : null;
        $newUser = User::find($validated['user_id']);

        $sale->user_id = $validated['user_id'];
        $sale->save();

        Record::createDealAssignment($sale, $oldUser, $newUser, $user);

        return response()->json([
            'message' => 'User successfully assigned to deal',
            'sale' => $sale->fresh(['user:id,name,email']),
        ]);
    }

    /**
     * Archive or restore a deal.
     *
     * Archived deals keep every relation and stay in their stage; they are
     * simply hidden from the board and search. This is the reversible
     * counterpart to `destroy()`, which cascades and cannot be undone.
     */
    public function setArchived(Request $request, Sale $sale): JsonResponse
    {
        $user = Auth::user();

        $this->assertSaleAccess($sale, $user);

        $validated = $request->validate([
            'archived' => 'required|boolean',
        ]);

        $archived = $validated['archived'];

        if ($sale->is_active === !$archived) {
            return response()->json([
                'message' => 'Deal is already in this state.',
                'sale'    => $sale,
            ]);
        }

        $sale->update(['is_active' => !$archived]);

        // The board hides archived deals, so from every other client's point of
        // view the card has gone — and comes back when it is restored.
        broadcast(new DealLiveUpdate($archived ? 'deleted' : 'created', $sale->fresh()));

        return response()->json([
            'message'  => $archived ? 'Deal archived successfully' : 'Deal restored successfully',
            'archived' => $archived,
            'sale'     => $sale->fresh(['user:id,name,email']),
        ]);
    }

    /**
     * Move a deal to a different deal funnel, landing on that funnel's entry
     * stage. Mirrors LeadController::moveToFunnel.
     */
    public function moveToFunnel(Request $request, Sale $sale): JsonResponse
    {
        $user = Auth::user();

        $this->assertSaleAccess($sale, $user);

        $validated = $request->validate([
            'funnel_id' => 'required|integer',
        ]);

        // Scoped to the company *and* to deal funnels: a deal on a lead funnel
        // would sit in stages the deal board never renders.
        $funnel = \App\Models\Funnel::where('id', $validated['funnel_id'])
            ->where('company_id', $sale->company_id)
            ->where('type', 'deal')
            ->first();

        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found in this company'], 422);
        }

        if ((int) $funnel->id === (int) $sale->funnel_id) {
            return response()->json(['message' => 'Deal is already in this funnel.'], 422);
        }

        $entryStage = Stage::where('funnel_id', $funnel->id)
            ->where('type', 'entry')
            ->orderBy('order')
            ->first();

        if (!$entryStage) {
            return response()->json([
                'message' => 'The selected funnel has no entry stage configured.',
            ], 422);
        }

        $previousStage = $sale->stage_id ? Stage::find($sale->stage_id) : null;

        // Tell the old board the card has gone before it moves.
        broadcast(new DealLiveUpdate('deleted', $sale));

        $sale->update([
            'funnel_id' => $funnel->id,
            'stage_id'  => $entryStage->id,
        ]);

        Record::createDealStageChange($sale, $previousStage, $entryStage, $user);

        $fresh = $sale->fresh();

        broadcast(new DealLiveUpdate('created', $fresh));

        return response()->json([
            'message' => 'Deal moved to funnel successfully',
            'sale'    => $fresh,
        ]);
    }

    public function destroy(Sale $sale): JsonResponse
    {
        $user = Auth::user();

        $this->assertSaleAccess($sale, $user);

        DB::transaction(function () use ($sale, $user) {

            // Log deletion before the sale is removed
            Record::createDealDeletion($sale, $user);

            // Delete related sale items
            $sale->items()->delete();

            // Delete related charges
            $sale->charges()->delete();

            // Delete related documents (and files)
            foreach ($sale->docs as $doc) {
                // If files are stored
                if ($doc->storage_path) {
                    Storage::delete($doc->storage_path);
                    CompanyStorage::forget($doc->storage_path);
                }
                $doc->delete();
            }

            // Finally delete the sale
            $sale->delete();
        });

        return response()->json([
            'message' => 'Sale deleted successfully',
            'sale_id' => $sale->id
        ]);
    }

    /**
     * Display a specific deal.
     */
    public function show(Sale $sale)
    {
        $this->assertSaleAccess($sale, Auth::user());

        $sale->load([
            'user:id,name,email,profile_pic',
            // `timezone` and the inbox chat are what the deal view's
            // Appointments and Conversation tabs need — both act on the person
            // behind the deal, so they reuse the lead components.
            'lead:id,first_name,last_name,email,phone,job_title,timezone,company_id,funnel_id,stage_id',
            'lead.funnel:id,name',
            'lead.stage:id,name,color',
            'lead.leadCompany:id,legal_name,trade_name,logo_path',
            'lead.whatsappInboxChat.messages' => fn ($q) => $q->orderBy('sent_at'),
            'lead.whatsappInboxChat.channelable',
            'charges',
            'discounts',
            'items',
            'docs',
            'funnel:id,name',
            'stage:id,name,color',
            'records' => function ($query) {
                $query->latest();
            },
            'records.user:id,name,email',
        ]);

        $user = Auth::user();

        $users = User::fromCompany($user->company_id)
            ->select('id', 'name', 'email')
            ->get();

        return response()->json([
            'sale' => $sale,
            'users' => $users,
        ]);
    }

    /**
     * Mark a pending sale as closed or lost
     */
    public function updateStatus(Request $request, Sale $sale)
    {
        $this->assertSaleAccess($sale, Auth::user());

        $data = $request->validate([
            'status' => ['required', 'in:closed,lost'],
        ]);

        // Only allow status change from pending or sent
        if (!in_array($sale->status, ['pending', 'sent'])) {
            return response()->json([
                'message' => 'Only pending or sent sales can be updated.',
            ], 422);
        }

        $updateData = [
            'status' => $data['status'],
        ];

        // Handle timestamps based on new status
        if ($data['status'] === 'closed') {
            $updateData['closed_at'] = now();
            $updateData['lost_at'] = null; // ensure consistency
        }

        if ($data['status'] === 'lost') {
            $updateData['lost_at'] = now();
            $updateData['closed_at'] = null; // ensure consistency
        }

        // Move to the matching won/lost stage in the funnel
        if ($sale->funnel_id) {
            $targetStageType = $data['status'] === 'closed' ? 'won' : 'lost';
            $targetStage = Stage::where('funnel_id', $sale->funnel_id)
                ->where('type', $targetStageType)
                ->first();
            if ($targetStage) {
                $updateData['stage_id'] = $targetStage->id;
            }
        }

        $fromStatus = $sale->status;
        $sale->update($updateData);

        Record::createDealStatusChange($sale, $fromStatus, $data['status'], Auth::user());

        $notifEvent = $data['status'] === 'closed' ? 'deal_won' : 'deal_lost';
        $notifLead  = $sale->lead;
        app(NotificationService::class)->dispatch($notifEvent, $sale->company_id, [
            'deal_owner_id' => $sale->user_id,
            'lead_owner_id' => $notifLead?->assigned_to,
            'actor_name'    => Auth::user()->name,
            'sale'          => ['id' => $sale->id, 'name' => $sale->name, 'total' => $sale->total],
            'lead'          => $notifLead ? [
                'id'   => $notifLead->id,
                'name' => trim("{$notifLead->first_name} {$notifLead->last_name}"),
            ] : [],
        ]);

        return response()->json([
            'message' => 'Sale status updated successfully.',
            'data' => $sale->fresh(['stage']),
        ]);
    }

    public function moveToStage(Request $request, $saleId)
    {
        // Validate request
        $request->validate([
            'stage_id' => 'required|exists:stages,id',
        ]);

        // Find sale
        $sale = Sale::find($saleId);

        if (!$sale) {
            return response()->json([
                'message' => 'Sale not found.'
            ], 404);
        }

        $this->assertSaleAccess($sale, Auth::user());

        // Optional: check if stage belongs to same funnel (recommended if applicable)
        $stage = Stage::find($request->stage_id);

        if ($sale->funnel_id && $stage->funnel_id !== $sale->funnel_id) {
            return response()->json([
                'message' => 'Stage does not belong to the same funnel as this sale.'
            ], 422);
        }

        // Capture current stage before moving
        $fromStage  = $sale->stage_id ? Stage::find($sale->stage_id) : null;
        $oldStageId = $sale->stage_id;

        // Update stage
        $sale->stage_id = $request->stage_id;

        if ($stage->type === 'won') {
            $sale->status = 'closed';
            $sale->closed_at = now();
        } elseif ($stage->type === 'lost') {
            $sale->status = 'lost';
            $sale->lost_at = now();
        }

        $sale->save();

        Record::createDealStageChange($sale, $fromStage, $stage, Auth::user());

        $stageNotifEvent = match (true) {
            $stage->type === 'won'  => 'deal_won',
            $stage->type === 'lost' => 'deal_lost',
            default                 => 'deal_stage_changed',
        };
        $stageLead = $sale->fresh()->lead;
        app(NotificationService::class)->dispatch($stageNotifEvent, $sale->company_id, [
            'deal_owner_id' => $sale->user_id,
            'lead_owner_id' => $stageLead?->assigned_to,
            'actor_name'    => Auth::user()->name,
            'stage_name'    => $stage->name,
            'sale'          => ['id' => $sale->id, 'name' => $sale->name, 'total' => $sale->total],
            'lead'          => $stageLead ? [
                'id'   => $stageLead->id,
                'name' => trim("{$stageLead->first_name} {$stageLead->last_name}"),
            ] : [],
        ]);

        // Broadcast stage change live to deal-funnel channel
        if ($sale->funnel_id) {
            broadcast(new DealLiveUpdate(
                'stage_changed',
                $sale,
                $oldStageId,
                $stage->type
            ));
        }

        return response()->json([
            'message' => 'Sale moved successfully.',
            'data' => $sale->fresh()
        ]);
    }

    public function dealTransactions(Request $request)
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
                'recordable:id,lead_id,user_id,name,total,created_at',
                'recordable.lead:id,first_name,last_name,email,phone',
                'user:id,name,email'
            ])
            ->where('recordable_type', Record::RECORDABLE_SALE)
            ->where('company_id', $companyId)
            ->whereHasMorph('recordable', [Sale::class], fn($q) => $q->visibleToUser($user))
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

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:1']);

        $user  = Auth::user();
        $query = $request->input('q');

        $sales = Sale::where('company_id', $user->company_id)
            ->visibleToUser($user)
            ->notArchived()
            ->where('name', 'like', "%{$query}%")
            ->select('id', 'name', 'total', 'status')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($sales);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $query = Sale::where('company_id', $company->id);

        if ($request->has('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('funnel_id')) {
            $query->where('funnel_id', $request->funnel_id);
        }

        $sales = $query
            ->with(['lead:id,first_name,last_name,email', 'user:id,name,email', 'stage:id,name,color'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($sales);
    }

    public function apiShow(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $sale = Sale::where('company_id', $company->id)
            ->with(['lead:id,first_name,last_name,email,phone', 'user:id,name,email', 'items', 'charges', 'discounts', 'stage:id,name,color', 'funnel:id,name'])
            ->find($id);

        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        return response()->json(['data' => $sale]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'lead_id'          => 'required|exists:leads,id',
            'name'             => 'required|string|max:255',
            'pricing_model'    => 'required|in:product,commission,hybrid',
            'notes'            => 'nullable|string',
            'user_id'          => 'nullable|exists:users,id',
            'funnel_id'        => 'nullable|exists:funnels,id',
            'stage_id'         => 'nullable|exists:stages,id',
            'commission_type'  => 'nullable|in:percentage,fixed',
            'commission_value' => 'nullable|numeric|min:0',
            'reference_value'  => 'nullable|numeric|min:0',
            'items'              => 'nullable|array',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'charges'            => 'nullable|array',
            'charges.*.name'     => 'required|string',
            'charges.*.type'     => 'required|in:percentage,fixed',
            'charges.*.value'    => 'required|numeric|min:0',
            'discounts'          => 'nullable|array',
            'discounts.*.name'   => 'required|string',
            'discounts.*.type'   => 'required|in:percentage,fixed',
            'discounts.*.value'  => 'required|numeric|min:0',
        ]);

        $lead = Lead::where('id', $validated['lead_id'])->where('company_id', $company->id)->first();
        if (!$lead) {
            return response()->json(['message' => 'Lead not found or does not belong to this company'], 404);
        }

        $sale = Sale::create([
            'company_id'       => $company->id,
            'lead_id'          => $lead->id,
            'user_id'          => $validated['user_id'] ?? null,
            'funnel_id'        => $validated['funnel_id'] ?? null,
            'stage_id'         => $validated['stage_id'] ?? null,
            'name'             => $validated['name'],
            'status'           => 'pending',
            'pricing_model'    => $validated['pricing_model'],
            'commission_type'  => $validated['commission_type'] ?? null,
            'commission_value' => $validated['commission_value'] ?? null,
            'reference_value'  => $validated['reference_value'] ?? null,
            'notes'            => $validated['notes'] ?? null,
        ]);

        $baseSubtotal = 0;

        if (!empty($validated['items'])) {
            foreach ($validated['items'] as $item) {
                $totalPrice = $item['unit_price'] * $item['quantity'];
                $baseSubtotal += $totalPrice;
                SaleItem::create([
                    'sale_id'      => $sale->id,
                    'product_id'   => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'total_price'  => $totalPrice,
                ]);
            }
        }

        if (in_array($sale->pricing_model, ['commission', 'hybrid'])) {
            $reference = $validated['reference_value'] ?? $baseSubtotal;
            $commissionAmount = ($validated['commission_type'] ?? null) === 'percentage'
                ? $reference * (($validated['commission_value'] ?? 0) / 100)
                : ($validated['commission_value'] ?? 0);
            $baseSubtotal += $commissionAmount;
        }

        $sale->update(['subtotal' => $baseSubtotal]);

        $chargesTotal = 0;
        if (!empty($validated['charges'])) {
            foreach ($validated['charges'] as $chargeData) {
                $calculatedAmount = $chargeData['type'] === 'percentage'
                    ? $baseSubtotal * ($chargeData['value'] / 100)
                    : $chargeData['value'];
                $chargesTotal += $calculatedAmount;
                $sale->charges()->create([
                    'name'              => $chargeData['name'],
                    'type'              => $chargeData['type'],
                    'value'             => $chargeData['value'],
                    'calculated_amount' => $calculatedAmount,
                ]);
            }
        }

        $discountsTotal = 0;
        if (!empty($validated['discounts'])) {
            foreach ($validated['discounts'] as $discountData) {
                $calculatedAmount = $discountData['type'] === 'percentage'
                    ? $baseSubtotal * ($discountData['value'] / 100)
                    : $discountData['value'];
                $discountsTotal += $calculatedAmount;
                $sale->discounts()->create([
                    'name'              => $discountData['name'],
                    'type'              => $discountData['type'],
                    'value'             => $discountData['value'],
                    'calculated_amount' => $calculatedAmount,
                ]);
            }
        }

        $sale->update([
            'discount_total' => $discountsTotal,
            'total'          => max(0, $baseSubtotal + $chargesTotal - $discountsTotal),
        ]);

        if ($sale->funnel_id) {
            broadcast(new DealLiveUpdate('created', $sale));
        }

        FireIntegrationWebhook::dispatch('deal_created', [
            'id'            => $sale->id,
            'company_id'    => $sale->company_id,
            'lead_id'       => $sale->lead_id,
            'funnel_id'     => $sale->funnel_id,
            'stage_id'      => $sale->stage_id,
            'name'          => $sale->name,
            'status'        => $sale->status,
            'pricing_model' => $sale->pricing_model,
            'total'         => $sale->total,
            'created_at'    => $sale->created_at?->toISOString(),
        ]);

        return response()->json([
            'message' => 'Sale created successfully',
            'data'    => $sale->load(['items', 'charges', 'discounts']),
        ], 201);
    }

    public function apiUpdate(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $sale = Sale::where('company_id', $company->id)->find($id);
        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $validated = $request->validate([
            'name'      => 'nullable|string|max:255',
            'notes'     => 'nullable|string',
            'status'    => 'nullable|in:pending,sent,closed,lost',
            'user_id'   => 'nullable|exists:users,id',
            'funnel_id' => 'nullable|exists:funnels,id',
            'stage_id'  => 'nullable|exists:stages,id',
        ]);

        $oldUserId = $sale->user_id;

        $sale->update($validated);

        if (array_key_exists('user_id', $validated) && $validated['user_id'] != $oldUserId) {
            Record::createDealAssignment(
                $sale,
                $oldUserId ? User::find($oldUserId) : null,
                $validated['user_id'] ? User::find($validated['user_id']) : null,
                null
            );
        }

        return response()->json([
            'message' => 'Sale updated successfully',
            'data'    => $sale->fresh(),
        ]);
    }

    public function apiDestroy(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $sale = Sale::where('company_id', $company->id)->find($id);
        if (!$sale) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        $hadFunnel = (bool) $sale->funnel_id;

        DB::transaction(function () use ($sale) {
            $sale->items()->delete();
            $sale->charges()->delete();
            foreach ($sale->docs as $doc) {
                if ($doc->storage_path) {
                    Storage::delete($doc->storage_path);
                    CompanyStorage::forget($doc->storage_path);
                }
                $doc->delete();
            }
            $sale->delete();
        });

        if ($hadFunnel) {
            broadcast(new DealLiveUpdate('deleted', $sale));
        }

        return response()->json(['message' => 'Sale deleted successfully']);
    }

    private function assertSaleAccess(Sale $sale, User $user): void
    {
        if ($user->isAdmin()) {
            abort_if($sale->company_id !== $user->company_id, 403, 'Unauthorized.');
            return;
        }

        $ids     = $user->visibleUserIds();
        $allowed = $sale->company_id === $user->company_id
            && (in_array($sale->user_id, $ids)
                || ($user->isManager() && is_null($sale->user_id)));

        abort_if(!$allowed, 403, 'Unauthorized access to this deal.');
    }
}