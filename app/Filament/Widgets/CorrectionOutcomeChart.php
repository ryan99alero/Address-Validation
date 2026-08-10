<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CorrectionOutcomeService;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The factual scoreboard: of the addresses we corrected that actually SHIPPED and were INVOICED,
 * how many dodged the fee (Prevented), got hit but were billed back (Recouped), or got hit and
 * weren't (Charged). Only invoiced shipments count, so every bar is a fact — and the three series
 * stack to that period's invoiced-corrected total. Respects the dashboard period filter.
 */
class CorrectionOutcomeChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Correction Outcomes (invoiced shipments)';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '240px';

    protected string $view = 'filament.widgets.expandable-chart';

    private int $prevented = 0;

    private int $recouped = 0;

    private int $charged = 0;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        $series = app(CorrectionOutcomeService::class)->outcomeSeries($year, $month);

        $this->prevented = (int) $series->sum('prevented');
        $this->recouped = (int) $series->sum('recouped');
        $this->charged = (int) $series->sum('charged');

        $this->heading = 'Correction Outcomes · '.$this->periodLabel($year, $month);

        if ($year === null) {
            $labels = $series->pluck('label')->all(); // years
        } elseif ($month === null) {
            $labels = $series->map(fn ($r): string => substr(Dashboard::MONTHS[(int) $r->label] ?? $r->label, 0, 3))->all();
        } else {
            $labels = $series->map(fn ($r): int => (int) $r->label)->all();
        }

        return [
            'datasets' => [
                ['label' => 'Prevented', 'data' => $series->pluck('prevented')->all(), 'backgroundColor' => '#22c55e', 'stack' => 'outcome'],
                ['label' => 'Recouped', 'data' => $series->pluck('recouped')->all(), 'backgroundColor' => '#f59e0b', 'stack' => 'outcome'],
                ['label' => 'Charged', 'data' => $series->pluck('charged')->all(), 'backgroundColor' => '#ef4444', 'stack' => 'outcome'],
            ],
            'labels' => $labels,
        ];
    }

    public function getDescription(): ?string
    {
        $total = $this->prevented + $this->recouped + $this->charged;
        if ($total === 0) {
            return 'No invoiced corrected shipments in this period yet.';
        }

        $pct = round($this->prevented / $total * 100);

        return $total.' invoiced · '.$pct.'% prevented · '.$this->recouped.' recouped · '.$this->charged.' still charged';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true],
            ],
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
        ];
    }
}
