<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TagController extends Controller
{
    /**
     * Display all tags from the authenticated user's company.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $tags = Tag::where('company_id', $user->company_id)
            // `?scope=lead|deal|task` narrows the list to tags usable on that
            // entity, so a picker asks for what it can actually attach rather
            // than filtering a flat list client-side.
            ->forUsage($request->query('scope'))
            // Counts back the management screen; skipped for pickers, which
            // only need names and colours.
            ->when($request->boolean('with_counts'), fn ($q) => $q->withCount(['leads', 'sales', 'tasks']))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $tags
        ], 200);
    }

    /**
     * Store new tag.
     */
    public function store(Request $request)
    {
        $validated = $request->validate(Tag::validationRules());

        if (Tag::existsForCompany($validated['company_id'], $validated['name'])) {
            return response()->json([
                'message' => 'This tag already exists.'
            ], 422); // Unprocessable Entity
        }

        // A tag scoped to nothing would be invisible in every picker, which
        // reads as a bug rather than a choice. Default to all entities.
        $validated['scopes'] = $validated['scopes'] ?? Tag::SCOPES;

        $tag = Tag::create($validated);

        return response()->json([
            'message' => 'Tag created with success.',
            'data' => $tag
        ], 201);
    }

    /**
     * Update an existing tag's name and/or color.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $tag = Tag::fromCompany($user->company_id)->findOrFail($id);

        $validated = $request->validate(Tag::updateValidationRules());

        if (
            isset($validated['name'])
            && strcasecmp($validated['name'], $tag->name) !== 0
            && Tag::existsForCompany($user->company_id, $validated['name'])
        ) {
            return response()->json([
                'message' => 'This tag already exists.'
            ], 422);
        }

        $tag->update($validated);

        return response()->json([
            'message' => 'Tag updated successfully.',
            'data' => $tag
        ]);
    }

    /**
     * Remove the specified tag.
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $tag = Tag::fromCompany($user->company_id)->findOrFail($id);
        $tag->delete();

        return response()->json(['message' => 'Tag deleted successfully']);
    }
}
