<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Http\Request;

trait ResolvesMetaAdsDateRange
{
    protected const META_ADS_DATE_FORMAT = 'Y-m-d';

    /**
     * Resolve `date_range` (+ `date_from`/`date_to` for `custom`) into
     * [currentStart, currentEnd, previousStart, previousEnd] Carbon pairs.
     *
     * The previous period is the immediately preceding period of equal
     * length, except for `this_month`/`last_month` where it is the prior
     * full calendar month - more intuitive for month-over-month deltas.
     */
    private function resolveMetaAdsDateRange(Request $request): array
    {
        $now   = Carbon::now();
        $range = $request->query('date_range', '30_days');

        if ($range === 'this_month') {
            $start = $now->copy()->startOfMonth();
            $end   = $now->copy()->endOfDay();
            $prev  = $now->copy()->subMonthNoOverflow();

            return [$start, $end, $prev->copy()->startOfMonth(), $prev->copy()->endOfMonth()];
        }

        if ($range === 'last_month') {
            $month = $now->copy()->subMonthNoOverflow();
            $prev  = $now->copy()->subMonthsNoOverflow(2);

            return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth(), $prev->copy()->startOfMonth(), $prev->copy()->endOfMonth()];
        }

        [$start, $end] = match ($range) {
            'today'     => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7_days'    => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'custom'    => $this->resolveCustomMetaAdsRange($request, $now),
            default     => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };

        return [$start, $end, ...$this->resolvePreviousMetaAdsRange($start, $end)];
    }

    private function resolveCustomMetaAdsRange(Request $request, Carbon $now): array
    {
        $from = $request->query('date_from');
        $to   = $request->query('date_to');

        $start = $from ? Carbon::parse($from)->startOfDay() : $now->copy()->subDays(29)->startOfDay();
        $end   = $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    /**
     * Equal-length period immediately preceding [$start, $end].
     */
    private function resolvePreviousMetaAdsRange(Carbon $start, Carbon $end): array
    {
        $days = $start->diffInDays($end) + 1;

        return [
            $start->copy()->subDays($days)->startOfDay(),
            $start->copy()->subDay()->endOfDay(),
        ];
    }
}
