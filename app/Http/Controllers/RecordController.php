<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Record;
use App\Models\Sale;
use App\Traits\ResolvesActivityDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecordController extends Controller
{
    use ResolvesActivityDateRange;

    /**
     * Get all records for a given recordable (Lead or Sale).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'recordable_type' => 'required|in:Lead,Sale',
            'recordable_id'   => 'required|integer',
        ]);

        $user = Auth::user();
        $recordableType = $validated['recordable_type'];
        $modelClass = $recordableType === Record::RECORDABLE_LEAD ? Lead::class : Sale::class;

        $recordable = $modelClass::where('company_id', $user->company_id)
            ->where('id', $validated['recordable_id'])
            ->visibleToUser($user)
            ->firstOrFail();

        $records = Record::with('user:id,name,email')
            ->where('recordable_type', $recordableType)
            ->where('recordable_id', $recordable->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'recordable_type' => $recordableType,
            'recordable_id'   => $recordable->id,
            'records'         => $records,
        ]);
    }

    /**
     * Get all records for a given company, optionally filtered by recordable type.
     */
    public function byCompany(Request $request, $companyId)
    {
        $user = Auth::user();

        if ((int) $companyId !== (int) $user->company_id) {
            abort(403);
        }

        $validated = $request->validate([
            'recordable_type' => 'nullable|in:Lead,Sale',
        ]);

        $query = Record::with(['recordable', 'user:id,name,email'])
            ->where('company_id', $companyId)
            ->orderByDesc('created_at');

        if (!empty($validated['recordable_type'])) {
            $query->where('recordable_type', $validated['recordable_type']);
        }

        return response()->json([
            'company_id' => (int) $companyId,
            'records'    => $query->get(),
        ]);
    }

    /**
     * Unified "Record Logs" feed — Lead and Sale records merged into a single,
     * date-ranged, permission-scoped timeline (optionally filtered to one type).
     */
    public function activity(Request $request)
    {
        $validated = $request->validate([
            'date_range'       => 'nullable|in:today,yesterday,7_days,30_days,custom',
            'date_from'        => 'nullable|date',
            'date_to'          => 'nullable|date',
            'recordable_type'  => 'nullable|in:Lead,Sale',
        ]);

        $user      = Auth::user();
        $companyId = $user->company_id;
        [$start, $end] = $this->resolveActivityDateRange($request);
        $type = $validated['recordable_type'] ?? null;

        $results = collect();
        $total   = 0;

        if (!$type || $type === Record::RECORDABLE_LEAD) {
            $leadQuery = Record::with([
                    'recordable:id,first_name,last_name,email,company_id',
                    'user:id,name,email',
                ])
                ->where('recordable_type', Record::RECORDABLE_LEAD)
                ->where('company_id', $companyId)
                ->whereHasMorph('recordable', [Lead::class], fn($q) => $q->visibleToUser($user))
                ->whereBetween('created_at', [$start, $end]);

            $total   += $leadQuery->count();
            $results = $results->concat($leadQuery->latest()->limit(self::ACTIVITY_RESULT_CAP)->get());
        }

        if (!$type || $type === Record::RECORDABLE_SALE) {
            $saleQuery = Record::with([
                    'recordable:id,lead_id,user_id,name,total,created_at',
                    'recordable.lead:id,first_name,last_name,email,phone,avatar_key',
                    'user:id,name,email',
                ])
                ->where('recordable_type', Record::RECORDABLE_SALE)
                ->where('company_id', $companyId)
                ->whereHasMorph('recordable', [Sale::class], fn($q) => $q->visibleToUser($user))
                ->whereBetween('created_at', [$start, $end]);

            $total   += $saleQuery->count();
            $results = $results->concat($saleQuery->latest()->limit(self::ACTIVITY_RESULT_CAP)->get());
        }

        $transactions = $results->sortByDesc('created_at')->values()->take(self::ACTIVITY_RESULT_CAP);

        return response()->json([
            'transactions' => $transactions,
            'meta' => [
                'total'     => $total,
                'returned'  => $transactions->count(),
                'truncated' => $total > $transactions->count(),
            ],
        ]);
    }
}
