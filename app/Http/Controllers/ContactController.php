<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CONTACTS
 *
 * Every lead in the company, viewed as a contact rather than as a pipeline
 * card. Leads no longer have to belong to a funnel or stage, so this is the
 * canonical list — the funnel board is one optional view onto a subset of it.
 */
class ContactController extends Controller
{
    /** Page sizes the UI offers. Anything else is clamped to the default. */
    private const PAGE_SIZES = [10, 15, 20, 50, 100];
    private const DEFAULT_PAGE_SIZE = 15;

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        $perPage = (int) $request->query('per_page', self::DEFAULT_PAGE_SIZE);
        if (!in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = self::DEFAULT_PAGE_SIZE;
        }

        $query = Lead::fromCompany($user->company_id)
            ->visibleToUser($user)
            // Archived leads are hidden unless explicitly asked for.
            ->archiveFilter($request->query('archived'))
            ->with([
                'funnel:id,name,type',
                'stage:id,name',
                'assignedUser:id,name,email,profile_pic',
                'tags:id,name,color',
                // The contact's own avatar comes from their WhatsApp chat —
                // leads have no picture column of their own.
                'whatsappInboxChat:id,lead_id,profile_pic_url',
            ]);

        // Free-text across the fields someone would actually search a contact by.
        if ($search = trim((string) $request->query('search', ''))) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('username', 'like', $like);
            });
        }

        if ($request->filled('funnel_id')) {
            $query->where('funnel_id', $request->query('funnel_id'));
        }

        if ($request->filled('stage_id')) {
            $query->where('stage_id', $request->query('stage_id'));
        }

        if ($request->filled('assigned_to')) {
            // `unassigned` is a real filter state, not an absent one.
            $assigned = $request->query('assigned_to');
            $assigned === 'unassigned'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', $assigned);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }

        if ($request->filled('source_platform')) {
            $query->where('source_platform', $request->query('source_platform'));
        }

        // Contacts that sit outside any pipeline — the new default state.
        if ($request->boolean('without_funnel')) {
            $query->whereNull('funnel_id');
        }

        $sort = in_array($request->query('sort'), ['created_at', 'updated_at', 'first_name', 'last_name'], true)
            ? $request->query('sort')
            : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $contacts = $query->orderBy($sort, $direction)->paginate($perPage);

        return response()->json([
            'data' => $contacts->items(),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page'    => $contacts->lastPage(),
                'per_page'     => $contacts->perPage(),
                'total'        => $contacts->total(),
                'page_sizes'   => self::PAGE_SIZES,
            ],
        ]);
    }
}
