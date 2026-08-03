<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CALENDARS
 *
 * CRUD for a company's calendars, driven entirely from the Calendar screen —
 * there is deliberately no settings page for this.
 *
 * Every query starts from the caller's company and their visible calendars, so
 * a crafted id can never reach another tenant's calendar. The default calendar
 * carries extra protection: it cannot be deleted or archived, because it is
 * the fallback every unfiled appointment lands on.
 */
class CalendarController extends Controller
{
    /**
     * The company's calendars, with the counts the picker shows.
     *
     * Archived calendars are excluded unless asked for: they should not clutter
     * the switcher, but the management view needs to reach them to restore one.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        $query = Calendar::fromCompany($user->company_id)
            ->visibleToUser($user)
            ->withCount(['appointments', 'tasks'])
            ->with('owner:id,name,email');

        if (!$request->boolean('include_archived')) {
            $query->active();
        }

        // The default first, then alphabetically: the switcher should open on
        // the calendar most people want without hunting.
        $calendars = $query
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        // A company that predates this feature, or one whose calendar was
        // removed out of band, gets one here rather than an empty screen.
        if ($calendars->isEmpty()) {
            Calendar::defaultFor($user->company_id);

            $calendars = Calendar::fromCompany($user->company_id)
                ->visibleToUser($user)
                ->withCount(['appointments', 'tasks'])
                ->with('owner:id,name,email')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get();
        }

        return response()->json(['data' => $calendars]);
    }

    public function show(Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        $calendar->loadCount(['appointments', 'tasks'])
            ->load(['owner:id,name,email', 'integrations', 'bookingSettings']);

        return response()->json(['data' => $calendar]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();
        $data = $this->validatePayload($request, $user);

        $calendar = DB::transaction(function () use ($data, $user) {
            $calendar = Calendar::create([
                ...$data,
                'company_id' => $user->company_id,
                'created_by' => $user->id,
            ]);

            // First calendar in a company is necessarily the default; after
            // that it is opt-in through `is_default` on the request.
            $isFirst = Calendar::fromCompany($user->company_id)->count() === 1;

            if ($isFirst || ($data['is_default'] ?? false)) {
                $calendar->makeDefault();
            }

            return $calendar;
        });

        return response()->json(['data' => $calendar->fresh()->loadCount(['appointments', 'tasks'])], 201);
    }

    public function update(Request $request, Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        $user = $this->user();
        $data = $this->validatePayload($request, $user, $calendar);

        DB::transaction(function () use ($calendar, $data) {
            // Demoting the default directly would leave the company without
            // one, so `is_default` only ever goes through makeDefault().
            $makeDefault = $data['is_default'] ?? false;
            unset($data['is_default']);

            // The default calendar is the fallback for unfiled records; it has
            // to stay visible in the picker.
            if ($calendar->is_default && array_key_exists('is_active', $data) && !$data['is_active']) {
                unset($data['is_active']);
            }

            $calendar->update($data);

            if ($makeDefault) {
                $calendar->makeDefault();
            }
        });

        return response()->json(['data' => $calendar->fresh()->loadCount(['appointments', 'tasks'])]);
    }

    /** Promote a calendar to be the company's default. */
    public function setDefault(Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        $calendar->makeDefault();

        return response()->json(['data' => $calendar->fresh()]);
    }

    /**
     * Delete a calendar, moving anything scheduled on it to the default.
     *
     * The records are re-homed rather than deleted — a calendar is a container,
     * and removing the container must never destroy months of appointments.
     * The default calendar itself cannot be deleted; a company always has one.
     */
    public function destroy(Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        if ($calendar->is_default) {
            return response()->json([
                'message' => 'The default calendar cannot be deleted. Make another calendar the default first.',
            ], 422);
        }

        $fallback = Calendar::defaultFor($calendar->company_id);

        DB::transaction(function () use ($calendar, $fallback) {
            $calendar->appointments()->update(['calendar_id' => $fallback->id]);
            $calendar->tasks()->update(['calendar_id' => $fallback->id]);

            $calendar->delete();
        });

        return response()->json([
            'message'              => 'Calendar deleted successfully',
            'moved_to_calendar_id' => $fallback->id,
        ]);
    }

    /* ---------------- helpers ---------------- */

    private function user(): User
    {
        $user = Auth::user();

        abort_if(!$user?->company_id, 403, 'User does not belong to a company.');

        return $user;
    }

    /** A calendar is reachable only inside its own company, and only if visible. */
    private function assertAccess(Calendar $calendar): void
    {
        $user = $this->user();

        abort_if($calendar->company_id !== $user->company_id, 403, 'Unauthorized access to this calendar.');

        $visible = Calendar::whereKey($calendar->id)->visibleToUser($user)->exists();

        abort_if(!$visible, 403, 'Unauthorized access to this calendar.');
    }

    private function validatePayload(Request $request, User $user, ?Calendar $calendar = null): array
    {
        $creating = $calendar === null;

        $rules = [
            'name'        => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => 'sometimes|nullable|string|max:2000',
            'color'       => 'sometimes|string|max:20',
            'type'        => ['sometimes', Rule::in(Calendar::TYPES)],
            'visibility'  => ['sometimes', Rule::in(Calendar::VISIBILITIES)],
            'timezone'    => 'sometimes|nullable|string|max:100',
            'is_default'  => 'sometimes|boolean',
            'is_active'   => 'sometimes|boolean',
            'settings'    => 'sometimes|nullable|array',
            'owner_id'    => 'sometimes|nullable|integer',
        ];

        $data = $request->validate($rules);

        // An owner must belong to the same company — `exists:users,id` alone
        // would let a caller point a calendar at another tenant's user.
        if (array_key_exists('owner_id', $data) && $data['owner_id'] !== null) {
            $owner = User::where('id', $data['owner_id'])
                ->where('company_id', $user->company_id)
                ->first();

            abort_if(!$owner, 422, 'Owner not found in this company.');
        }

        return $data;
    }
}
