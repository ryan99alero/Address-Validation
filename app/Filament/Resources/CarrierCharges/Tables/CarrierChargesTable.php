<?php

namespace App\Filament\Resources\CarrierCharges\Tables;

use App\Filament\Filters\BillingTypeFilter;
use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Filament\Support\CartonReferenceColumns;
use App\Models\CarrierCharge;
use App\Models\ChargeCategory;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CarrierChargesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->searchable(isIndividual: true)
                    ->copyable()
                    ->placeholder('—'),
                ...CartonReferenceColumns::make(),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->badge()
                    ->sortable(),
                TextColumn::make('raw_charge_description')
                    ->label('Adjustment / Charge')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'primary' : 'gray')
                    ->placeholder('Uncategorized'),
                TextColumn::make('amount')
                    ->money('USD')
                    ->sortable()
                    ->summarize(Sum::make()->money('USD')->label('Total')),
                TextColumn::make('invoice_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('invoice.filename')
                    ->label('Invoice')
                    ->url(fn (CarrierCharge $record): ?string => $record->invoice
                        ? CarrierInvoiceResource::getUrl('view', ['record' => $record->invoice])
                        : null)
                    ->color('primary')
                    ->limit(28)
                    ->placeholder('—'),
            ])
            // id-desc is index-fast on 3.4M rows; invoice_date sort is available
            // per-column. Once a category/carrier filter is applied the subset is
            // small enough to sort however the user likes.
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->relationship('carrier', 'name'),
                BillingTypeFilter::make(),
                SelectFilter::make('charge_category_id')
                    ->label('Category')
                    ->options(fn () => ChargeCategory::orderBy('name')->pluck('name', 'id')),
                Filter::make('year')
                    ->label('Date')
                    ->schema([
                        Select::make('year_from')
                            ->label('Year from')
                            ->options(self::yearOptions())
                            ->placeholder('Earliest'),
                        Select::make('year_to')
                            ->label('Year to')
                            ->options(self::yearOptions())
                            ->placeholder('Latest'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['year_from'] ?? null, fn (Builder $q, $from): Builder => $q->where('invoice_date', '>=', "{$from}-01-01"))
                        ->when($data['year_to'] ?? null, fn (Builder $q, $to): Builder => $q->where('invoice_date', '<', ((int) $to + 1).'-01-01')))
                    ->indicateUsing(fn (array $data): ?string => self::yearIndicator($data)),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * Years available for the date filter (no DB scan — generated range).
     *
     * @return array<int, string>
     */
    protected static function yearOptions(): array
    {
        $years = [];
        for ($y = (int) date('Y'); $y >= 2009; $y--) {
            $years[$y] = (string) $y;
        }

        return $years;
    }

    /**
     * Human label for the active year filter (single year or range).
     *
     * @param  array<string, mixed>  $data
     */
    protected static function yearIndicator(array $data): ?string
    {
        $from = $data['year_from'] ?? null;
        $to = $data['year_to'] ?? null;
        if (! $from && ! $to) {
            return null;
        }
        if ($from && $to) {
            return $from === $to ? "Year {$from}" : "Years {$from}–{$to}";
        }

        return $from ? "{$from} onward" : "Through {$to}";
    }
}
