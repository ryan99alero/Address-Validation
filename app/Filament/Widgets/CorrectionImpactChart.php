<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Models\SystemLog;
use App\Services\Analytics\CostAnalyticsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

/**
 * Prevent zone: how much of our carrier spend bleeds to AVOIDABLE correction fees — address
 * corrections + residential re-class adjustments — as a $ amount (bars) and as a % of total spend
 * (line). This is the "we pay X% on top of shipping to correctable fees" number the address engine
 * exists to drive down; the description also surfaces how many corrections we've pushed live (not
 * dry-run) in the period. Respects the dashboard period filter: all years → yearly, a year →
 * monthly, a year+month → daily.
 */
class CorrectionImpactChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Correctable Fee Load';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '240px';

    protected string $view = 'filament.widgets.expandable-chart';

    private ?object $summary = null;

    private int $livePushes = 0;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(CostAnalyticsService::class);
        [$year, $month] = $this->selectedPeriod($svc);

        $this->summary = $svc->correctableFeeLoad($year, $month);
        $this->livePushes = $this->livePushCount($year, $month);
        $series = $svc->correctableFeeLoadSeries($year, $month);

        if ($year === null) {
            $labels = $series->pluck('label')->all();
            $this->heading = 'Correctable Fee Load · by Year';
        } elseif ($month === null) {
            $labels = $series->map(fn ($r): string => substr(Dashboard::MONTHS[(int) $r->label], 0, 3))->all();
            $this->heading = 'Correctable Fee Load · '.$year;
        } else {
            $labels = $series->map(fn ($r): int => (int) $r->label)->all();
            $this->heading = 'Correctable Fee Load · '.substr(Dashboard::MONTHS[$month], 0, 3).' '.$year;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Avoidable fees $',
                    'data' => $series->pluck('avoidable')->all(),
                    'type' => 'bar',
                    'backgroundColor' => '#ef4444',
                    'yAxisID' => 'y',
                    'order' => 2,
                ],
                [
                    'label' => '% of spend',
                    'data' => $series->pluck('load_pct')->all(),
                    'type' => 'line',
                    'borderColor' => '#6366f1',
                    'backgroundColor' => '#6366f1',
                    'yAxisID' => 'y1',
                    'order' => 1,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    public function getDescription(): ?string
    {
        $s = $this->summary;
        if (! $s) {
            return null;
        }

        return $s->load_pct.'% of spend · $'.number_format($s->avoidable).' avoidable · '
            .number_format($this->livePushes).' pushed live';
    }

    /**
     * Corrections actually written back to Pace (not dry-run) in the period. pushed_at is set only on
     * a real push, so its presence is the portable, boolean-JSON-free "live" signal.
     */
    private function livePushCount(?int $year, ?int $month): int
    {
        $query = SystemLog::query()
            ->where('type', 'pace_address_correction')
            ->whereRaw("json_extract(metadata, '\$.pushed_at') is not null");

        if ($year !== null) {
            $start = Carbon::create($year, $month ?? 1, 1)->startOfDay();
            $end = $month !== null ? $start->copy()->addMonth() : $start->copy()->addYear();
            $query->where('created_at', '>=', $start)->where('created_at', '<', $end);
        } elseif ($month !== null) {
            $query->whereRaw('substr(created_at, 6, 2) = ?', [sprintf('%02d', $month)]);
        }

        return $query->count();
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'position' => 'left',
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => '$ avoidable'],
                ],
                'y1' => [
                    'position' => 'right',
                    'beginAtZero' => true,
                    'grid' => ['drawOnChartArea' => false],
                    'title' => ['display' => true, 'text' => '% of spend'],
                ],
            ],
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
        ];
    }
}
