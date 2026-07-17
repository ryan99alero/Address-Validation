<?php

namespace App\Filament\Resources\ShipViaCodes\Tables;

use App\Models\AccountOwner;
use App\Models\Plant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ShipViaCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Your Code')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('carrier_code')
                    ->label('Carrier Code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('alternate_codes')
                    ->label('Alt Codes')
                    ->badge()
                    ->separator(',')
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'FedEx' => 'purple',
                        'UPS' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('service_name')
                    ->label('Service Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('service_type')
                    ->label('API Service Type')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->fontFamily('mono')
                    ->size('sm'),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plant_id')
                    ->label('Plant')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_number')
                    ->label('Account')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_owner')
                    ->label('Owner')
                    ->badge()
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->relationship('carrier', 'name'),
                SelectFilter::make('plant_id')
                    ->label('Plant')
                    ->options(fn (): array => Plant::options()),
                SelectFilter::make('carrier_account_id')
                    ->label('Billing Account')
                    ->relationship('carrierAccount', 'nickname')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('account_owner')
                    ->label('Account Owner')
                    ->options(fn (): array => AccountOwner::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $ownerId) => $q->where(fn ($q) => $q
                            ->whereHas('carrierAccount', fn ($q) => $q->where('account_owner_id', $ownerId))
                            ->orWhereHas('thirdPartyAccount', fn ($q) => $q->where('account_owner_id', $ownerId))),
                    )),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code');
    }
}
