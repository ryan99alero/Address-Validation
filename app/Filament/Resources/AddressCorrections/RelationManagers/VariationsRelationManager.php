<?php

namespace App\Filament\Resources\AddressCorrections\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariationsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Bad Address Variations';

    public function table(Table $table): Table
    {
        return $table
            ->description('Every bad/original address that has been corrected to the good address above.')
            ->columns([
                TextColumn::make('input_address_1')
                    ->label('Original (Bad) Address')
                    ->weight('bold')
                    ->wrap()
                    ->color('danger')
                    ->description(fn ($record): ?string => trim(implode(', ', array_filter([
                        $record->input_address_2, $record->input_city, $record->input_state, $record->input_postal,
                    ]))) ?: null)
                    ->searchable(['input_address_1', 'input_address_2', 'input_city', 'input_state', 'input_postal']),
                TextColumn::make('times_seen')
                    ->label('Times Seen')
                    ->badge()
                    ->color('warning')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->label('First Seen')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('times_seen', 'desc')
            ->paginated([25, 50, 100]);
    }
}
