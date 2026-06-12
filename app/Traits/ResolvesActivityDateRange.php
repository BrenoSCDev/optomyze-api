<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Http\Request;

trait ResolvesActivityDateRange
{
    protected const ACTIVITY_RESULT_CAP = 500;

    /**
     * Resolve the `date_range` (and optional `date_from`/`date_to` for `custom`)
     * query params into a [start, end] Carbon pair.
     */
    private function resolveActivityDateRange(Request $request): array
    {
        $now   = Carbon::now();
        $range = $request->query('date_range', 'today');

        return match ($range) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7_days'    => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            '30_days'   => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            'custom'    => $this->resolveCustomActivityRange($request, $now),
            default     => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    private function resolveCustomActivityRange(Request $request, Carbon $now): array
    {
        $from = $request->query('date_from');
        $to   = $request->query('date_to');

        $start = $from ? Carbon::parse($from)->startOfDay() : $now->copy()->startOfDay();
        $end   = $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }
}
