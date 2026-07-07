<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\Dashboard;
use App\Services\Analytics\CostAnalyticsService;

/**
 * Shared reader for the dashboard period filter (year + optional month). Widgets using this must
 * also use Filament's InteractsWithPageFilters so $this->pageFilters is populated.
 */
trait ReadsDashboardPeriod
{
    /**
     * The selected year and month. Year is null for "All years" (filter value 0); when the year
     * filter is entirely unset it falls back to the latest year with data. Month is null = full year.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function selectedPeriod(CostAnalyticsService $svc): array
    {
        $raw = $this->pageFilters['year'] ?? null;
        if ($raw === null || $raw === '') {
            $year = $svc->availableYears()[0] ?? null; // unset → default to the latest year
        } else {
            $year = (int) $raw ?: null; // 0 => "All years"
        }
        $month = (int) ($this->pageFilters['month'] ?? 0) ?: null;

        return [$year, $month];
    }

    /**
     * A short human label for a period, e.g. "2025", "Jun 2025", "All years" or "All years · Jun".
     */
    protected function periodLabel(?int $year, ?int $month): string
    {
        if ($year === null) {
            return $month !== null ? 'All years · '.substr(Dashboard::MONTHS[$month], 0, 3) : 'All years';
        }

        return $month !== null
            ? substr(Dashboard::MONTHS[$month], 0, 3).' '.$year
            : (string) $year;
    }
}
