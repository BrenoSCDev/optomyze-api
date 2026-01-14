<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeadProductController extends Controller
{
    public function store(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        // Load all products in one query (important for performance)
        $products = Product::whereIn(
            'id',
            collect($validated['products'])->pluck('id')
        )->get()->keyBy('id');

        $syncData = [];
        $grandTotal = 0;

        foreach ($validated['products'] as $item) {
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
        }

        // Sync pivot data
        $lead->products()->sync($syncData);

        // Reload products with pivot info
        $lead->load([
            'products:id,name,base_price'
        ]);

        return response()->json([
            'message' => 'Products successfully linked to lead',
            'data' => [
                'lead_id' => $lead->id,
                'products' => $lead->products,
                'grand_total' => $grandTotal,
            ],
        ]);
    }
}
