<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CorrectionOutcomeService;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The address-engine funnel by sub-period (all years → by year, a year → by month, a year+month → by
 * day), on invoiced shipments only so every bar is a fact. Five grouped bars per bucket — nested
 * subsets drawn side by side, never stacked. Recovery (billed back to the job) is surfaced in the
 * description rather than as a bar, since it is a share of the *charged* shipments, not the whole.
 */
class CorrectionOutcomeChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Address Correction Funnel';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '220px';

    protected string $view = 'filament.widgets.expandable-chart';

    /**
     * The five funnel bars: row key => [legend label, colour, hover description].
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    private array $bars = [
        'processed' => ['Shipments checked', '#94a3b8', 'Every invoiced shipment the engine looked at — whether it changed the address or passed it as already clean.'],
        'fixed' => ['Addresses we fixed', '#6366f1', 'Of those checked, the ones where the engine actually changed the address.'],
        'avoided' => ['Fees avoided', '#22c55e', 'We fixed the address and the carrier charged no address/residential fee — the win.'],
        'charged_fixed' => ['Fixed, billed anyway', '#f97316', 'We corrected the address but the carrier still billed an address/residential fee — the fix was too late or did not take.'],
        'charged_nofix' => ['Passed clean, still billed', '#ef4444', 'The engine said no change was needed, but the carrier billed an address/residential fee anyway — a miss, or a charge worth disputing.'],
    ];

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

        $datasets = [];
        foreach ($this->bars as $key => [$label, $color]) {
            $this->totals[$key] = (int) $series->sum($key);
            $datasets[] = ['label' => $label, 'data' => $series->pluck($key)->all(), 'backgroundColor' => $color];
        }
        $this->totals['billed_back'] = (int) $series->sum('billed_back');

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    /**
     * The described legend for the hover tooltips in the chart view.
     *
     * @return array<int, array{label:string, color:string, description:string}>
     */
    public function getLegendItems(): array
    {
        return array_map(
            fn (array $bar): array => ['label' => $bar[0], 'color' => $bar[1], 'description' => $bar[2]],
            array_values($this->bars),
        );
    }

    public function getDescription(): ?string
    {
        if (($this->totals['processed'] ?? 0) === 0) {
            return 'No invoiced shipments we processed in this period yet.';
        }

        $charged = ($this->totals['charged_fixed'] ?? 0) + ($this->totals['charged_nofix'] ?? 0);

        return $this->totals['processed'].' checked · '.$this->totals['fixed'].' fixed · '
            .$this->totals['avoided'].' fees avoided · '.$charged.' still billed ('
            .$this->totals['billed_back'].' recovered to the job)';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => false],
                'y' => ['stacked' => false, 'beginAtZero' => true, 'title' => ['display' => true, 'text' => 'shipments']],
            ],
            // The built-in legend is replaced by a described legend (with hover tooltips) in the view.
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
