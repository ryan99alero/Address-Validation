<?php

namespace App\Filament\Resources\ChargeCategories\Schemas;

use App\Models\ChargeCategory;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class ChargeCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Fee Category')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->rules([fn (?ChargeCategory $record) => Rule::unique('charge_categories', 'name')->ignore($record?->id)])
                            // System categories are referenced by name in code, so their name is locked.
                            ->disabled(fn (?ChargeCategory $record): bool => (bool) $record?->is_system)
                            ->dehydrated(fn (?ChargeCategory $record): bool => ! $record?->is_system)
                            ->helperText(fn (?ChargeCategory $record): ?string => $record?->is_system
                                ? 'Fixed — the app references this category by name.'
                                : null),
                        TextInput::make('abbreviation')->label('Badge')->maxLength(16),
                        TextInput::make('pace_cost_center')
                            ->label('Pace Cost Center')
                            ->maxLength(255)
                            ->helperText('Where charges of this type post in Pace on the recoup push.'),
                        TextInput::make('sort_order')->label('Sort Order')->numeric()->default(0),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ]),
            ]);
    }
}
