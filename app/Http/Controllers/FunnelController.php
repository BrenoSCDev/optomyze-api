<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\Stage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FunnelController extends Controller
{
    /**
     * How many leads a stage returns per request. The contact board loads one
     * page per column up front and pulls the rest as the column is scrolled,
     * so a funnel with thousands of leads no longer ships in a single payload.
     */
    private const LEADS_PER_STAGE = 10;

    /** Relations every lead card on the board renders. */
    private const LEAD_CARD_RELATIONS = [
        'assignedUser:id,name,profile_pic',
        'whatsappInboxChat:id,lead_id,profile_pic_url',
        'products:id,name,base_price',
        'tags',
    ];

    /**
     * A lead's estimated value is the sum of its interested products. Kept as
     * raw SQL so it can be filtered on without loading the rows.
     */
    private const LEAD_VALUE_SQL = '(select coalesce(sum(lp.total_price), 0) from lead_product lp where lp.lead_id = leads.id)';

    /** Relations every deal card on the board renders. */
    private const DEAL_CARD_RELATIONS = [
        'lead:id,first_name,last_name,email,phone,stage_id,funnel_id,assigned_to',
        'lead.assignedUser:id,name,profile_pic',
        'lead.stage:id,name,color',
        'lead.funnel:id,name',
        'user:id,name,profile_pic',
        'items:id,sale_id,product_name,quantity,total_price',
        'charges',
        'discounts',
        'tags',
    ];

    /**
     * SQL mirror of Sale::getFinalTotalAttribute() — subtotal less discounts
     * plus charges. The accessor needs the charges relation loaded, which a
     * paginated board cannot afford for the rows it is only counting.
     */
    private const DEAL_VALUE_SQL = '(sales.subtotal - sales.discount_total + (select coalesce(sum(sc.calculated_amount), 0) from sale_charges sc where sc.sale_id = sales.id))';

    /**
     * List template funnels (type = model) with their stages.
     */
    public function templates()
    {
        $user = Auth::user();

        $templates = Funnel::fromCompany($user->company_id)
            ->where('type', 'model')
            ->with(['stages' => fn ($q) => $q->orderBy('order')])
            ->get();

        return response()->json($templates);
    }

    /**
     * Display a listing of funnels for the authenticated user's company.
     */
    /**
     * Funnels the signed-in user should see in their navigation.
     *
     * Mirrors the login payload so the frontend can refresh the menu after a
     * funnel is activated/deactivated, or after contact-pipeline management is
     * switched on or off — without forcing a re-login.
     *
     * Contact funnels are withheld entirely when the company has contact
     * pipelines disabled; deal funnels are always included.
     */
    public function navigationFunnels(Request $request)
    {
        $user = $request->user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        $types = ['deal'];
        if ($user->company?->contact_pipeline_enabled) {
            $types[] = 'contact';
        }

        $funnels = Funnel::where('company_id', $user->company_id)
            ->where('is_active', true)
            ->whereIn('type', $types)
            ->get()
            ->map(function ($funnel) {
                $funnel->nleads = $funnel->totalLeadsCount();
                return $funnel;
            });

        return response()->json(['data' => $funnels]);
    }

    public function index()
    {
        $user = Auth::user();

        $funnels = Funnel::fromCompany($user->company_id)
            ->whereIn('type', ['contact', 'deal'])
            ->get() // get results first
            ->map(function ($funnel) {
                $funnel->nleads = $funnel->type === 'deal'
                    ? $funnel->totalDealsCount()
                    : $funnel->totalLeadsCount();
                return $funnel;
            });

            return response()->json($funnels);
    }

    /**
     * Store a newly created funnel in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'is_active'       => 'boolean',
            'settings'        => 'nullable|array',
            'model_funnel_id' => 'nullable|exists:funnels,id',
            'type'            => 'nullable|in:contact,deal,model',
        ]);

        $funnel = Funnel::create([
            'company_id'  => $user->company_id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? true,
            'created_by'  => $user->id,
            'settings'    => $validated['settings'] ?? null,
            'type'        => $validated['type'] ?? 'contact',
        ]);

        if (!empty($validated['model_funnel_id'])) {
            $modelFunnel = Funnel::where('id', $validated['model_funnel_id'])
                ->where('company_id', $user->company_id)
                ->where('type', 'model')
                ->with(['stages' => fn ($q) => $q->orderBy('order')])
                ->firstOrFail();

            foreach ($modelFunnel->stages as $modelStage) {
                Stage::create([
                    'company_id'  => $user->company_id,
                    'funnel_id'   => $funnel->id,
                    'name'        => $modelStage->name,
                    'description' => $modelStage->description,
                    'type'        => $modelStage->type,
                    'order'       => $modelStage->order,
                    'color'       => $modelStage->color,
                    'settings'    => $modelStage->settings,
                    'is_active'   => true,
                    'created_by'  => $user->id,
                ]);
            }
        }

        return response()->json($funnel->load('stages'), 201);
    }

    /**
     * Display the specified funnel (only if belongs to company).
     */
    public function show($id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        return response()->json($funnel);
    }

    /**
     * Update the specified funnel.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $funnel->update($validated);

        return response()->json($funnel);
    }

    /**
     * Toggle the specified funnel's active status.
     */
    public function toggleStatus($id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        $funnel->is_active = !$funnel->is_active;
        $funnel->save();

        return response()->json($funnel);
    }

    /**
     * What deleting this funnel would cost, so the confirmation can say it.
     *
     * Only the contact count: those are the records that survive the delete in
     * a changed state, and "12 contacts will be left without a funnel" is the
     * one fact that decides whether someone clicks the button.
     */
    public function deleteImpact($id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        return response()->json([
            'lead_count' => Lead::where('funnel_id', $funnel->id)->count(),
        ]);
    }

    /**
     * Remove the specified funnel, keeping its contacts.
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        $unfiled = $this->unfileLeadsAndDelete($funnel);

        return response()->json([
            'message'          => 'Funnel deleted successfully',
            'unfiled_leads'    => $unfiled,
        ]);
    }

    /**
     * Channel integrations that merely point at a funnel. A connected WhatsApp
     * account or social inbox is infrastructure the customer set up; the funnel
     * on it is a routing preference, and deleting the preference must not take
     * the connection with it.
     */
    private const CHANNEL_TABLES = [
        'whats_app_instances',
        'instagram_integrations',
        'facebook_messenger_integrations',
    ];

    /**
     * Detach everything that was merely filed in the funnel, then delete it.
     *
     * These columns carry ON DELETE CASCADE, so it is tempting to assume the
     * database already handles this. It does not, and cannot: Funnel is a
     * SoftDeletes model, so the delete below is an UPDATE that sets deleted_at.
     * The row never goes, no foreign key ever fires, and without this the
     * contacts would keep pointing at a funnel that has been deleted — absent
     * from every board, yet not unfiled either, since "unfiled" means
     * funnel_id IS NULL. They fell out of both views.
     *
     * stage_id travels with funnel_id for the same reason: a stage only means
     * something inside its own funnel.
     *
     * Wrapped in a transaction so a failed delete cannot leave contacts unfiled
     * from a funnel that is still there.
     */
    private function unfileLeadsAndDelete(Funnel $funnel): int
    {
        return DB::transaction(function () use ($funnel) {
            $unfiled = Lead::where('funnel_id', $funnel->id)
                ->update(['funnel_id' => null, 'stage_id' => null]);

            foreach (self::CHANNEL_TABLES as $table) {
                DB::table($table)->where('funnel_id', $funnel->id)->update(['funnel_id' => null]);
            }

            $funnel->delete();

            return $unfiled;
        });
    }

    /**
     * The contact board: every active stage with its first page of leads.
     *
     * Leads are paginated per stage (see self::LEADS_PER_STAGE) and every
     * filter is resolved in SQL, so the board no longer has to hold the whole
     * funnel in memory to search it. Subsequent pages come from @see stageLeads.
     */
    public function leadsByStages(Request $request, $id)
    {
        $user = Auth::user();

        $funnel = Funnel::with([
            'stages' => fn ($query) => $query->where('is_active', true)->orderBy('order'),
        ])->findOrFail($id);

        if ($funnel->company_id !== $user->company_id) {
            throw ValidationException::withMessages(['funnel' => 'Funnel not found.']);
        }

        $perPage     = $this->resolvePerPage($request);
        $funnelTotal = 0.0;

        $funnel->stages->each(function (Stage $stage) use ($request, $user, $perPage, &$funnelTotal) {
            $query = $this->stageLeadsQuery($stage, $request, $user);

            // Stage and funnel totals cover every matching lead, not just the
            // page that was loaded — otherwise the figures would climb as the
            // user scrolls a column.
            $stageTotal = $this->sumEstimatedValue($query);
            $funnelTotal += $stageTotal;

            $stage->value_estimated = $stageTotal;

            $this->attachLeadsPage($stage, $query, $perPage, 1);
        });

        $funnel->value_estimated = $funnelTotal;

        $tags = Tag::fromCompany($user->company_id)->get();

        return response()->json([
            'funnel' => $funnel,
            'tags'   => $tags,
        ]);
    }

    /**
     * One page of leads for a single stage — what the board's infinite scroll
     * asks for. Accepts the same filter/sort parameters as @see leadsByStages.
     */
    public function stageLeads(Request $request, $id, $stageId)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);
        $stage  = Stage::where('funnel_id', $funnel->id)->findOrFail($stageId);

        $perPage = $this->resolvePerPage($request) ?? self::LEADS_PER_STAGE;
        $page    = max(1, (int) $request->query('page', 1));

        $query = $this->stageLeadsQuery($stage, $request, $user);

        $paginator = (clone $query)
            ->with(self::LEAD_CARD_RELATIONS)
            ->paginate($perPage, ['*'], 'page', $page);

        $leads = $paginator->getCollection();
        $this->attachLeadValues($leads);

        return response()->json([
            'data' => $leads->values(),
            'meta' => [
                'current_page'    => $paginator->currentPage(),
                'last_page'       => max(1, $paginator->lastPage()),
                'per_page'        => (int) $paginator->perPage(),
                'total'           => $paginator->total(),
                'has_more'        => $paginator->currentPage() < $paginator->lastPage(),
                'value_estimated' => $this->sumEstimatedValue($query),
            ],
        ]);
    }

    /**
     * Base query for the leads of one stage: visibility, archive mode, and
     * every board filter, ordered so pagination is stable.
     */
    private function stageLeadsQuery(Stage $stage, Request $request, User $user): Builder
    {
        // 'active' (default) | 'archived' | 'all'. Archived leads stay hidden
        // unless asked for.
        $query = Lead::query()
            ->where('stage_id', $stage->id)
            ->visibleToUser($user)
            ->archiveFilter($request->query('archived'));

        // A stage filter narrows the board to a single column; the others come
        // back empty rather than unfiltered.
        $stageFilter = $request->query('stage_id');
        if ($stageFilter !== null && $stageFilter !== '' && (int) $stageFilter !== (int) $stage->id) {
            $query->whereRaw('1 = 0');
        }

        $this->applyLeadFilters($query, $request);

        $direction = $request->query('sort') === 'oldest' ? 'asc' : 'desc';

        return $query->orderBy('created_at', $direction)->orderBy('id', $direction);
    }

    /**
     * Board filters, all resolved in SQL. Silently ignores anything absent so
     * the same helper serves the first page and the infinite-scroll pages.
     */
    private function applyLeadFilters(Builder $query, Request $request): void
    {
        foreach (['first_name', 'last_name', 'email'] as $field) {
            $value = trim((string) $request->query($field, ''));
            if ($value !== '') {
                $query->where($field, 'like', '%' . $this->escapeLike($value) . '%');
            }
        }

        // Phones are stored digits-only, so both filters compare digits.
        $phone = preg_replace('/\D/', '', (string) $request->query('phone', ''));
        if ($phone !== '') {
            $query->where('phone', 'like', '%' . $phone . '%');
        }

        $ddi = preg_replace('/\D/', '', (string) $request->query('ddi', ''));
        if ($ddi !== '') {
            $query->where('phone', 'like', $ddi . '%');
        }

        $platform = trim((string) $request->query('source_platform', ''));
        if ($platform !== '') {
            $query->where('source_platform', $platform);
        }

        $assignedTo = $request->query('assigned_to');
        if ($assignedTo !== null && $assignedTo !== '') {
            $query->where('assigned_to', (int) $assignedTo);
        }

        $tags = array_values(array_filter(
            (array) $request->query('tags', []),
            fn ($tag) => is_string($tag) && trim($tag) !== ''
        ));
        if ($tags) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.name', $tags));
        }

        // A missing ai_score reads as 0, matching how the card renders it.
        $scoreMin = $request->query('score_min');
        if (is_numeric($scoreMin)) {
            $query->whereRaw('coalesce(ai_score, 0) >= ?', [(float) $scoreMin]);
        }

        $scoreMax = $request->query('score_max');
        if (is_numeric($scoreMax)) {
            $query->whereRaw('coalesce(ai_score, 0) <= ?', [(float) $scoreMax]);
        }

        $valueMin = $request->query('value_min');
        if (is_numeric($valueMin)) {
            $query->whereRaw(self::LEAD_VALUE_SQL . ' >= ?', [(float) $valueMin]);
        }

        $valueMax = $request->query('value_max');
        if (is_numeric($valueMax)) {
            $query->whereRaw(self::LEAD_VALUE_SQL . ' <= ?', [(float) $valueMax]);
        }
    }

    /**
     * Loads one page of leads onto a stage, along with the counters the board
     * needs to know whether to keep scrolling.
     *
     * A null $perPage means "no pagination" — the export path needs every
     * matching lead, not the first page.
     */
    private function attachLeadsPage(Stage $stage, Builder $query, ?int $perPage, int $page): void
    {
        if ($perPage === null) {
            $leads = (clone $query)->with(self::LEAD_CARD_RELATIONS)->get();
            $this->attachLeadValues($leads);

            $stage->setRelation('activeLeads', $leads);
            $stage->leads_total        = $leads->count();
            $stage->leads_per_page     = $leads->count();
            $stage->leads_current_page = 1;
            $stage->leads_last_page    = 1;
            $stage->leads_has_more     = false;

            return;
        }

        $paginator = (clone $query)
            ->with(self::LEAD_CARD_RELATIONS)
            ->paginate($perPage, ['*'], 'page', $page);

        $leads = $paginator->getCollection();
        $this->attachLeadValues($leads);

        // The relation is named activeLeads so the payload keeps its
        // `active_leads` key, which the frontend has always read.
        $stage->setRelation('activeLeads', $leads);
        $stage->leads_total        = $paginator->total();
        $stage->leads_per_page     = (int) $paginator->perPage();
        $stage->leads_current_page = $paginator->currentPage();
        $stage->leads_last_page    = max(1, $paginator->lastPage());
        $stage->leads_has_more     = $paginator->currentPage() < $paginator->lastPage();
    }

    /** Sets `value_estimated` on each loaded lead from its products. */
    private function attachLeadValues(Collection $leads): void
    {
        $leads->each(function (Lead $lead) {
            $lead->value_estimated = (float) $lead->products
                ->sum(fn ($product) => (float) $product->pivot->total_price);
        });
    }

    /** Estimated value of every lead a query matches, without loading them. */
    private function sumEstimatedValue(Builder $query): float
    {
        $ids = (clone $query)->reorder()->select('leads.id')->getQuery();

        return (float) DB::table('lead_product')->whereIn('lead_id', $ids)->sum('total_price');
    }

    /** Page size for a board request; null means "everything" (export). */
    private function resolvePerPage(Request $request): ?int
    {
        $perPage = $request->query('per_page');

        if ($perPage === 'all') {
            return null;
        }

        $perPage = (int) $perPage;

        return $perPage > 0 ? min($perPage, 100) : self::LEADS_PER_STAGE;
    }

    /** Escapes LIKE wildcards so a literal % or _ in a filter stays literal. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * The deal board: every active stage with its first page of deals.
     *
     * Paginated and filtered the same way as @see leadsByStages; subsequent
     * pages come from @see stageDeals.
     */
    public function dealsByStages(Request $request, $id)
    {
        $user = Auth::user();

        $funnel = Funnel::with([
            'stages' => fn ($query) => $query->where('is_active', true)->orderBy('order'),
        ])->findOrFail($id);

        if ($funnel->company_id !== $user->company_id) {
            throw ValidationException::withMessages(['funnel' => 'Funnel not found.']);
        }

        $perPage    = $this->resolvePerPage($request);
        $funnelTotal = 0.0;

        $funnel->stages->each(function (Stage $stage) use ($request, $user, $perPage, &$funnelTotal) {
            $query = $this->stageDealsQuery($stage, $request, $user);

            // Totals span every matching deal, not the loaded page, so the
            // header doesn't climb as the user scrolls.
            $stageTotal = $this->sumDealValue($query);
            $funnelTotal += $stageTotal;

            $stage->deals_value_total = $stageTotal;

            $this->attachDealsPage($stage, $query, $perPage, 1);
        });

        $funnel->deals_value_total = $funnelTotal;

        $tags = Tag::fromCompany($user->company_id)->get();

        return response()->json([
            'funnel' => $funnel,
            'tags'   => $tags,
        ]);
    }

    /**
     * One page of deals for a single stage — the deal board's infinite scroll.
     */
    public function stageDeals(Request $request, $id, $stageId)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);
        $stage  = Stage::where('funnel_id', $funnel->id)->findOrFail($stageId);

        $perPage = $this->resolvePerPage($request) ?? self::LEADS_PER_STAGE;
        $page    = max(1, (int) $request->query('page', 1));

        $query = $this->stageDealsQuery($stage, $request, $user);

        $paginator = (clone $query)
            ->with(self::DEAL_CARD_RELATIONS)
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => max(1, $paginator->lastPage()),
                'per_page'     => (int) $paginator->perPage(),
                'total'        => $paginator->total(),
                'has_more'     => $paginator->currentPage() < $paginator->lastPage(),
                'value_total'  => $this->sumDealValue($query),
            ],
        ]);
    }

    /**
     * Base query for the deals of one stage: visibility plus every board
     * filter, ordered so pagination is stable.
     */
    private function stageDealsQuery(Stage $stage, Request $request, User $user): Builder
    {
        // 'active' (default) | 'archived' | 'all'. Archived deals stay hidden
        // unless asked for, same as the lead board.
        $query = Sale::query()
            ->where('stage_id', $stage->id)
            ->visibleToUser($user)
            ->archiveFilter($request->query('archived'));

        // A stage filter narrows the board to a single column.
        $stageFilter = $request->query('stage_id');
        if ($stageFilter !== null && $stageFilter !== '' && (int) $stageFilter !== (int) $stage->id) {
            $query->whereRaw('1 = 0');
        }

        $name = trim((string) $request->query('name', ''));
        if ($name !== '') {
            $query->where('sales.name', 'like', '%' . $this->escapeLike($name) . '%');
        }

        // The deal's contact, matched across first and last name together so
        // "Ana Ribeiro" finds the deal that "Ana" and "Ribeiro" both would.
        $leadName = trim((string) $request->query('lead_name', ''));
        if ($leadName !== '') {
            $escaped = '%' . $this->escapeLike($leadName) . '%';
            $query->whereHas('lead', fn ($q) => $q->whereRaw(
                "concat(coalesce(first_name, ''), ' ', coalesce(last_name, '')) like ?",
                [$escaped]
            ));
        }

        $valueMin = $request->query('value_min');
        if (is_numeric($valueMin)) {
            $query->whereRaw(self::DEAL_VALUE_SQL . ' >= ?', [(float) $valueMin]);
        }

        $valueMax = $request->query('value_max');
        if (is_numeric($valueMax)) {
            $query->whereRaw(self::DEAL_VALUE_SQL . ' <= ?', [(float) $valueMax]);
        }

        $direction = $request->query('sort') === 'oldest' ? 'asc' : 'desc';

        return $query->orderBy('sales.created_at', $direction)->orderBy('sales.id', $direction);
    }

    /**
     * Loads one page of deals onto a stage, with the counters the board needs
     * to decide whether to keep scrolling. A null $perPage means "everything".
     */
    private function attachDealsPage(Stage $stage, Builder $query, ?int $perPage, int $page): void
    {
        if ($perPage === null) {
            $deals = (clone $query)->with(self::DEAL_CARD_RELATIONS)->get();

            $stage->setRelation('deals', $deals);
            $stage->deals_total        = $deals->count();
            $stage->deals_per_page     = $deals->count();
            $stage->deals_current_page = 1;
            $stage->deals_last_page    = 1;
            $stage->deals_has_more     = false;

            return;
        }

        $paginator = (clone $query)
            ->with(self::DEAL_CARD_RELATIONS)
            ->paginate($perPage, ['*'], 'page', $page);

        $stage->setRelation('deals', $paginator->getCollection());
        $stage->deals_total        = $paginator->total();
        $stage->deals_per_page     = (int) $paginator->perPage();
        $stage->deals_current_page = $paginator->currentPage();
        $stage->deals_last_page    = max(1, $paginator->lastPage());
        $stage->deals_has_more     = $paginator->currentPage() < $paginator->lastPage();
    }

    /** Final total of every deal a query matches, without loading them. */
    private function sumDealValue(Builder $query): float
    {
        $sum = (clone $query)
            ->reorder()
            ->selectRaw('coalesce(sum(' . self::DEAL_VALUE_SQL . '), 0) as aggregate')
            ->value('aggregate');

        return (float) $sum;
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $query = Funnel::fromCompany($company->id)
            ->with(['stages:id,funnel_id,name,order,type,color']);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $funnels = $query->paginate($request->input('per_page', 20));

        return response()->json($funnels);
    }

    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)
            ->with(['stages' => fn ($q) => $q->ordered()])
            ->find($id);

        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        return response()->json(['data' => $funnel]);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'type'        => 'nullable|in:contact,deal',
            'is_active'   => 'boolean',
        ]);

        $funnel = Funnel::create([
            'company_id'  => $company->id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? true,
            'type'        => $validated['type'] ?? 'contact',
        ]);

        return response()->json([
            'message' => 'Funnel created successfully',
            'data'    => $funnel,
        ], 201);
    }

    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($id);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $validated = $request->validate([
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        $funnel->update($validated);

        return response()->json([
            'message' => 'Funnel updated successfully',
            'data'    => $funnel->fresh(),
        ]);
    }

    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($id);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        // Same contract as the CRM's own delete: the pipeline goes, the
        // contacts in it stay and become unfiled.
        $unfiled = $this->unfileLeadsAndDelete($funnel);

        return response()->json([
            'message'       => 'Funnel deleted successfully',
            'unfiled_leads' => $unfiled,
        ]);
    }
}
