<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Observation;
use App\Models\Record;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Notes on leads and deals, with one level of threaded replies.
 *
 * The same endpoints serve both: what a note is attached to is a `notable`,
 * addressed by a short type key. Raw class names are never accepted from a
 * client — the map below is the whole allowed set.
 */
class ObservationController extends Controller
{
    /** Short keys the frontend uses, mapped to the models they address. */
    private const NOTABLE_TYPES = [
        'lead' => Lead::class,
        'deal' => Sale::class,
        // Appointments carry discussion too — "did they confirm?", "moved to
        // Thursday" — and get the same threaded notes rather than a parallel
        // comment system. Every notable here is scoped by company_id below.
        'appointment' => Appointment::class,
    ];

    /**
     * Resolve a notable, refusing anything outside the caller's company.
     *
     * Returns null rather than throwing so callers decide the status code —
     * "not yours" and "does not exist" should look the same from outside.
     */
    private function resolveNotable(string $type, int|string $id, int $companyId): ?Model
    {
        $class = self::NOTABLE_TYPES[$type] ?? null;

        if (!$class) {
            return null;
        }

        return $class::where('company_id', $companyId)->find($id);
    }

    /**
     * Notes for one record, newest thread first, each with its replies.
     *
     * Replies are nested rather than interleaved: a thread stays readable as
     * one unit, and the timeline keeps its chronological order at the top
     * level.
     */
    public function indexFor(Request $request, string $type, string $id)
    {
        $user = Auth::user();
        $notable = $this->resolveNotable($type, $id, $user->company_id);

        if (!$notable) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        $notes = Observation::for($notable)
            ->roots()
            ->with([
                'user:id,name,email,profile_pic',
                'replies.user:id,name,email,profile_pic',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $notes]);
    }

