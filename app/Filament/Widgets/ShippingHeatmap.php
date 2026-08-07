<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use App\Services\Analytics\HeatmapService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * US heatmap of where packages shipped, from carrier_shipments destinations, resolved ZIP → centroid
 * (no geocoding). UPS and FedEx are separate overlaid heat layers, each toggleable via the map's
 * layer control. Honours the dashboard year/month filter. Count = shipments; the color scale peaks
 * at each carrier's busiest ZIP.
 */
class ShippingHeatmap extends Widget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected string $view = 'filament.widgets.heatmap';

    // Half-width (side-by-side with the other heatmap) on wide screens; full-width and stacked on
    // smaller ones so the map stays readable. Click-to-enlarge covers the detailed view.
    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 2];

    protected static ?int $sort = 20;

    /** UPS = blue → red. */
    private const GRADIENT_UPS = ['0.2' => '#2c7bb6', '0.4' => '#abd9e9', '0.6' => '#ffffbf', '0.8' => '#fdae61', '1.0' => '#d7191c'];

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
            'ups' => $svc->shipments($year, $month, 'ups'),
            'fedex' => $svc->shipments($year, $month, 'fedex'),
        ];
    }

    public function heatHeading(): string
    {
        return 'Shipping Destinations';
    }

    public function heatDescription(): string
    {
        $r = $this->resolve();

        return 'Where packages shipped, UPS vs FedEx · '.$this->periodLabel($r['year'], $r['month']).' · toggle a carrier top-right';
    }

    public function heatMapId(): string
    {
        return 'shipments';
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
        return 'shipments';
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
            'unmapped' => (int) $data['meta']['unmatched'],
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
