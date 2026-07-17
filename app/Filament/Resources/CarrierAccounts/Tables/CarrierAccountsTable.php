<?php

namespace App\Filament\Resources\CarrierAccounts\Tables;

use App\Filament\Support\GridCsv;
use App\Models\AccountOwner;
use App\Models\CarrierAccount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CarrierAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nickname')
                    ->label('Nickname')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->badge()
                    ->sortable(),
                TextColumn::make('account_number')
                    ->label('Account #')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->badge()
                    ->placeholder('Needs owner')
                    ->color(fn (CarrierAccount $record): string => match (optional($record->owner)->type) {
                        AccountOwner::TYPE_COMPANY => 'success',
                        AccountOwner::TYPE_CUSTOMER => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('codes')
                    ->label('Ship Via Codes')
                    ->badge()
                    ->getStateUsing(fn (CarrierAccount $record): int => $record->shipViaCodes()->count())
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('nickname')
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->relationship('carrier', 'name'),
                SelectFilter::make('account_owner_id')
                    ->label('Owner')
                    ->relationship('owner', 'name'),
                TernaryFilter::make('needs_owner')
                    ->label('Owner assigned')
                    ->placeholder('All')
                    ->trueLabel('Has an owner')
                    ->falseLabel('Needs owner')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('account_owner_id'),
                        false: fn ($query) => $query->whereNull('account_owner_id'),
                    ),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([GridCsv::menu(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
