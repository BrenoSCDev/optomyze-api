<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Sale;
use App\Models\SaleItem;
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

            // Products (from lead pivot)
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',

            // Commission fields
            'commission_type' => 'nullable|in:percentage,fixed',
            'commission_value' => 'nullable|numeric|min:0',
            'reference_value' => 'nullable|numeric|min:0',

            // Additional charges
            'charges' => 'nullable|array',
            'charges.*.name' => 'required|string',
            'charges.*.type' => 'required|in:percentage,fixed',
            'charges.*.value' => 'required|numeric|min:0',
        ]);

        $user = Auth::user();

        /** -----------------------------------------
         * 1️⃣ Create Sale (base)
         * ----------------------------------------*/
        $sale = Sale::create([
            'company_id' => $user->company_id,
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'pricing_model' => $validated['pricing_model'],
            'commission_type' => $validated['commission_type'] ?? null,
            'commission_value' => $validated['commission_value'] ?? null,
            'reference_value' => $validated['reference_value'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        /** -----------------------------------------
         * 2️⃣ Attach product snapshot (if applicable)
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

        // A) Product or Hybrid → sum items
        if (in_array($sale->pricing_model, ['product', 'hybrid'])) {
            $baseSubtotal += $sale->items->sum('total_price');
        }

        // B) Commission or Hybrid → calculate commission
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
         * 4️⃣ Create additional charges (on subtotal)
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
         * 5️⃣ Final total
         * ----------------------------------------*/
        $finalTotal = $baseSubtotal + $chargesTotal;

        $sale->update([
            'total' => $finalTotal,
        ]);

        /** -----------------------------------------
         * 6️⃣ Return response
         * ----------------------------------------*/
        return response()->json([
            'message' => 'Sale created successfully',
            'sale' => $sale->load([
                'items',
                'charges',
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

        DB::transaction(function () use ($sale) {

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

        $sale->update($updateData);

        return response()->json([
            'message' => 'Sale status updated successfully.',
            'data' => $sale->fresh(),
        ]);
    }


}