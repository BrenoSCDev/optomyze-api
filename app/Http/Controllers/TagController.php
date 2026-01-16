<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TagController extends Controller
{
    /**
     * Display all tags from the authenticated user's company.
     */
    public function index(Request $request)
    {
        // Get the authenticated user via Sanctum
        $user = Auth::user();

        // Assuming user has company_id column
        $tags = Tag::where('company_id', $user->company_id)->get();

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

        $tag = Tag::create($validated);

        return response()->json([
            'message' => 'Tag created with success.',
            'data' => $tag
        ], 201);
    }

    /**
     * Remove the specified tag.
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $tag = Tag::fromCompany($user->company_id)->findOrFail($id);

        DB::beginTransaction();
        try {
            $leads = Lead::where('company_id', $user->company_id)
                ->whereNotNull('tags')
                ->get();

            foreach ($leads as $lead) {
                $tags = $lead->tags ?? [];

                if (!is_array($tags) || count($tags) === 0) {
                    continue;
                }

                $originalCount = count($tags);

                $filtered = array_filter($tags, function ($t) use ($tag) {
                    if (is_string($t)) {
                        $tName = $t;
                    } elseif (is_array($t)) {
                        $tName = $t['name'] ?? null;
                    } elseif (is_object($t)) {
                        $tName = $t->name ?? null;
                    } else {
                        $tName = null;
                    }

                    if ($tName === null) {
                        return true;
                    }

                    return strcasecmp($tName, $tag->name) !== 0;
                });

                if (count($filtered) !== $originalCount) {
                    $lead->tags = array_values($filtered);
                    $lead->save();
                }
            }

            $tag->delete();
            DB::commit();

            return response()->json(['message' => 'Tag deleted successfully']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao deletar tag',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
