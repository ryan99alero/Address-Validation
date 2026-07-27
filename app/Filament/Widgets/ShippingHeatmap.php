<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\Analytics\CostAnalyticsService;
use App\Services\Analytics\HeatmapService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * US heatmap of where packages shipped, from carrier_shipments (UPS-sourced) destinations, resolved
 * ZIP → centroid (no geocoding). Honours the dashboard year/month filter. Count = shipments; red is
 * the highest-volume ZIPs.
 */
class ShippingHeatmap extends Widget
{
    use InteractsWithPageFilters;
    use ReadsDashboardPeriod;

    protected string $view = 'filament.widgets.heatmap';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 20;

    /** Blue → red gradient; red = the busiest ZIPs. */
    private const GRADIENT = ['0.2' => '#2c7bb6', '0.4' => '#abd9e9', '0.6' => '#ffffbf', '0.8' => '#fdae61', '1.0' => '#d7191c'];

    /** @var array{year: ?int, month: ?int, data: array{points: array<int, mixed>, meta: array<string, mixed>}}|null */
    private ?array $resolved = null;

    /**
     * @return array{year: ?int, month: ?int, data: array{points: array<int, mixed>, meta: array<string, mixed>}}
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        [$year, $month] = $this->selectedPeriod(app(CostAnalyticsService::class));

        return $this->resolved = [
            'year' => $year,
            'month' => $month,
            'data' => app(HeatmapService::class)->shipments($year, $month),
        ];
    }

    public function heatHeading(): string
    {
        return 'Shipping Destinations';
    }

    public function heatDescription(): string
    {
        $r = $this->resolve();

        return 'Where UPS packages shipped · '.$this->periodLabel($r['year'], $r['month']);
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
        $data = $this->resolve()['data'];

        return [
            'center' => [39.5, -98.35],
            'zoom' => 4,
            'tiles' => [
                'url' => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
                'attribution' => '&copy; OpenStreetMap &copy; CARTO',
            ],
            'layers' => [[
                'name' => 'Shipments',
                'points' => $data['points'],
                'max' => $data['meta']['max'],
                'gradient' => self::GRADIENT,
            ]],
        ];
    }

    /**
     * @return list<array{label: string, stops: list<string>, max: float}>
     */
    public function heatLegends(): array
    {
        return [[
            'label' => 'Shipments',
            'stops' => array_values(self::GRADIENT),
            'max' => (float) $this->resolve()['data']['meta']['max'],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function heatMeta(): array
    {
        return $this->resolve()['data']['meta'];
    }
}
