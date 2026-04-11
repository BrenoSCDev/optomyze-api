<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeadProductController extends Controller
{
    public function apiStore(Request $request, int $leadId): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $lead = Lead::where('company_id', $company->id)->find($leadId);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $validated = $request->validate([
            'products'           => 'required|array',
            'products.*.id'      => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($validated, $lead) {
            $products = Product::whereIn(
                'id',
                collect($validated['products'])->pluck('id')
            )->get()->keyBy('id');

            $syncData = [];
            $grandTotal = 0;
            $descriptionParts = [];

            foreach ($validated['products'] as $item) {
                if (!isset($products[$item['id']])) {
                    abort(400, "Invalid product ID: {$item['id']}");
                }

                $product = $products[$item['id']];
                $unitPrice = $product->base_price;
                $quantity = $item['quantity'];
                $totalPrice = $unitPrice * $quantity;

                $syncData[$product->id] = [
                    'quantity'    => $quantity,
                    'unit_price'  => $unitPrice,
                    'total_price' => $totalPrice,
                ];

                $grandTotal += $totalPrice;
                $descriptionParts[] = "{$product->name} ({$quantity}x)";
            }

            $lead->products()->sync($syncData);

            $lead->load('products:id,name,base_price');

            return response()->json([
                'message' => 'Products successfully linked to lead',
                'data' => [
                    'lead_id'     => $lead->id,
                    'products'    => $lead->products,
                    'grand_total' => $grandTotal,
                ],
            ]);
        });
    }

    public function store(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($validated, $lead) {

            $products = Product::whereIn(
                'id',
                collect($validated['products'])->pluck('id')
            )->get()->keyBy('id');

            $syncData = [];
            $grandTotal = 0;
            $descriptionParts = [];

            foreach ($validated['products'] as $item) {

                if (!isset($products[$item['id']])) {
                    abort(400, "Invalid product ID: {$item['id']}");
                }

                $product = $products[$item['id']];
                $unitPrice = $product->base_price;
                $quantity = $item['quantity'];
                $totalPrice = $unitPrice * $quantity;

                $syncData[$product->id] = [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ];

                $grandTotal += $totalPrice;

                $descriptionParts[] = "{$product->name} ({$quantity}x)";
            }

            $lead->products()->sync($syncData);

            // Create Lead Transaction
            $lead->transactions()->create([
                'company_id' => $lead->company_id,
                'user_id' => Auth::user()->id,
                'type' => 'product_update',
                'action' => 'products_linked',
                'description' => Auth::user()->name . ' updated the products of interest linked to the lead: ' .
                    implode(', ', $descriptionParts) .
                    ' — Total: ' . number_format($grandTotal, 2, ',', '.'),
            ]);

            $lead->load('products:id,name,base_price');

            return response()->json([
                'message' => 'Products successfully linked to lead',
                'data' => [
                    'lead_id' => $lead->id,
                    'products' => $lead->products,
                    'grand_total' => $grandTotal,
                ],
            ]);
        });
    }
}