    /**
     * List observations by lead.
     *
     * The original endpoint, kept working: it now returns the same threaded
     * shape as indexFor so existing callers gain replies for free.
     */
    public function index($leadId)
    {
        $user = Auth::user();
        $lead = Lead::where('company_id', $user->company_id)->find($leadId);

        if (!$lead) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        return Observation::for($lead)
            ->roots()
            ->with([
                'user:id,name,email,profile_pic',
                'replies.user:id,name,email,profile_pic',
            ])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Store a note, or a reply to one.
     *
     * Accepts either the legacy `lead_id` or a `notable_type` + `notable_id`
     * pair. A `parent_id` makes it a reply, and the reply inherits whatever
     * its parent is attached to — a reply cannot end up on a different record
     * from the note it answers.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'lead_id'       => ['nullable', 'integer'],
            'notable_type'  => ['nullable', 'string', 'in:' . implode(',', array_keys(self::NOTABLE_TYPES))],
            'notable_id'    => ['nullable', 'integer'],
            'parent_id'     => ['nullable', 'integer'],
            'note'          => ['required', 'string'],
        ]);

        $parent = null;

        if (!empty($data['parent_id'])) {
            $parent = Observation::fromCompany($user->company_id)->find($data['parent_id']);

            if (!$parent) {
                return response()->json(['message' => 'Note not found'], 404);
            }

            // One level deep: replying to a reply joins the same thread rather
            // than starting a nested one.
            $parent = $parent->parent_id
                ? Observation::fromCompany($user->company_id)->find($parent->parent_id) ?? $parent
                : $parent;
        }

        if ($parent) {
            $notableType = $parent->notable_type;
            $notableId   = $parent->notable_id;
            $leadId      = $parent->lead_id;
        } else {
            [$notableType, $notableId, $leadId] = $this->resolveTarget($data, $user->company_id);
        }

        // Mentions are parsed, tenant-checked and stored so the UI can
        // highlight them. Delivering a notification is a separate step:
        // NotificationService resolves recipients from company settings
        // (admin / manager / record owner), not from an explicit list, so
        // routing mentions through it would notify the wrong people. The
        // existing task-comment mentions behave the same way.
        $mentions = Observation::parseMentions($data['note'], $user->company_id);

        $observation = Observation::create([
            'company_id'   => $user->company_id,
            'notable_type' => $notableType,
            'notable_id'   => $notableId,
            // Still populated for lead notes so the column stays usable until
            // it is dropped.
            'lead_id'      => $leadId,
            'parent_id'    => $parent?->id,
            'note'         => $data['note'],
            'mentions'     => $mentions ?: null,
            'user_id'      => $user->id,
        ]);

        // The activity timeline is lead-centric, so only lead notes log there.
        if ($leadId && !$parent) {
            $lead = Lead::find($leadId);
            if ($lead) {
                Record::createObservationCreated($observation, $lead, $user);
            }
        }

        return response()->json([
            'message' => 'Observation created successfully.',
            'data'    => $observation->load('user:id,name,email,profile_pic', 'replies.user:id,name,email,profile_pic'),
        ], 201);
    }

    /**
     * Where a new top-level note attaches.
     *
     * `lead_id` is the legacy shape and still wins when present, so nothing
     * that already posts notes has to change.
     */
    private function resolveTarget(array $data, int $companyId): array
    {
        if (!empty($data['lead_id'])) {
            $lead = Lead::where('company_id', $companyId)->find($data['lead_id']);

            if (!$lead) {
                throw ValidationException::withMessages(['lead_id' => ['Lead not found.']]);
            }

            return [$lead->getMorphClass(), $lead->id, $lead->id];
        }

        if (empty($data['notable_type']) || empty($data['notable_id'])) {
            throw ValidationException::withMessages([
                'notable_id' => ['Say what this note is about.'],
            ]);
        }

        $notable = $this->resolveNotable($data['notable_type'], $data['notable_id'], $companyId);

        if (!$notable) {
            throw ValidationException::withMessages(['notable_id' => ['Record not found.']]);
        }

        /*
         * `lead_id` is the legacy column, kept so lead-scoped views can find a
         * note without a second query. It has a foreign key to `leads`, so it
         * must hold a real lead id or nothing.
         *
         * This used to fall back to the notable's own key for anything that
         * was not a Sale — correct only while Lead was the sole alternative.
         * An appointment's id is not a lead id, and writing one violated the
         * constraint. Each notable now says explicitly which lead, if any,
         * stands behind it.
         */
        $leadId = match (true) {
            $notable instanceof Lead        => $notable->getKey(),
            $notable instanceof Sale        => $notable->lead_id,
            $notable instanceof Appointment => $notable->lead_id,
            default                         => null,
        };

        return [$notable->getMorphClass(), $notable->getKey(), $leadId];
    }

    /**
     * Update a note.
     *
     * Only the author edits their own words. `edited_at` is stamped so the UI
     * can show that a note changed after the fact rather than silently
     * rewriting history.
     */
    public function update(Request $request, Observation $observation)
    {
        $user = Auth::user();

        if ($observation->company_id !== $user->company_id) {
            return response()->json(['message' => 'Note not found'], 404);
        }

        if ($observation->user_id !== $user->id) {
            return response()->json(['message' => 'You can only edit your own notes.'], 403);
        }

        $data = $request->validate([
            'note' => ['required', 'string'],
        ]);

        $observation->update([
            'note'      => $data['note'],
            'mentions'  => Observation::parseMentions($data['note'], $user->company_id) ?: null,
            'edited_at' => now(),
        ]);

        return response()->json([
            'message' => 'Observation updated successfully.',
            'data'    => $observation->fresh(['user:id,name,email,profile_pic', 'replies.user:id,name,email,profile_pic']),
        ]);
    }

    /**
     * Delete a note. Deleting one that started a thread takes its replies with
     * it — that is what removing a thread means.
     *
     * Admins and managers can remove anyone's note; everyone else only their
     * own, matching how the rest of the CRM treats authored content.
     */
    public function destroy(Observation $observation)
    {
        $user = Auth::user();

        if ($observation->company_id !== $user->company_id) {
            return response()->json(['message' => 'Note not found'], 404);
        }

        if ($observation->user_id !== $user->id && !$user->canManageUsers()) {
            return response()->json(['message' => 'You can only delete your own notes.'], 403);
        }

        $observation->delete();

        return response()->json([
            'message' => 'Observation deleted successfully.',
        ]);
    }

    ////////////////// V1 API METHODS //////////////////

    public function apiIndex(Request $request, int $leadId)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $lead = Lead::where('company_id', $company->id)->find($leadId);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $observations = Observation::for($lead)
            ->roots()
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($observations);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) return response()->json(['message' => 'Unauthorized'], 401);

        $lead = Lead::where('company_id', $company->id)->find($request->lead_id);
        if (!$lead) return response()->json(['message' => 'Lead not found'], 404);

        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'note'    => 'required|string',
        ]);

        $observation = Observation::create([
            ...$validated,
            'company_id'   => $company->id,
            'notable_type' => Lead::class,
            'notable_id'   => $lead->id,
            'user_id'      => null,
        ]);

        return response()->json(['message' => 'Observation created successfully', 'data' => $observation], 201);
    }
}
