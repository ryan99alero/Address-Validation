<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;

/**
 * Bleed zone: which accessorial categories cost the most in the selected period, base transport
 * excluded — so fuel / DAS / residential / additional handling / corrections stand out.
 *
 * Click a category bar to drill it down over time, one level finer than the current period filter:
 * all years → by year, a year → by month, a year+month → by day. "Back" returns to the mix.
 */
class FeeCategoryMixChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Accessorial Spend by Category';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '220px';

    protected string $view = 'filament.widgets.drilldown-chart';

    /**
     * The category currently drilled into (its label from the mix), or null for the category mix.
     */
    public ?string $drillCategory = null;

    /**
     * Drill into one category's spend over time. Called from the chart's bar click.
     */
    public function drillIntoCategory(string $category): void
    {
        $this->drillCategory = $category;
    }

    /**
     * Return from a category drill-down to the full category mix.
     */
    public function clearDrill(): void
    {
        $this->drillCategory = null;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        return $this->drillCategory !== null
            ? $this->drillData($svc, $year, $month)
            : $this->mixData($svc, $year, $month);
    }

    /**
     * The category mix for the period (default view).
     *
     * @return array<string, mixed>
     */
    private function mixData(CostAnalyticsService $svc, ?int $year, ?int $month): array
    {
        $mix = $svc->periodCategoryMixSplit($year, $month)->take(12);

        $this->heading = 'Accessorial Spend by Category · '.$this->periodLabel($year, $month);

        return [
            'datasets' => $this->splitDatasets($mix),
            'labels' => $mix->pluck('category')->all(),
        ];
    }

    /**
     * One category broken down over time, one level finer than the current period.
     *
     * @return array<string, mixed>
     */
    private function drillData(CostAnalyticsService $svc, ?int $year, ?int $month): array
    {
        $series = $svc->categoryTimeSeriesSplit($this->drillCategory, $year, $month);

        if ($year === null) {
            $labels = $series->pluck('label')->all(); // years
            $this->heading = $this->drillCategory.' · by Year';
        } elseif ($month === null) {
            $labels = $series->map(fn ($r): string => substr(Dashboard::MONTHS[(int) $r->label], 0, 3))->all();
            $this->heading = $this->drillCategory.' · '.$year.' by Month';
        } else {
            $labels = $series->map(fn ($r): int => (int) $r->label)->all();
            $this->heading = $this->drillCategory.' · '.substr(Dashboard::MONTHS[$month], 0, 3).' '.$year.' by Day';
        }

        return [
            'datasets' => $this->splitDatasets($series),
            'labels' => $labels,
        ];
    }

    /**
     * Two stacked datasets — spend billed on the original invoice vs post-bill adjustments —
     * from a set of driver-split rows (each carrying on_invoice + adjustment).
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function splitDatasets($rows): array
    {
        return [
            [
                'label' => 'On original invoice',
                'data' => $rows->pluck('on_invoice')->all(),
                'backgroundColor' => '#6366f1',
                'stack' => 'spend',
            ],
            [
                'label' => 'Post-bill adjustment',
                'data' => $rows->pluck('adjustment')->all(),
                'backgroundColor' => '#f59e0b',
                'stack' => 'spend',
            ],
        ];
    }

    public function getDescription(): ?string
    {
        return $this->drillCategory !== null
            ? 'One category over time — click Back to return to the mix.'
            : 'Click a category to break it down over time.';
    }

    protected function getOptions(): array
    {
        // Stacked horizontal bars: each category (or time bucket, when drilled) splits along its
        // length into on-invoice vs post-bill adjustment, so the two are comparable across rows.
        return [
            'indexAxis' => 'y', // horizontal bars — category labels are long
            'scales' => [
                'x' => ['beginAtZero' => true, 'stacked' => true],
                'y' => ['stacked' => true],
            ],
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
        ];
    }
}
