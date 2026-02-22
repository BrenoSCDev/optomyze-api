<?php

namespace App\Http\Controllers;

use App\Models\Funnel;
use App\Models\FunnelManagerQueue;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FunnelManagerQueueController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $queues = FunnelManagerQueue::fromCompany($user->company_id)
            ->with([
                'users:id,name,email',
                'funnels:id,funnel_manager_queue_id,name,type,is_active',
            ])
            ->latest()
            ->get();

        return response()->json($queues);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'is_active'     => 'boolean',
            'users'         => 'nullable|array',
            'users.*.id'    => 'required|exists:users,id',
            'users.*.order' => 'required|integer|min:0',
            'funnel_ids'    => 'nullable|array',
            'funnel_ids.*'  => 'integer|exists:funnels,id',
        ]);

        $queue = FunnelManagerQueue::create([
            'company_id' => $user->company_id,
            'name'       => $data['name'],
            'is_active'  => $data['is_active'] ?? true,
        ]);

        if (!empty($data['users'])) {
            $this->syncUsers($queue, $data['users'], $user->company_id);
        }

        if (!empty($data['funnel_ids'])) {
            $this->assignFunnels($queue, $data['funnel_ids'], $user->company_id);
        }

        return response()->json([
            'message' => 'Queue created successfully',
            'data'    => $queue->load(['users:id,name,email', 'funnels:id,funnel_manager_queue_id,name,type,is_active']),
        ], 201);
    }

    public function show(FunnelManagerQueue $funnelManagerQueue)
    {
        $user = Auth::user();

        if ($funnelManagerQueue->company_id !== $user->company_id) {
            return response()->json(['message' => 'Queue not found'], 404);
        }

        $funnelManagerQueue->load([
            'users:id,name,email',
            'funnels:id,funnel_manager_queue_id,name,type,is_active',
        ]);

        return response()->json($funnelManagerQueue);
    }

    public function update(Request $request, FunnelManagerQueue $funnelManagerQueue)
    {
        $user = Auth::user();

        if ($funnelManagerQueue->company_id !== $user->company_id) {
            return response()->json(['message' => 'Queue not found'], 404);
        }

        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'is_active'     => 'boolean',
            'users'         => 'nullable|array',
            'users.*.id'    => 'required|exists:users,id',
            'users.*.order' => 'required|integer|min:0',
            'funnel_ids'    => 'nullable|array',
            'funnel_ids.*'  => 'integer|exists:funnels,id',
        ]);

        $funnelManagerQueue->update(array_filter([
            'name'      => $data['name'] ?? null,
            'is_active' => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('users', $data)) {
            $this->syncUsers($funnelManagerQueue, $data['users'] ?? [], $user->company_id);
        }

        if (array_key_exists('funnel_ids', $data)) {
            $this->assignFunnels($funnelManagerQueue, $data['funnel_ids'] ?? [], $user->company_id);
        }

        return response()->json([
            'message' => 'Queue updated successfully',
            'data'    => $funnelManagerQueue->fresh()->load([
                'users:id,name,email',
                'funnels:id,funnel_manager_queue_id,name,type,is_active',
            ]),
        ]);
    }

    public function destroy(FunnelManagerQueue $funnelManagerQueue)
    {
        $user = Auth::user();

        if ($funnelManagerQueue->company_id !== $user->company_id) {
            return response()->json(['message' => 'Queue not found'], 404);
        }

        $funnelManagerQueue->funnels()->update(['funnel_manager_queue_id' => null]);
        $funnelManagerQueue->delete();

        return response()->json(['message' => 'Queue deleted successfully']);
    }

    private function syncUsers(FunnelManagerQueue $queue, array $users, int $companyId): void
    {
        $companyUserIds = User::fromCompany($companyId)->pluck('id')->toArray();

        $syncData = collect($users)
            ->filter(fn ($u) => in_array($u['id'], $companyUserIds))
            ->mapWithKeys(fn ($u) => [
                $u['id'] => ['order' => $u['order']],
            ])
            ->toArray();

        $queue->users()->sync($syncData);
    }

    private function assignFunnels(FunnelManagerQueue $queue, array $funnelIds, int $companyId): void
    {
        // Clear funnels previously assigned to this queue that are no longer in the new list
        Funnel::fromCompany($companyId)
            ->where('funnel_manager_queue_id', $queue->id)
            ->whereNotIn('id', $funnelIds)
            ->update(['funnel_manager_queue_id' => null]);

        if (!empty($funnelIds)) {
            Funnel::fromCompany($companyId)
                ->whereIn('id', $funnelIds)
                ->update(['funnel_manager_queue_id' => $queue->id]);
        }
    }
}
