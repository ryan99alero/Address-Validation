<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use App\Services\Analytics\HeatmapService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * US heatmap of where address corrections happen, split by carrier so regional patterns and
 * carrier differences show. UPS (warm) and FedEx (cool) are separate overlaid heat layers, each
 * toggleable via the map's layer control. Honours the dashboard year/month filter.
 */
class CorrectionHeatmap extends Widget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected string $view = 'filament.widgets.heatmap';

    // Half-width (side-by-side with the other heatmap) on wide screens; full-width and stacked on
    // smaller ones so the map stays readable. Click-to-enlarge covers the detailed view.
    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 2];

    protected static ?int $sort = 21;

    /** UPS = amber → red. */
    private const GRADIENT_UPS = ['0.3' => '#feb24c', '0.6' => '#fd8d3c', '1.0' => '#bd0026'];

    /** FedEx = blue → purple, so the two carriers read apart when overlaid. */
    private const GRADIENT_FEDEX = ['0.3' => '#9ebcda', '0.6' => '#8c6bb1', '1.0' => '#6e016b'];

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    /**
     * @return array<string, mixed>
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        [$year, $month] = $this->selectedPeriod(app(CostAnalyticsService::class));
        $svc = app(HeatmapService::class);

        return $this->resolved = [
            'year' => $year,
            'month' => $month,
            'ups' => $svc->corrections($year, $month, 'ups'),
            'fedex' => $svc->corrections($year, $month, 'fedex'),
        ];
    }

    public function heatHeading(): string
    {
        return 'Address Correction Hotspots (Map)';
    }

    public function heatDescription(): string
    {
        $r = $this->resolve();

        return 'Where UPS vs FedEx correct addresses · '.$this->periodLabel($r['year'], $r['month']).' · toggle a carrier top-right';
    }

    public function heatMapId(): string
    {
        return 'corrections';
    }

    public function heatPeriodKey(): string
    {
        $r = $this->resolve();

        return ($r['year'] ?? 'all').'-'.($r['month'] ?? 'all');
    }

    /**
     * @return array<string, mixed>
     */
    public function heatMapConfig(): array
    {
        $r = $this->resolve();

        return [
            'center' => [39.5, -98.35],
            'zoom' => 4,
            'tiles' => [
                'url' => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
                'attribution' => '&copy; OpenStreetMap &copy; CARTO',
            ],
            'layers' => [
                ['name' => 'UPS', 'points' => $r['ups']['points'], 'max' => $r['ups']['meta']['max'], 'gradient' => self::GRADIENT_UPS],
                ['name' => 'FedEx', 'points' => $r['fedex']['points'], 'max' => $r['fedex']['meta']['max'], 'gradient' => self::GRADIENT_FEDEX],
            ],
        ];
    }

    public function heatUnit(): string
    {
        return 'corrections';
    }

    /**
     * @return list<array{label: string, stops: list<string>, max: float, plotted: int, zips: int, unmapped: int}>
     */
    public function heatLegends(): array
    {
        $r = $this->resolve();

        return [
            $this->legendRow('UPS', self::GRADIENT_UPS, $r['ups']),
            $this->legendRow('FedEx', self::GRADIENT_FEDEX, $r['fedex']),
        ];
    }

    /**
     * @param  array<string, string>  $gradient
     * @param  array{points: array<int, mixed>, meta: array<string, mixed>}  $data
     * @return array{label: string, stops: list<string>, max: float, plotted: int, zips: int, unmapped: int}
     */
    private function legendRow(string $label, array $gradient, array $data): array
    {
        return [
            'label' => $label,
            'stops' => array_values($gradient),
            'max' => (float) $data['meta']['max'],
            'plotted' => (int) $data['meta']['matched'],
            'zips' => count($data['points']),
            'unmapped' => (int) ($data['meta']['unmatched'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function heatMeta(): array
    {
        $r = $this->resolve();

        return [
            'matched' => $r['ups']['meta']['matched'] + $r['fedex']['meta']['matched'],
            'unmatched' => $r['ups']['meta']['unmatched'] + $r['fedex']['meta']['unmatched'],
            'max' => max($r['ups']['meta']['max'], $r['fedex']['meta']['max']),
        ];
    }
}
