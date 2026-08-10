<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CorrectionOutcomeService;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The address-engine funnel broken out by sub-period (all years → by year, a year → by month, a
 * year+month → by day), on invoiced shipments only so every bar is a fact. Each bucket shows the six
 * funnel metrics as GROUPED bars (nested subsets — never stacked): Processed → Fixed → Fee avoided /
 * Charged (we fixed it) / Charged (no fix) / Billed back.
 */
class CorrectionOutcomeChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Address Correction Funnel';

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    protected ?string $maxHeight = '300px';

    protected string $view = 'filament.widgets.expandable-chart';

    /** @var array<string, int> */
    private array $totals = [];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        $series = app(CorrectionOutcomeService::class)->funnelSeries($year, $month);
        $this->heading = 'Address Correction Funnel · '.$this->periodLabel($year, $month);

        if ($year === null) {
            $labels = $series->pluck('label')->all(); // years
        } elseif ($month === null) {
            $labels = $series->map(fn ($r): string => substr(Dashboard::MONTHS[(int) $r->label] ?? $r->label, 0, 3))->all();
        } else {
            $labels = $series->map(fn ($r): int => (int) $r->label)->all();
        }

        // [legend label, row key, colour] — the six funnel stages, drawn as grouped bars per bucket.
        $metrics = [
            ['Processed', 'processed', '#94a3b8'],
            ['Fixed', 'fixed', '#6366f1'],
            ['Fee avoided', 'avoided', '#22c55e'],
            ['Charged — fixed', 'charged_fixed', '#f97316'],
            ['Charged — no fix', 'charged_nofix', '#ef4444'],
            ['Billed back', 'billed_back', '#f59e0b'],
        ];

        $this->totals = [];
        $datasets = [];
        foreach ($metrics as [$label, $key, $color]) {
            $this->totals[$key] = (int) $series->sum($key);
            $datasets[] = [
                'label' => $label,
                'data' => $series->pluck($key)->all(),
                'backgroundColor' => $color,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    public function getDescription(): ?string
    {
        if (($this->totals['processed'] ?? 0) === 0) {
            return 'No invoiced shipments we processed in this period yet.';
        }

        $charged = ($this->totals['charged_fixed'] ?? 0) + ($this->totals['charged_nofix'] ?? 0);

        return $this->totals['processed'].' processed · '.$this->totals['fixed'].' fixed · '
            .$this->totals['avoided'].' fee avoided · '.$charged.' still charged';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => false],
                'y' => ['stacked' => false, 'beginAtZero' => true],
            ],
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
        ];
    }
}
