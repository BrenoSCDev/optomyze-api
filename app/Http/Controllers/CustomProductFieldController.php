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
            'field_key'     => 'required|string|max:255',
            'field_type'    => 'required|string|in:text,title,number,boolean,json,date',
            'field_value'   => 'nullable',
            'product_id'    => 'nullable|exists:products,id',
            'group_id'      => 'nullable|exists:custom_product_field_groups,id',
            'type'          => 'nullable|in:model,product',
            'icon'          => 'nullable|string|max:100',
            'display_order' => 'integer',
        ]);

        $field = CustomProductField::create([
            'company_id'    => $request->user()->company_id,
            'type'          => $validated['type'] ?? 'product',
            'product_id'    => $validated['product_id'] ?? null,
            'group_id'      => $validated['group_id'] ?? null,
            'field_key'     => $validated['field_key'],
            'field_type'    => $validated['field_type'],
            'field_value'   => $validated['field_value'] ?? null,
            'icon'          => $validated['icon'] ?? null,
            'display_order' => $validated['display_order'] ?? 0,
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
            'field_key'     => 'required|string|max:255',
            'field_type'    => 'required|string|in:text,title,number,boolean,json,date',
            'field_value'   => 'nullable',
            'product_id'    => 'nullable|exists:products,id',
            'group_id'      => 'nullable|exists:custom_product_field_groups,id',
            'type'          => 'nullable|in:model,product',
            'icon'          => 'nullable|string|max:100',
            'display_order' => 'integer',
        ]);

        $customProductField->update([
            'type'          => $validated['type'] ?? $customProductField->type,
            'product_id'    => $validated['product_id'] ?? $customProductField->product_id,
            'group_id'      => array_key_exists('group_id', $validated) ? $validated['group_id'] : $customProductField->group_id,
            'field_key'     => $validated['field_key'],
            'field_type'    => $validated['field_type'],
            'field_value'   => $validated['field_value'] ?? null,
            'icon'          => array_key_exists('icon', $validated) ? $validated['icon'] : $customProductField->icon,
            'display_order' => $validated['display_order'] ?? $customProductField->display_order,
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
