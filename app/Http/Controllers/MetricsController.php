<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\LeadTransaction;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class MetricsController extends Controller
{
    /**
     * Get comprehensive dashboard metrics.
     *
     * Accepts an optional ?period= query param:
     *   today | yesterday | 7_days | 30_days (default) | 60_days | 90_days | 120_days | full
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $companyId = $company->id;

        [$startDate, $endDate, $prevStart, $prevEnd, $periodLabel] =
            $this->resolvePeriod($request->input('period', '30_days'));

        // 1. Lead Statistics by Stage Type
        $leadStats     = $this->getLeadStatistics($companyId, $startDate, $endDate);
        $prevLeadStats = $this->getLeadStatistics($companyId, $prevStart, $prevEnd);

        // 2. Conversion Rate
        $conversionRate     = $this->getConversionRate($companyId, $startDate, $endDate);
        $prevConversionRate = $this->getConversionRate($companyId, $prevStart, $prevEnd);

        // 3. Performance Comparison (skipped when full period — no previous window)
        $performance = ($prevStart !== null)
            ? $this->calculatePerformanceComparison($leadStats, $prevLeadStats, $conversionRate, $prevConversionRate)
            : null;

        // 4. Leads by Funnel Stage (scoped to selected period)
        $leadsByFunnelStage = $this->getLeadsByFunnelStage($companyId, $startDate, $endDate);

        // 5. Estimated vs Closed Value (closed_at scoped to selected period)
        $estimatedVsClosedValue = $this->getEstimatedVsClosedValue($company, $startDate, $endDate);

        // 6. Deals by Funnel Stage (scoped to selected period)
        $dealsByFunnelStage = $this->getSalesByFunnelStage($companyId, $startDate, $endDate);

        // 7. Deal Statistics scoped to period (uses closed_at / lost_at)
        $dealStats = $this->getDealStatistics($companyId, $startDate, $endDate);

        return response()->json([
            'data' => [
                'lead_statistics'          => $leadStats,
                'deal_statistics'          => $dealStats,
                'conversion_rate'          => round($conversionRate, 2),
                'performance_comparison'   => $performance,
                'leads_by_funnel_stage'    => $leadsByFunnelStage,
                'deals_by_funnel_stage'    => $dealsByFunnelStage,
                'estimated_vs_closed_value' => $estimatedVsClosedValue,
                'summary' => [
                    'period'       => $periodLabel,
                    'company_id'   => $companyId,
                    'generated_at' => now()->toISOString(),
                ],
            ],
        ]);
    }

    /**
     * Resolve the requested period string into date boundaries.
     *
     * Returns [startDate, endDate, previousStart, previousEnd, periodLabel].
     * For the 'full' period, start/end and previous boundaries are null.
     */
    private function resolvePeriod(string $period): array
    {
        $now = Carbon::now();

        $make = fn(int $days) => [
            $now->copy()->subDays($days)->startOfDay(),
            $now->copy()->endOfDay(),
            $now->copy()->subDays($days * 2)->startOfDay(),
            $now->copy()->subDays($days + 1)->endOfDay(),
            [
                'label' => "last_{$days}_days",
                'start' => $now->copy()->subDays($days)->toDateString(),
                'end'   => $now->toDateString(),
            ],
        ];

        return match ($period) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
                ['label' => 'today', 'start' => $now->toDateString(), 'end' => $now->toDateString()],
            ],
            'yesterday' => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
                $now->copy()->subDays(2)->startOfDay(),
                $now->copy()->subDays(2)->endOfDay(),
                ['label' => 'yesterday', 'start' => $now->copy()->subDay()->toDateString(), 'end' => $now->copy()->subDay()->toDateString()],
            ],
            '7_days'   => $make(7),
            '60_days'  => $make(60),
            '90_days'  => $make(90),
            '120_days' => $make(120),
            'full'     => [null, null, null, null, ['label' => 'full_period', 'start' => null, 'end' => null]],
            default    => $make(30), // 30_days
        };
    }

    /**
     * Get deal statistics for a period, using closed_at / lost_at timestamps.
     * Total deals and conversion rate are scoped by created_at.
     */
    private function getDealStatistics(int $companyId, ?Carbon $startDate, ?Carbon $endDate): array
    {
        $closedQuery = Sale::where('company_id', $companyId)->where('status', 'closed');
        $lostQuery   = Sale::where('company_id', $companyId)->where('status', 'lost');
        $totalQuery  = Sale::where('company_id', $companyId);

        if ($startDate && $endDate) {
            $closedQuery->whereBetween('closed_at', [$startDate, $endDate]);
            $lostQuery->whereBetween('lost_at', [$startDate, $endDate]);
            $totalQuery->whereBetween('created_at', [$startDate, $endDate]);
        }

        $closedCount = (clone $closedQuery)->count();
        $revenue     = (clone $closedQuery)->sum('total');
        $lostCount   = (clone $lostQuery)->count();
        $totalDeals  = (clone $totalQuery)->count();

        $conversionRate = $totalDeals > 0
            ? round(($closedCount / $totalDeals) * 100, 2)
            : 0.0;

        return [
            'total_deals'          => $totalDeals,
            'closed_deals'         => $closedCount,
            'lost_deals'           => $lostCount,
            'revenue'              => round((float) $revenue, 2),
            'average_deal_value'   => $closedCount > 0 ? round((float) $revenue / $closedCount, 2) : 0.0,
            'deal_conversion_rate' => $conversionRate,
        ];
    }

    /**
     * Get comprehensive monthly metrics.
     */
    public function monthlyCharts(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $companyId = $company->id;

        // 1. Revenue Metrics (Module-aware)
        $revenueMetrics = $this->getRevenueMetricsByModule($company);

        // 2. Monthly Lead Creation Data (Current Year)
        $monthlyLeadData = $this->getMonthlyLeadData($companyId);
        return response()->json([
            'data' => [
                'monthly_lead_data' => $monthlyLeadData,
                'revenue_metrics' => $revenueMetrics,
            ],
        ]);
    }

    /**
     * Get lead statistics by stage type.
     */
    private function getLeadStatistics(int $companyId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = Lead::query()
            ->fromCompany($companyId)
            ->join('stages', 'leads.stage_id', '=', 'stages.id');

        // Apply date filters if provided
        if ($startDate && $endDate) {
            $query->whereBetween('leads.created_at', [$startDate, $endDate]);
        }

        // Get total leads
        $totalLeads = $query->count();

        // Get leads by stage type
        $leadsByType = $query
            ->select('stages.type', DB::raw('COUNT(*) as count'))
            ->groupBy('stages.type')
            ->pluck('count', 'type')
            ->toArray();

        return [
            'total_leads'     => $totalLeads,
            'total_leads_lost' => $leadsByType['lost'] ?? 0,
            'converted_leads' => $leadsByType['conversion'] ?? 0,
            'qualified_leads' => $leadsByType['qualified'] ?? 0,
            'breakdown'       => $leadsByType,
        ];
    }

    /**
     * Calculate conversion rate.
     */
    private function getConversionRate(int $companyId, ?Carbon $startDate = null, ?Carbon $endDate = null): float
    {
        $query = Lead::query()
            ->fromCompany($companyId)
            ->join('stages', 'leads.stage_id', '=', 'stages.id');

        // Apply date filters if provided
        if ($startDate && $endDate) {
            $query->whereBetween('leads.created_at', [$startDate, $endDate]);
        }

        $totalLeads = $query->count();
        $convertedLeads = $query->where('stages.type', 'conversion')->count();

        if ($totalLeads === 0) {
            return 0.0;
        }

        return ($convertedLeads / $totalLeads) * 100;
    }

    /**
     * Get monthly lead creation data for current year.
     */
    private function getMonthlyLeadData(int $companyId): array
    {
        $currentYear = Carbon::now()->year;
        $months = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
        ];

        // Get lead counts by month for current year
        $leadCountsByMonth = Lead::fromCompany($companyId)
            ->whereYear('created_at', $currentYear)
            ->select(DB::raw('MONTH(created_at) as month'), DB::raw('COUNT(*) as leads'))
            ->groupBy(DB::raw('MONTH(created_at)'))
            ->pluck('leads', 'month')
            ->toArray();

        // Format the data
        $monthlyData = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthlyData[] = [
                'month' => $months[$month],
                'leads' => $leadCountsByMonth[$month] ?? 0,
            ];
        }

        return $monthlyData;
    }

    /**
     * Calculate month-over-month performance comparison.
     */
    private function calculatePerformanceComparison(
        array $currentStats,
        array $lastMonthStats,
        float $currentConversionRate,
        float $lastMonthConversionRate
    ): array {
        return [
            'total_leads' => $this->calculateMetricComparison(
                $currentStats['total_leads'],
                $lastMonthStats['total_leads'],
                'higher_is_better'
            ),
            'converted_leads' => $this->calculateMetricComparison(
                $currentStats['converted_leads'],
                $lastMonthStats['converted_leads'],
                'higher_is_better'
            ),
            'total_leads_lost' => $this->calculateMetricComparison(
                $currentStats['total_leads_lost'],
                $lastMonthStats['total_leads_lost'],
                'lower_is_better'
            ),
            'qualified_leads' => $this->calculateMetricComparison(
                $currentStats['qualified_leads'],
                $lastMonthStats['qualified_leads'],
                'higher_is_better'
            ),
            'conversion_rate' => $this->calculateMetricComparison(
                $currentConversionRate,
                $lastMonthConversionRate,
                'higher_is_better'
            ),
        ];
    }

    /**
     * Calculate metric comparison between current and previous period.
     */
    private function calculateMetricComparison(
        float $currentValue,
        float $previousValue,
        string $improvementDirection
    ): array {
        if ($previousValue == 0) {
            $percentageChange = $currentValue > 0 ? 100.0 : 0.0;
        } else {
            $percentageChange = (($currentValue - $previousValue) / $previousValue) * 100;
        }

        // Determine if the change represents improvement
        $isImprovement = match($improvementDirection) {
            'higher_is_better' => $percentageChange > 0,
            'lower_is_better' => $percentageChange < 0,
            default => false
        };

        return [
            'current_value' => round($currentValue, 2),
            'previous_value' => round($previousValue, 2),
            'percentage_change' => round($percentageChange, 2),
            'performance_indicator' => $isImprovement ? 'improvement' : 'worsening',
            'change_direction' => $percentageChange > 0 ? 'increase' : ($percentageChange < 0 ? 'decrease' : 'no_change'),
        ];
    }

    /**
     * Get lead statistics for a specific period (helper method).
     */
    public function getLeadStatsPeriod(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;
        
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();

        $stats = $this->getLeadStatistics($companyId, $startDate, $endDate);
        $conversionRate = $this->getConversionRate($companyId, $startDate, $endDate);

        return response()->json([
            'data' => [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
                'lead_statistics' => $stats,
                'conversion_rate' => round($conversionRate, 2),
            ],
        ]);
    }

    /**
     * Get funnel performance metrics.
     */
    public function getFunnelMetrics(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        // Get funnel performance data
        $funnelMetrics = DB::table('leads')
            ->join('funnels', 'leads.funnel_id', '=', 'funnels.id')
            ->join('stages', 'leads.stage_id', '=', 'stages.id')
            ->where('leads.company_id', $companyId)
            ->select(
                'funnels.id as funnel_id',
                'funnels.name as funnel_name',
                'stages.type as stage_type',
                DB::raw('COUNT(*) as lead_count')
            )
            ->groupBy('funnels.id', 'funnels.name', 'stages.type')
            ->get()
            ->groupBy('funnel_id');

        $formattedMetrics = [];
        foreach ($funnelMetrics as $funnelId => $metrics) {
            $funnelData = [
                'funnel_id' => $funnelId,
                'funnel_name' => $metrics->first()->funnel_name,
                'total_leads' => $metrics->sum('lead_count'),
                'stage_breakdown' => $metrics->pluck('lead_count', 'stage_type')->toArray(),
            ];
            
            // Calculate funnel-specific conversion rate
            $totalLeads = $funnelData['total_leads'];
            $convertedLeads = $funnelData['stage_breakdown']['conversion'] ?? 0;
            $funnelData['conversion_rate'] = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 2) : 0;
            
            $formattedMetrics[] = $funnelData;
        }

        return response()->json([
            'data' => $formattedMetrics,
        ]);
    }

    /**
     * Get user performance metrics.
     */
    public function getUserMetrics(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        // Get user performance data
        $userMetrics = DB::table('leads')
            ->join('users', 'leads.assigned_to', '=', 'users.id')
            ->join('stages', 'leads.stage_id', '=', 'stages.id')
            ->where('leads.company_id', $companyId)
            ->whereNotNull('leads.assigned_to')
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                'stages.type as stage_type',
                DB::raw('COUNT(*) as lead_count')
            )
            ->groupBy('users.id', 'users.name', 'stages.type')
            ->get()
            ->groupBy('user_id');

        $formattedMetrics = [];
        foreach ($userMetrics as $userId => $metrics) {
            $userData = [
                'user_id' => $userId,
                'user_name' => $metrics->first()->user_name,
                'total_assigned_leads' => $metrics->sum('lead_count'),
                'stage_breakdown' => $metrics->pluck('lead_count', 'stage_type')->toArray(),
            ];
            
            // Calculate user-specific conversion rate
            $totalLeads = $userData['total_assigned_leads'];
            $convertedLeads = $userData['stage_breakdown']['conversion'] ?? 0;
            $userData['conversion_rate'] = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 2) : 0;
            
            $formattedMetrics[] = $userData;
        }

        return response()->json([
            'data' => $formattedMetrics,
        ]);
    }

    /**
     * Get real-time dashboard summary.
     */
    public function getSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $summary = [
            'total_leads_today' => Lead::fromCompany($companyId)->whereDate('created_at', today())->count(),
            'total_leads_this_week' => Lead::fromCompany($companyId)->whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ])->count(),
            'total_leads_this_month' => Lead::fromCompany($companyId)->whereMonth('created_at', Carbon::now()->month)->count(),
            'conversion_rate_this_month' => $this->getConversionRate($companyId),
            'active_funnels' => $user->company->activeFunnels()->count(),
            'active_users' => $user->company->activeUsers()->count(),
        ];

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * Get revenue metrics based on company's active module.
     * Returns data only if the module supports revenue tracking.
     */
    private function getRevenueMetricsByModule($company): array
    {
        // Check if the company is using the product module
        if ($company->product_module !== 'product') {
            return [
                'available' => false,
                'message' => 'Revenue metrics are not available for the current module configuration.',
                'active_module' => $company->product_module,
            ];
        }

        // Fetch revenue metrics based on active module
        $metrics = $this->getRevenueDataByModule($company->id, $company->product_module);

        return [
            'available' => true,
            'module' => $company->product_module,
            'metrics' => $metrics,
        ];
    }

    /**
     * Get revenue data based on the active module.
     * This method routes to the appropriate data source.
     */
    private function getRevenueDataByModule(int $companyId, string $module): array
    {
        return match ($module) {
            'product' => $this->getProductModuleRevenue($companyId),
            'ERP' => $this->getERPModuleRevenue($companyId),
            default => [
                'error' => 'Unsupported module type',
                'module' => $module,
            ],
        };
    }

    /**
     * Get revenue metrics for the Product module.
     * Revenue is calculated from Sales with status = 'closed'.
     */
    private function getProductModuleRevenue(int $companyId): array
    {
        $currentYear = Carbon::now()->year;
        $currentMonth = Carbon::now()->month;

        // 1. Total Annual Revenue (Current Year)
        $totalAnnualRevenue = Sale::where('company_id', $companyId)
            ->where('status', 'closed')
            ->whereYear('closed_at', $currentYear)
            ->sum(DB::raw('`total`'));

        // 2. Total Monthly Revenue (Current Month)
        $totalMonthlyRevenue = Sale::where('company_id', $companyId)
            ->where('status', 'closed')
            ->whereYear('closed_at', $currentYear)
            ->whereMonth('closed_at', $currentMonth)
            ->sum(DB::raw('`total`'));

        // 3. Average Deal Value (Closed Sales Only)
        $closedSalesCount = Sale::where('company_id', $companyId)
            ->where('status', 'closed')
            ->count();

        $averageDealValue = $closedSalesCount > 0
            ? $totalAnnualRevenue / $closedSalesCount
            : 0;

        // 4. Monthly Closed vs Lost Deals Analysis (Current Year Only)
        $monthlyDealsAnalysis = $this->getMonthlyClosedVsLostAnalysis($companyId);

        // 5. Deals Status Distribution
        $dealsDistribution = $this->getDealsStatusDistribution($companyId);

        return [
            'total_annual_revenue' => round($totalAnnualRevenue, 2),
            'total_monthly_revenue' => round($totalMonthlyRevenue, 2),
            'average_deal_value' => round($averageDealValue, 2),
            'closed_deals_count' => $closedSalesCount,
            'monthly_deals_analysis' => $monthlyDealsAnalysis,
            'deals_status_distribution' => $dealsDistribution,
            'period' => [
                'current_year' => $currentYear,
                'current_month' => Carbon::now()->format('F Y'),
            ],
        ];
    }

    /**
     * Get revenue metrics for the ERP module.
     * Placeholder for future implementation.
     */
    private function getERPModuleRevenue(int $companyId): array
    {
        // Future implementation: Revenue from invoices, payments, or financial ledger
        return [
            'message' => 'ERP module revenue metrics are not yet implemented',
            'total_annual_revenue' => 0,
            'total_monthly_revenue' => 0,
            'average_deal_value' => 0,
            'monthly_deals_analysis' => [],
            'deals_status_distribution' => [],
        ];
    }

    /**
     * Get monthly closed vs lost deals analysis for current year.
     * Includes average monthly revenue and average lost revenue.
     */
    private function getMonthlyClosedVsLostAnalysis(int $companyId): array
    {
        $currentYear = Carbon::now()->year;
        $months = [
            1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april',
            5 => 'may', 6 => 'june', 7 => 'july', 8 => 'august',
            9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december'
        ];

        // Get closed deals by month
        $closedDeals = Sale::where('company_id', $companyId)
            ->where('status', 'closed')
            ->whereYear('closed_at', $currentYear)
            ->select(
                DB::raw('MONTH(closed_at) as month'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total) as total_value')
            )
            ->groupBy(DB::raw('MONTH(closed_at)'))
            ->get()
            ->keyBy('month');

        // Get lost deals by month
        $lostDeals = Sale::where('company_id', $companyId)
            ->where('status', 'lost')
            ->whereYear('lost_at', $currentYear)
            ->select(
                DB::raw('MONTH(lost_at) as month'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total) as total_value')
            )
            ->groupBy(DB::raw('MONTH(lost_at)'))
            ->get()
            ->keyBy('month');

        // Build monthly comparison
        $monthlyData = [];
        $totalClosedRevenue = 0;
        $totalLostRevenue = 0;
        $monthsWithClosedDeals = 0;
        $monthsWithLostDeals = 0;

        for ($month = 1; $month <= 12; $month++) {
            $monthName = $months[$month];
            
            $closedData = $closedDeals->get($month);
            $lostData = $lostDeals->get($month);

            $closedValue = $closedData ? $closedData->total_value : 0;
            $lostValue = $lostData ? $lostData->total_value : 0;

            $monthlyData[$monthName] = [
                'closed' => [
                    'count' => $closedData ? $closedData->count : 0,
                    'total' => round($closedValue, 2),
                ],
                'lost' => [
                    'count' => $lostData ? $lostData->count : 0,
                    'total' => round($lostValue, 2),
                ],
            ];

            // Accumulate totals for averages
            if ($closedValue > 0) {
                $totalClosedRevenue += $closedValue;
                $monthsWithClosedDeals++;
            }
            if ($lostValue > 0) {
                $totalLostRevenue += $lostValue;
                $monthsWithLostDeals++;
            }
        }

        // Calculate average monthly revenue (only for months with closed deals)
        $averageMonthlyRevenue = $monthsWithClosedDeals > 0
            ? round($totalClosedRevenue / $monthsWithClosedDeals, 2)
            : 0;

        // Calculate average lost revenue (only for months with lost deals)
        $averageLostRevenue = $monthsWithLostDeals > 0
            ? round($totalLostRevenue / $monthsWithLostDeals, 2)
            : 0;

        return [
            'monthly_data' => $monthlyData,
            'average_monthly_revenue' => $averageMonthlyRevenue,
            'average_lost_revenue' => $averageLostRevenue,
            'year' => $currentYear,
        ];
    }

    /**
     * Get deals status distribution with counts and percentages.
     * Includes all sales regardless of status.
     */
    private function getDealsStatusDistribution(int $companyId): array
    {
        $statuses = ['pending', 'sent', 'closed', 'lost'];

        // Get total sales count
        $totalSales = Sale::where('company_id', $companyId)->count();

        if ($totalSales === 0) {
            return [
                'total_deals' => 0,
                'distribution' => array_fill_keys($statuses, [
                    'count' => 0,
                    'percentage' => 0,
                ]),
            ];
        }

        // Get counts by status
        $statusCounts = Sale::where('company_id', $companyId)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Build distribution with percentages
        $distribution = [];
        foreach ($statuses as $status) {
            $count = $statusCounts[$status] ?? 0;
            $percentage = ($count / $totalSales) * 100;

            $distribution[$status] = [
                'count' => $count,
                'percentage' => round($percentage, 2),
            ];
        }

        return [
            'total_deals' => $totalSales,
            'distribution' => $distribution,
        ];
    }

    /**
     * Get leads distribution by funnel stage, scoped to the selected period.
     * Shows lead count and percentage per stage in each funnel.
     */
    private function getLeadsByFunnelStage(int $companyId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $funnels = Funnel::where(['company_id' => $companyId, 'type' => 'sale'])
            ->with('stages')
            ->get();

        $funnelAnalysis = [];

        foreach ($funnels as $funnel) {
            $baseQuery = fn() => Lead::where('funnel_id', $funnel->id)
                ->where('company_id', $companyId)
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]));

            $totalLeadsInFunnel = $baseQuery()->count();

            $stagesData = [];

            foreach ($funnel->stages as $stage) {
                $leadsInStage = $baseQuery()
                    ->where('stage_id', $stage->id)
                    ->count();

                $percentage = $totalLeadsInFunnel > 0
                    ? round(($leadsInStage / $totalLeadsInFunnel) * 100, 2)
                    : 0;

                $stagesData[] = [
                    'stage_id'   => $stage->id,
                    'stage_name' => $stage->name,
                    'stage_type' => $stage->type ?? null,
                    'leads'      => $leadsInStage,
                    'percentage' => $percentage,
                ];
            }

            $funnelAnalysis[] = [
                'funnel_id'   => $funnel->id,
                'funnel_name' => $funnel->name,
                'total_leads' => $totalLeadsInFunnel,
                'stages'      => $stagesData,
            ];
        }

        return [
            'available'     => true,
            'funnels'       => $funnelAnalysis,
            'total_funnels' => count($funnelAnalysis),
        ];
    }

    /**
     * Get sales distribution by funnel stage, scoped to the selected period.
     * Shows sales count and percentage per stage in each deal funnel.
     */
    private function getSalesByFunnelStage(int $companyId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $funnels = Funnel::where(['company_id' => $companyId, 'type' => 'deal'])
            ->with('stages')
            ->get();

        $funnelAnalysis = [];

        foreach ($funnels as $funnel) {
            $baseQuery = fn() => Sale::where('funnel_id', $funnel->id)
                ->where('company_id', $companyId)
                ->when($startDate && $endDate, fn($q) => $q->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('created_at', [$startDate, $endDate])
                      ->orWhereBetween('closed_at', [$startDate, $endDate])
                      ->orWhereBetween('lost_at', [$startDate, $endDate]);
                }));

            $totalSalesInFunnel = $baseQuery()->count();

            $stagesData = [];

            foreach ($funnel->stages as $stage) {
                $salesInStage = $baseQuery()
                    ->where('stage_id', $stage->id)
                    ->count();

                $percentage = $totalSalesInFunnel > 0
                    ? round(($salesInStage / $totalSalesInFunnel) * 100, 2)
                    : 0;

                $stagesData[] = [
                    'stage_id'   => $stage->id,
                    'stage_name' => $stage->name,
                    'stage_type' => $stage->type ?? null,
                    'sales'      => $salesInStage,
                    'percentage' => $percentage,
                ];
            }

            $funnelAnalysis[] = [
                'funnel_id'   => $funnel->id,
                'funnel_name' => $funnel->name,
                'total_sales' => $totalSalesInFunnel,
                'stages'      => $stagesData,
            ];
        }

        return [
            'available'     => true,
            'funnels'       => $funnelAnalysis,
            'total_funnels' => count($funnelAnalysis),
        ];
    }

    /**
     * Get estimated value vs closed value analysis per funnel.
     * Only available for product module.
     */
    private function getEstimatedVsClosedValue($company, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        // Check if module supports this analysis
        if ($company->product_module !== 'product') {
            return [
                'available' => false,
                'message' => 'Estimated vs closed value analysis is only available for product module.',
                'active_module' => $company->product_module,
            ];
        }

        $companyId = $company->id;

        // Get all funnels with their stages and leads (with products)
        $funnels = Funnel::where('company_id', $companyId)
            ->with(['stages.leads.products'])
            ->get();

        $funnelValueAnalysis = [];

        foreach ($funnels as $funnel) {
            $funnelEstimatedValue = 0;

            // Calculate estimated value from all leads in funnel
            foreach ($funnel->stages as $stage) {
                foreach ($stage->leads as $lead) {
                    // Calculate lead estimated value from products
                    $leadEstimatedValue = $lead->products->sum(function ($product) {
                        return (float) ($product->pivot->total_price ?? 0);
                    });

                    $funnelEstimatedValue += $leadEstimatedValue;
                }
            }

            // Calculate closed value from sales using closed_at for period scoping
            $closedQuery = Sale::where('company_id', $companyId)
                ->where('status', 'closed')
                ->whereHas('lead', function ($query) use ($funnel) {
                    $query->where('funnel_id', $funnel->id);
                });

            if ($startDate && $endDate) {
                $closedQuery->whereBetween('closed_at', [$startDate, $endDate]);
            }

            $closedValue = $closedQuery->sum('total');

            // Calculate conversion percentage
            $conversionPercentage = $funnelEstimatedValue > 0
                ? round(($closedValue / $funnelEstimatedValue) * 100, 2)
                : 0;

            // Calculate gap percentage
            $gapPercentage = $funnelEstimatedValue > 0
                ? round(100 - $conversionPercentage, 2)
                : 0;

            $funnelValueAnalysis[] = [
                'funnel_id' => $funnel->id,
                'funnel_name' => $funnel->name,
                'estimated_value' => round($funnelEstimatedValue, 2),
                'closed_value' => round($closedValue, 2),
                'conversion_percentage' => $conversionPercentage,
                'gap_percentage' => $gapPercentage,
            ];
        }

        return [
            'available' => true,
            'funnels' => $funnelValueAnalysis,
            'total_funnels' => count($funnelValueAnalysis),
            'summary' => [
                'total_estimated_value' => round(array_sum(array_column($funnelValueAnalysis, 'estimated_value')), 2),
                'total_closed_value' => round(array_sum(array_column($funnelValueAnalysis, 'closed_value')), 2),
            ],
        ];
    }

    public function planUsage()
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $company = $user->company;

        /*
        |--------------------------------------------------------------------------
        | LEADS
        |--------------------------------------------------------------------------
        */
        $leadsUsed = \App\Models\Lead::where('company_id', $company->id)->count();

        /*
        |--------------------------------------------------------------------------
        | USERS
        |--------------------------------------------------------------------------
        */
        $usersUsed = \App\Models\User::where('company_id', $company->id)->count();

        /*
        |--------------------------------------------------------------------------
        | PRODUCTS
        |--------------------------------------------------------------------------
        */
        $productsUsed = \App\Models\Product::where('company_id', $company->id)->count();

        /*
        |--------------------------------------------------------------------------
        | STORAGE (GB)
        |--------------------------------------------------------------------------
        */
        $storageUsed = $company->storage_use ?? 0;

        /*
        |--------------------------------------------------------------------------
        | ACTIVE DEALS
        |--------------------------------------------------------------------------
        */
        $activeDealsUsed = \App\Models\Sale::where('company_id', $company->id)
            ->where('status', '!=', 'closed')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | TASKS
        |--------------------------------------------------------------------------
        */
        $tasksUsed = \App\Models\Task::whereHas('lead', function ($q) use ($company) {
            $q->where('company_id', $company->id);
        })->count();

        /*
        |--------------------------------------------------------------------------
        | INTEGRATIONS
        |--------------------------------------------------------------------------
        */
        $integrations = [
            'n8n'         => \App\Models\N8nIntegration::class,
            'meta_ads'   => \App\Models\MetaAdsIntegration::class,
            'google_ads' => \App\Models\GoogleAdsIntegration::class,
            'wpp_evo'    => \App\Models\WhatsAppEvoIntegration::class,
        ];

        $integrationsUsed = 0;

        foreach ($integrations as $model) {
            if ($model::where('company_id', $company->id)->active()->exists()) {
                $integrationsUsed++;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SALES FUNNELS
        |--------------------------------------------------------------------------
        */
        $salesFunnelsUsed = \App\Models\Funnel::where('company_id', $company->id)
            ->where('type', 'sale')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | DEAL FUNNELS
        |--------------------------------------------------------------------------
        */
        $dealFunnelsUsed = \App\Models\Funnel::where('company_id', $company->id)
            ->where('type', 'deal')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | LEAD COMPANIES
        |--------------------------------------------------------------------------
        */
        $companiesUsed = \App\Models\LeadCompany::where('company_id', $company->id)->count();

        /*
        |--------------------------------------------------------------------------
        | MESSAGES (EXTENSIBLE AGGREGATION)
        |--------------------------------------------------------------------------
        |
        | Each provider is a closure responsible for returning a count
        | scoped by company_id. New providers can be added without
        | changing the validation contract.
        |
        */
        $messageProviders = [

            // WhatsApp Evo
            function (int $companyId) {
                return \App\Models\WhatsappEvoChat::where('company_id', $companyId)
                    ->withCount('messages')
                    ->get()
                    ->sum('messages_count');
            },

            function (int $companyId) {
                return \App\Models\Conversation::where('company_id', $companyId)
                    ->withCount('messages')
                    ->get()
                    ->sum('messages_count');
            },

            // Future providers (examples):
            // function (int $companyId) {
            //     return \App\Models\FacebookChat::where('company_id', $companyId)
            //         ->withCount('messages')
            //         ->get()
            //         ->sum('messages_count');
            // },
        ];

        $messagesUsed = 0;

        foreach ($messageProviders as $provider) {
            $messagesUsed += $provider($company->id);
        }

        /*
        |--------------------------------------------------------------------------
        | Helper for limit calculation
        |--------------------------------------------------------------------------
        */
        $calculate = function ($used, $limit) {
            if (is_null($limit)) {
                return [
                    'used' => $used,
                    'limit' => null,
                    'remaining' => null,
                    'percentage' => null,
                    'is_unlimited' => true,
                ];
            }

            $remaining = max($limit - $used, 0);

            return [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
                'percentage' => $limit > 0
                    ? round(($used / $limit) * 100, 2)
                    : 0,
                'is_unlimited' => false,
            ];
        };

        /*
        |--------------------------------------------------------------------------
        | FINAL RESPONSE
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'subscription_plan' => $company->subscription_plan,

            'leads'          => $calculate($leadsUsed, $company->leads_limit),
            'users'          => $calculate($usersUsed, $company->users_limit),
            'products'       => $calculate($productsUsed, $company->products_limit),
            'storage'        => $calculate($storageUsed, $company->storage_limit_gb),
            'active_deals'   => $calculate($activeDealsUsed, $company->active_deals_limit),
            'tasks'          => $calculate($tasksUsed, $company->tasks_limit),
            'integrations'   => $calculate($integrationsUsed, $company->integrations_limit),

            // NEW LIMITS
            'sales_funnels'  => $calculate($salesFunnelsUsed, $company->sales_funnels_limit),
            'deal_funnels'   => $calculate($dealFunnelsUsed, $company->deal_funnels_limit),
            'companies'      => $calculate($companiesUsed, $company->companies_limit),
            'messages'       => $calculate($messagesUsed, $company->messages_limit),
        ]);
    }

}