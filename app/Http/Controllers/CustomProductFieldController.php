<?php

namespace App\Http\Controllers;

use App\Models\CustomProductField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomProductFieldController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = CustomProductField::query();

        return response()->json(
            $query->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'field_key'   => 'required|string|max:255',
            'field_type'  => 'required|string|in:text,title,number,boolean,json,date',
            'field_value' => 'nullable',
            'product_id'  => 'nullable|exists:products,id',
            'type'        => 'nullable|in:model,product',
        ]);

        $field = CustomProductField::create([
            'company_id' => $request->user()->company_id,
            'type'       => $validated['type'] ?? 'product',
            'product_id' => $validated['product_id'] ?? null,
            'field_key'  => $validated['field_key'],
            'field_type' => $validated['field_type'],
            'field_value'=> $validated['field_value'] ?? null,
        ]);

        return response()->json($field, 201);
    }


    /**
     * Display the specified resource.
     */
    public function show(CustomProductField $customProductField)
    {
        return response()->json($customProductField);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CustomProductField $customProductField)
    {
        $validated = $request->validate([
            'field_key'   => 'required|string|max:255',
            'field_type'  => 'required|string|in:text,title,number,boolean,json,date',
            'field_value' => 'nullable',
            'product_id'  => 'nullable|exists:products,id',
            'type'        => 'nullable|in:model,product',
        ]);

        $customProductField->update([
            'type'        => $validated['type'] ?? $customProductField->type,
            'product_id'  => $validated['product_id'] ?? $customProductField->product_id,
            'field_key'   => $validated['field_key'],
            'field_type'  => $validated['field_type'],
            'field_value' => $validated['field_value'] ?? null,
        ]);

        return response()->json($customProductField);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CustomProductField $customProductField)
    {
        $customProductField->delete();

        return response()->json([
            'message' => 'Custom product field deleted successfully.'
        ]);
    }
}
