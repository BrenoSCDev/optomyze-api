<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleTransaction;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SaleController extends Controller
{
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
         * 7️⃣ Log creation transaction
         * ----------------------------------------*/
        SaleTransaction::createSaleCreation($sale, $user);

        /** -----------------------------------------
         * 8️⃣ Response
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

    public function destroy(Sale $sale): JsonResponse
    {
        $user = Auth::user();

        // Security check: sale must belong to user's company
        if ($sale->company_id !== $user->company_id) {
            return response()->json([
                'message' => 'Unauthorized action.'
            ], 403);
        }

        DB::transaction(function () use ($sale, $user) {

            // Log deletion before the sale is removed
            SaleTransaction::createSaleDeletion($sale, $user);

            // Delete related sale items
            $sale->items()->delete();

            // Delete related charges
            $sale->charges()->delete();

            // Delete related documents (and files)
            foreach ($sale->docs as $doc) {
                // If files are stored
                if ($doc->storage_path) {
                    Storage::delete($doc->storage_path);
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
        $sale->load([
            'user:id,name,email',
            'lead:id,first_name,last_name,email,phone,job_title,company_id,funnel_id,stage_id',
            'lead.funnel:id,name',
            'lead.stage:id,name,color',
            'lead.leadCompany:id,legal_name,trade_name,logo_path',
            'charges',
            'discounts',
            'items',
            'docs',
            'funnel:id,name',
            'stage:id,name,color',
            'transactions'
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

        $fromStatus = $sale->status;
        $sale->update($updateData);

        SaleTransaction::createStatusChange($sale, $fromStatus, $data['status'], Auth::user());

        return response()->json([
            'message' => 'Sale status updated successfully.',
            'data' => $sale->fresh(),
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

        // Optional: check if stage belongs to same funnel (recommended if applicable)
        $stage = Stage::find($request->stage_id);

        if ($sale->funnel_id && $stage->funnel_id !== $sale->funnel_id) {
            return response()->json([
                'message' => 'Stage does not belong to the same funnel as this sale.'
            ], 422);
        }

        // Capture current stage before moving
        $fromStage = $sale->stage_id ? Stage::find($sale->stage_id) : null;

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

        SaleTransaction::createStageChange($sale, $fromStage, $stage, Auth::user());

        return response()->json([
            'message' => 'Sale moved successfully.',
            'data' => $sale->fresh()
        ]);
    }

    public function dealTransactions()
    {
        $companyId = Auth::user()->company_id;

        $transactions = SaleTransaction::with([
                'sale:id,lead_id,user_id,name,total,created_at',
                'sale.lead:id,first_name,last_name,email,phone',
                'user:id,name,email'
            ])
            ->where('company_id', $companyId)
            ->latest()
            ->get();

        return response()->json([
            'transactions' => $transactions,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:1']);

        $user  = Auth::user();
        $query = $request->input('q');

        $sales = Sale::where('company_id', $user->company_id)
            ->where('name', 'like', "%{$query}%")
            ->select('id', 'name', 'total', 'status')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($sales);
    }
}