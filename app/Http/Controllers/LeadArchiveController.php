<?php

namespace App\Http\Controllers;

use App\Events\LeadLiveUpdate;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Record;
use App\Models\Stage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LEAD ARCHIVE & BULK ACTIONS
 *
 * Archiving is backed by `leads.is_active` (false = archived). Archived leads
 * are hidden by default everywhere — contacts, funnels and the global inbox —
 * so archiving is the soft "get this out of my way" action, distinct from
 * deletion.
 *
 * Every action is scoped to the caller's company and their visible leads, so a
 * crafted id list can't reach another tenant's records.
 */
class LeadArchiveController extends Controller
{
    /** Cap so one request can't rewrite the whole table. */
    private const MAX_BULK = 500;

    public function archive(Request $request, $id): JsonResponse
    {
        return $this->setArchived($request, [$id], true);
    }

    public function unarchive(Request $request, $id): JsonResponse
    {
        return $this->setArchived($request, [$id], false);
    }

    public function bulkArchive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_ids'   => 'required|array|min:1|max:' . self::MAX_BULK,
            'lead_ids.*' => 'integer',
            'archived'   => 'required|boolean',
        ]);

        return $this->setArchived($request, $data['lead_ids'], $data['archived']);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_ids'   => 'required|array|min:1|max:' . self::MAX_BULK,
            'lead_ids.*' => 'integer',
            'user_id'    => 'nullable|integer',
        ]);

        $user = Auth::user();
        $leads = $this->scopedLeads($user, $data['lead_ids']);

        // An assignee must belong to the same company — `exists:users,id`
        // alone would let a caller assign across tenants.
        $assigneeId = null;
        if (!empty($data['user_id'])) {
            $assignee = User::where('id', $data['user_id'])
                ->where('company_id', $user->company_id)
                ->first();

            if (!$assignee) {
                return response()->json(['message' => 'User not found in this company'], 422);
            }
            $assigneeId = $assignee->id;
        }

        $affected = Lead::whereIn('id', $leads->pluck('id'))->update(['assigned_to' => $assigneeId]);

        return response()->json(['updated' => $affected]);
    }

    /**
     * File a selection of contacts into a funnel, at its entry stage.
     *
     * The bulk counterpart of the Contacts row action. Leads already in the
     * target funnel are left alone rather than reset to the entry stage —
     * a bulk action should not silently undo someone's pipeline progress.
     */
    public function bulkMoveToFunnel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_ids'   => 'required|array|min:1|max:' . self::MAX_BULK,
            'lead_ids.*' => 'integer',
            'funnel_id'  => 'required|integer',
        ]);

        $user = Auth::user();

        $funnel = Funnel::where('id', $data['funnel_id'])
            ->where('company_id', $user->company_id)
            ->first();

        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found in this company'], 422);
        }

        $entryStage = Stage::where('funnel_id', $funnel->id)
            ->where('type', 'entry')
            ->orderBy('order')
            ->first();

        if (!$entryStage) {
            return response()->json([
                'message' => 'The selected funnel has no entry stage configured.',
            ], 422);
        }

        // Skips leads already in this funnel; a null funnel_id (an unfiled
        // contact, the common case here) never matches and so is included.
        $leads = $this->scopedLeads($user, $data['lead_ids'])
            ->filter(fn (Lead $lead) => (int) $lead->funnel_id !== (int) $funnel->id);

        $affected = 0;

        foreach ($leads as $lead) {
            $previousStage = $lead->stage;

            // Tell the old board the card has gone before it moves.
            if ($lead->funnel_id) {
                broadcast(new LeadLiveUpdate('deleted', $lead));
            }

            $lead->update([
                'funnel_id' => $funnel->id,
                'stage_id'  => $entryStage->id,
            ]);

            Record::createLeadFunnelChange($lead, $funnel, $previousStage, $entryStage, $user);

            broadcast(new LeadLiveUpdate('created', $lead->fresh()));

            $affected++;
        }

        return response()->json(['updated' => $affected]);
    }

    public function bulkTag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_ids'   => 'required|array|min:1|max:' . self::MAX_BULK,
            'lead_ids.*' => 'integer',
            'tag_ids'    => 'required|array',
            'tag_ids.*'  => 'integer',
            // `attach` adds without disturbing existing tags; `replace` overwrites.
            'mode'       => 'sometimes|in:attach,replace',
        ]);

        $user = Auth::user();
        $leads = $this->scopedLeads($user, $data['lead_ids']);

        $tagIds = Tag::whereIn('id', $data['tag_ids'])
            ->where('company_id', $user->company_id)
            ->pluck('id')
            ->all();

        $mode = $data['mode'] ?? 'attach';

        foreach ($leads as $lead) {
            $mode === 'replace'
                ? $lead->tags()->sync($tagIds)
                : $lead->tags()->syncWithoutDetaching($tagIds);
        }

        return response()->json(['updated' => $leads->count()]);
    }

    /** Leads from the given ids that this user is actually allowed to touch. */
    private function scopedLeads($user, array $ids)
    {
        return Lead::fromCompany($user->company_id)
            ->visibleToUser($user)
            ->whereIn('id', $ids)
            ->get();
    }

    private function setArchived(Request $request, array $ids, bool $archived): JsonResponse
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        $leads = $this->scopedLeads($user, $ids);

        if ($leads->isEmpty()) {
            return response()->json(['message' => 'No matching leads'], 404);
        }

        $affected = Lead::whereIn('id', $leads->pluck('id'))
            ->update(['is_active' => !$archived]);

        return response()->json([
            'updated'  => $affected,
            'archived' => $archived,
        ]);
    }
}
