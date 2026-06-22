<?php

namespace App\Http\Controllers;

use App\Models\ClosingMessage;
use App\Models\ClosingReason;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClosingMessageController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $messages = ClosingMessage::where('company_id', $user->company_id)
            ->with('closingReasons:id,name')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $messages], 200);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $this->validateMessage($request);

        $message = ClosingMessage::create([
            'company_id' => $user->company_id,
            'name' => $data['name'],
            'message' => $data['message'],
        ]);

        $this->syncReasons($message, $data['closing_reason_ids'] ?? [], $user->company_id);

        return response()->json(['data' => $message->load('closingReasons:id,name')], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = ClosingMessage::where('id', $id)->where('company_id', $user->company_id)->firstOrFail();

        $data = $this->validateMessage($request, partial: true);

        $message->update(array_intersect_key($data, array_flip(['name', 'message'])));

        if (array_key_exists('closing_reason_ids', $data)) {
            $this->syncReasons($message, $data['closing_reason_ids'] ?? [], $user->company_id);
        }

        return response()->json(['data' => $message->load('closingReasons:id,name')], 200);
    }

    public function destroy(Request $request, $id)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = ClosingMessage::where('id', $id)->where('company_id', $user->company_id)->firstOrFail();
        $message->delete();

        return response()->json(['message' => 'Deleted'], 200);
    }

    private function syncReasons(ClosingMessage $message, array $reasonIds, int $companyId): void
    {
        $validReasonIds = ClosingReason::where('company_id', $companyId)
            ->whereIn('id', $reasonIds)
            ->pluck('id');

        $message->closingReasons()->sync($validReasonIds);
    }

    private function validateMessage(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => 'string|max:255',
            'message' => 'string',
            'closing_reason_ids' => 'sometimes|array',
            'closing_reason_ids.*' => 'integer',
        ];

        if (!$partial) {
            $rules['name'] = 'required|string|max:255';
            $rules['message'] = 'required|string';
        }

        return $request->validate($rules);
    }
}
