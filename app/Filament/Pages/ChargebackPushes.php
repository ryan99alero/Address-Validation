<?php

namespace App\Filament\Pages;

use App\Models\ChargebackPush;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * The chargeback ledger — every carrier charge pushed (or considered) as a Pace JobCost, with its
 * disposition and the returned JobCost id. Read-only view + CSV export (all fields incl. the notes
 * with the recorded→corrected address), so finance can trust it and chase failed/unverified rows.
 */
class ChargebackPushes extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.chargeback-pushes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Costs';

    protected static ?string $navigationLabel = 'Chargeback Pushes';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Chargeback Pushes (Pace JobCost)';

    public static function getNavigationBadge(): ?string
    {
        $n = ChargebackPush::whereIn('status', ['failed', 'unverified'])->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ChargebackPush::query()->latest('id'))
            ->columns([
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'pushed' => 'success',
                        'failed', 'unverified' => 'danger',
                        'pending' => 'warning',
                        default => 'gray', // skipped_*
                    }),
                TextColumn::make('tracking_number')->label('Tracking')->searchable()->fontFamily('mono'),
                TextColumn::make('driver')->badge()->toggleable(),
                TextColumn::make('activity_code')->label('Activity')->badge(),
                TextColumn::make('amount')->money('USD')->sortable()->alignEnd(),
                TextColumn::make('pace_job')->label('Job')->searchable(),
                TextColumn::make('pace_customer_id')->label('Customer')->toggleable(),
                TextColumn::make('pace_jobcost_id')->label('JobCost ID')->fontFamily('mono')->placeholder('—')->searchable(),
                TextColumn::make('pushed_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('last_error')->label('Error')->limit(60)->color('danger')->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('notes')->limit(50)->toggleable(isToggledHiddenByDefault: true)->tooltip(fn (?string $state): ?string => $state),
            ])
            ->filters([
                SelectFilter::make('status')->options(fn (): array => ChargebackPush::query()->distinct()->pluck('status', 'status')->all()),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([50, 100, 'all']);
    }

    private function exportCsv(): StreamedResponse
    {
        $columns = ['id', 'status', 'carrier_id', 'carrier_invoice_id', 'tracking_number', 'driver',
            'charge_category_id', 'activity_code', 'amount', 'ship_date', 'pace_job', 'pace_job_part',
            'pace_customer_id', 'pace_jobcost_id', 'pushed_at', 'attempts', 'last_error', 'notes'];

        return response()->streamDownload(function () use ($columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            ChargebackPush::query()->orderByDesc('id')->chunk(500, function ($rows) use ($out, $columns): void {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn (string $c) => (string) $row->{$c}, $columns));
                }
            });
            fclose($out);
        }, 'chargeback-pushes-'.now()->format('Ymd-His').'.csv');
    }
}
