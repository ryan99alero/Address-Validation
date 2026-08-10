<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CorrectionOutcomeService;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The address-engine funnel for the selected period, on invoiced shipments only (so every bar is a
 * fact): Processed → Address fixed → Fee avoided / Charged (we fixed it) / Charged (no fix) / Billed
 * back. Drawn as descending bars — these are nested subsets, so they are NOT stacked. Reads as a
 * drop-off: how far the big "processed" number narrows to the few that still cost us.
 */
class CorrectionOutcomeChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Address Correction Funnel';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '280px';

    protected string $view = 'filament.widgets.expandable-chart';

    private ?object $funnel = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        $this->funnel = app(CorrectionOutcomeService::class)->funnel($year, $month);
        $this->heading = 'Address Correction Funnel · '.$this->periodLabel($year, $month);

        // [label, value, colour] — descending stages; drawn as separate bars, never stacked.
        $bars = [
            ['Processed (invoiced)', $this->funnel->processed, '#94a3b8'],
            ['Address fixed', $this->funnel->fixed, '#6366f1'],
            ['Fee avoided', $this->funnel->avoided, '#22c55e'],
            ['Charged — we fixed it', $this->funnel->charged_fixed, '#f97316'],
            ['Charged — no fix', $this->funnel->charged_nofix, '#ef4444'],
            ['Billed back to job', $this->funnel->billed_back, '#f59e0b'],
        ];

        return [
            'datasets' => [[
                'label' => 'Shipments',
                'data' => array_column($bars, 1),
                'backgroundColor' => array_column($bars, 2),
            ]],
            'labels' => array_column($bars, 0),
        ];
    }

    public function getDescription(): ?string
    {
        $f = $this->funnel;
        if (! $f || $f->processed === 0) {
            return 'No invoiced shipments we processed in this period yet.';
        }

        return $f->processed.' processed · '.$f->fixed.' fixed · '.$f->avoided.' fee avoided · '
            .($f->charged_fixed + $f->charged_nofix).' still charged';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y', // horizontal bars, longest on top
            'scales' => ['x' => ['beginAtZero' => true]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
