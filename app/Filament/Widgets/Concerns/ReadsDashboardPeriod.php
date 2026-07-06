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
     * The selected year (falls back to the latest year with data) and month (null = full year).
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function selectedPeriod(CostAnalyticsService $svc): array
    {
        $year = (int) ($this->pageFilters['year'] ?? 0) ?: $svc->availableYears()[0] ?? null;
        $month = (int) ($this->pageFilters['month'] ?? 0) ?: null;

        return [$year, $month];
    }

    /**
     * A short human label for a period, e.g. "2025" or "Jun 2025".
     */
    protected function periodLabel(int $year, ?int $month): string
    {
        return $month !== null
            ? substr(Dashboard::MONTHS[$month], 0, 3).' '.$year
            : (string) $year;
    }
}
