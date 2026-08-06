<?php

namespace App\Filament\Pages;

use App\Services\Analytics\CostAnalyticsService;
use App\Support\QueueStatus;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Grid;
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
        $queue = QueueStatus::counts();

        // One compact inline row (no bulky card): a "Dashboard / Timeline" label, the Year + Month
        // selects that drive the KPIs and fee mix, and the live queue status. The queue counts don't
        // auto-poll here (unlike the old widget) — they refresh on load and when a filter changes.
        return $schema->components([
            Grid::make(['default' => 2, 'sm' => 3, 'xl' => 6])
                ->schema([
                    Placeholder::make('timeline')
                        ->label('Dashboard')
                        ->content('Timeline'),
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
                    Placeholder::make('processing')
                        ->label('Processing now')
                        ->content((string) $queue['processing']),
                    Placeholder::make('queued')
                        ->label('Queued')
                        ->content((string) $queue['queued']),
                    Placeholder::make('failed')
                        ->label('Failed')
                        ->content((string) $queue['failed']),
                ]),
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
