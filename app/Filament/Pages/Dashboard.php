<?php

namespace App\Filament\Pages;

use App\Services\Analytics\CostAnalyticsService;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Cost-intelligence dashboard with a period filter (year + optional month). The filter drives the
 * KPI cards and the fee-mix chart to the chosen period and compares it to the same period one year
 * earlier (year-over-year), so "are we improving?" is answered on-screen without memorizing or
 * snapshotting. The multi-year "by Year" trend charts stay full-history — they ARE the combined view.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        $years = app(CostAnalyticsService::class)->availableYears();

        return $schema->components([
            Section::make('Period')
                ->description('Focus the KPIs and fee mix on a year — or a single month — and compare to the same period a year earlier.')
                ->schema([
                    Select::make('year')
                        ->label('Year')
                        ->options([0 => 'All years'] + (array_combine($years, $years) ?: []))
                        ->default($years[0] ?? 0)
                        ->selectablePlaceholder(false)
                        ->native(false),
                    Select::make('month')
                        ->label('Month')
                        ->options([0 => 'Full year'] + self::MONTHS)
                        ->default(0)
                        ->selectablePlaceholder(false)
                        ->native(false),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Compact tile grid so the whole dashboard is visible at a glance. Stat rows and heatmaps span
     * the full width (they need it, and are set to columnSpan 'full'); the charts take one column
     * each, so they tile 4-across on a large desktop, 3 on a laptop, 2 on a tablet, and stack on a
     * phone. Column counts are capped to keep each chart tile at least ~300px wide — the point below
     * which a chart stops being legible.
     */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];
    }

    /**
     * @var array<int, string>
     */
    public const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];
}
