<?php

namespace App\Http\Controllers;

use App\Models\ClosingReason;
use App\Services\ChatSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClosingReasonController extends Controller
{
    /**
     * List closing reasons. By default returns only active reasons with
     * minimal fields (for the End Conversation picker). Pass `?all=1` for
     * the full management list (incl. disabled reasons + outcome/template).
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        ChatSessionService::ensureDefaultClosingReasons($user->company_id);

        $query = ClosingReason::where('company_id', $user->company_id)->ordered();

        if ($request->boolean('all')) {
            $reasons = $query->with('closingMessages:id,name')
                ->get(['id', 'name', 'description', 'is_active', 'sort_order', 'outcome']);
        } else {
            $reasons = $query->where('is_active', true)->get(['id', 'name', 'description']);
        }

        return response()->json(['data' => $reasons], 200);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $this->validateReason($request);
        $data['company_id'] = $user->company_id;
        $data['sort_order'] = $data['sort_order'] ?? (ClosingReason::where('company_id', $user->company_id)->max('sort_order') + 1);

        $reason = ClosingReason::create($data);

        return response()->json(['data' => $reason], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reason = ClosingReason::where('id', $id)->where('company_id', $user->company_id)->firstOrFail();

        $reason->update($this->validateReason($request, partial: true));

        return response()->json(['data' => $reason], 200);
    }

    public function destroy(Request $request, $id)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reason = ClosingReason::where('id', $id)->where('company_id', $user->company_id)->firstOrFail();
        $reason->delete();

        return response()->json(['message' => 'Deleted'], 200);
    }

    public function reorder(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        DB::transaction(function () use ($data, $user) {
            foreach ($data['ids'] as $index => $id) {
                ClosingReason::where('id', $id)
                    ->where('company_id', $user->company_id)
                    ->update(['sort_order' => $index]);
            }
        });

        $reasons = ClosingReason::where('company_id', $user->company_id)->ordered()->get();

        return response()->json(['data' => $reasons], 200);
    }

    private function validateReason(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
            'outcome' => Rule::in(['won', 'lost', 'neutral']),
        ];

        if (!$partial) {
            $rules['name'] = 'required|string|max:255';
        }

        return $request->validate($rules);
    }
}
