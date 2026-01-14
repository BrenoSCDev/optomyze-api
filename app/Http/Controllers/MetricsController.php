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
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $companyId = $company->id;
        
        // Get current and last month dates
        $currentMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfCurrentMonth = Carbon::now()->endOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

        // 1. Lead Statistics by Stage Type
        $leadStats = $this->getLeadStatistics($companyId);
        $lastMonthLeadStats = $this->getLeadStatistics($companyId, $lastMonth, $endOfLastMonth);

        // 2. Conversion Rate
        $conversionRate = $this->getConversionRate($companyId);
        $lastMonthConversionRate = $this->getConversionRate($companyId, $lastMonth, $endOfLastMonth);

        // 3. Monthly Lead Creation Data (Current Year)
        $monthlyLeadData = $this->getMonthlyLeadData($companyId);

        // 4. Month-over-Month Performance Comparison
        $performance = $this->calculatePerformanceComparison(
            $leadStats,
            $lastMonthLeadStats,
            $conversionRate,
            $lastMonthConversionRate
        );

        // 5. Revenue Metrics (Module-aware)
        $revenueMetrics = $this->getRevenueMetricsByModule($company);

        // 6. Leads by Funnel Stage Analysis
        $leadsByFunnelStage = $this->getLeadsByFunnelStage($companyId);

        // 7. Estimated vs Closed Value Analysis (Product Module Only)
        $estimatedVsClosedValue = $this->getEstimatedVsClosedValue($company);

        return response()->json([
            'data' => [
                'lead_statistics' => $leadStats,
                'conversion_rate' => round($conversionRate, 2),
                'monthly_lead_data' => $monthlyLeadData,
                'performance_comparison' => $performance,
                'revenue_metrics' => $revenueMetrics,
                'leads_by_funnel_stage' => $leadsByFunnelStage,
                'estimated_vs_closed_value' => $estimatedVsClosedValue,
                'summary' => [
                    'period' => [
                        'current_month' => $currentMonth->format('F Y'),
                        'last_month' => $lastMonth->format('F Y'),
                    ],
                    'company_id' => $companyId,
                    'generated_at' => now()->toISOString(),
                ],
            ],
        ]);
    }

    /**
     * Get lead statistics by stage type.
     */
    private function getLeadStatistics(int $companyId, Carbon $startDate = null, Carbon $endDate = null): array
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
            'total_leads' => $totalLeads,
            'total_leads_lost' => $leadsByType['lost'] ?? 0,
            'converted_leads' => $leadsByType['conversion'] ?? 0,
            'in_service' => $leadsByType['service'] ?? 0,
            'in_pipeline' => $leadsByType['pipeline'] ?? 0,
            'breakdown' => $leadsByType,
        ];
    }

    /**
     * Calculate conversion rate.
     */
    private function getConversionRate(int $companyId, Carbon $startDate = null, Carbon $endDate = null): float
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
            'in_service' => $this->calculateMetricComparison(
                $currentStats['in_service'],
                $lastMonthStats['in_service'],
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
     * Get leads distribution by funnel stage.
     * Shows lead count and percentage per stage in each funnel.
     */
    private function getLeadsByFunnelStage(int $companyId): array
    {
        // Get all funnels with their stages and leads (eager loading)
    $funnels = Funnel::where('company_id', $companyId)
        ->with(['stages' => function ($query) use ($companyId) {
            $query->with(['leads' => function ($leadsQuery) use ($companyId) {
                $leadsQuery->where('company_id', $companyId)
                    ->select('id', 'stage_id', 'funnel_id', 'company_id');
            }]);
        }])
        ->get();


        $funnelAnalysis = [];

        foreach ($funnels as $funnel) {
            // Count total leads in funnel
            $totalLeadsInFunnel = Lead::where('funnel_id', $funnel->id)
                ->where('company_id', $companyId)
                ->count();

            $stagesData = [];

            foreach ($funnel->stages as $stage) {
                $leadsInStage = Lead::where('stage_id', $stage->id)
                    ->where('funnel_id', $funnel->id)
                    ->where('company_id', $companyId)
                    ->count();

                // Calculate percentage
                $percentage = $totalLeadsInFunnel > 0
                    ? round(($leadsInStage / $totalLeadsInFunnel) * 100, 2)
                    : 0;

                $stagesData[] = [
                    'stage_id' => $stage->id,
                    'stage_name' => $stage->name,
                    'stage_type' => $stage->type ?? null,
                    'leads' => $leadsInStage,
                    'percentage' => $percentage,
                ];
            }

            $funnelAnalysis[] = [
                'funnel_id' => $funnel->id,
                'funnel_name' => $funnel->name,
                'total_leads' => $totalLeadsInFunnel,
                'stages' => $stagesData,
            ];
        }

        return [
            'available' => true,
            'funnels' => $funnelAnalysis,
            'total_funnels' => count($funnelAnalysis),
        ];
    }

    /**
     * Get estimated value vs closed value analysis per funnel.
     * Only available for product module.
     */
    private function getEstimatedVsClosedValue($company): array
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

            // Calculate closed value from sales
            $closedValue = Sale::where('company_id', $companyId)
                ->where('status', 'closed')
                ->whereHas('lead', function ($query) use ($funnel) {
                    $query->where('funnel_id', $funnel->id);
                })
                ->sum('total');

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
            'n8n'        => \App\Models\N8nIntegration::class,
            'meta_ads'  => \App\Models\MetaAdsIntegration::class,
            'google_ads'=> \App\Models\GoogleAdsIntegration::class,
            'wpp_evo'   => \App\Models\WhatsAppEvoIntegration::class,
        ];

        $integrationsUsed = 0;

        foreach ($integrations as $model) {
            if ($model::where('company_id', $company->id)->active()->exists()) {
                $integrationsUsed++;
            }
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

            'leads' => $calculate($leadsUsed, $company->leads_limit),
            'users' => $calculate($usersUsed, $company->users_limit),
            'products' => $calculate($productsUsed, $company->products_limit),
            'storage' => $calculate($storageUsed, $company->storage_limit_gb),
            'active_deals' => $calculate($activeDealsUsed, $company->active_deals_limit),
            'tasks' => $calculate($tasksUsed, $company->tasks_limit),
            'integrations' => $calculate($integrationsUsed, $company->integrations_limit),
        ]);
    }

}