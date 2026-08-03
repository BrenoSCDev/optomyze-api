<?php

namespace App\Http\Controllers;

use App\Models\CompanyQuickstart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * QUICKSTART CHECKLIST
 *
 * The onboarding checklist a company works through after signing up. There is
 * exactly one per company and it is resolved from the authenticated user's
 * company — never from a client-supplied id — so the checklist is a
 * company-wide artefact that any member sees the same way.
 *
 * Ticking a step is a manual act, not something inferred from the data. That
 * is deliberate: "connect your messaging app" is done when the user says it
 * is, and guessing from, say, the existence of an integration row would tick
 * boxes people never visited and make the list feel out of their control.
 */
class QuickstartController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->resolve()->toPayload()]);
    }

    /** Tick or untick a single step. */
    public function updateStep(Request $request, string $step): JsonResponse
    {
        $validated = $request->validate([
            'completed' => 'required|boolean',
        ]);

        // Validated separately from the body so an unknown key is a 422 about
        // the step, not a silently ignored write.
        validator(
            ['step' => $step],
            ['step' => ['required', Rule::in(CompanyQuickstart::STEPS)]]
        )->validate();

        $quickstart = $this->resolve();
        $quickstart->setStepCompleted($step, $validated['completed']);

        return response()->json(['data' => $quickstart->fresh()->toPayload()]);
    }

    /**
     * Dismiss the checklist, or bring it back.
     *
     * Dismissing hides it from the menu; it is never deleted, so a company can
     * restore it and the ticked steps are still there.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dismissed' => 'sometimes|boolean',
        ]);

        $dismissed = $validated['dismissed'] ?? true;

        $quickstart = $this->resolve();
        $quickstart->update(['dismissed_at' => $dismissed ? now() : null]);

        return response()->json(['data' => $quickstart->fresh()->toPayload()]);
    }

    /**
     * This company's checklist, created on the spot if it is missing.
     *
     * The Company model creates one on registration and a migration backfilled
     * every company that predates the feature; this is the safety net for rows
     * inserted by a path that bypassed Eloquent events (a raw insert, a SQL
     * import), so the endpoint never 404s on its own bookkeeping.
     */
    private function resolve(): CompanyQuickstart
    {
        $user = Auth::user();

        abort_if(!$user?->company_id, 403, 'User does not belong to a company.');

        return CompanyQuickstart::firstOrCreate(['company_id' => $user->company_id]);
    }
}
