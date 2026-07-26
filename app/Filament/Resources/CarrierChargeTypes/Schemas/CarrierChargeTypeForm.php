<?php

namespace App\Filament\Resources\CarrierChargeTypes\Schemas;

use App\Models\Carrier;
use App\Models\CarrierChargeType;
use App\Models\ChargeCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CarrierChargeTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Charge Type')
                    ->columns(2)
                    ->schema([
                        Select::make('carrier_id')
                            ->label('Carrier')
                            ->options(fn (): array => Carrier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('Any carrier (generic)')
                            ->native(false),
                        TextInput::make('display_name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->helperText("Your label for this charge type — what it's called in the crosswalk."),
                        Select::make('charge_category_id')
                            ->label('Our Category')
                            ->options(fn (): array => ChargeCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('— Needs review —')
                            ->searchable()
                            ->native(false)
                            ->helperText('Leave blank to flag this for review.'),
                        Select::make('match_style')
                            ->label('Match Style')
                            ->options([
                                CarrierChargeType::MATCH_EXACT => 'Exact — the label matches exactly',
                                CarrierChargeType::MATCH_PREFIX => 'Prefix — the description starts with the label',
                                CarrierChargeType::MATCH_CONTAINS => 'Contains — the description contains the label',
                            ])
                            ->default(CarrierChargeType::MATCH_EXACT)
                            ->required()
                            ->native(false),
                    ]),
                Section::make('CSV identity')
                    ->description('How this charge appears in the carrier\'s CSV/EDI billing file.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('csv_label')
                            ->label('CSV Header / Description')
                            ->maxLength(255),
                        TextInput::make('csv_code')
                            ->label('CSV Section Code (optional)')
                            ->maxLength(20)
                            ->helperText('UPS section code (ISS, SCC…). When set, it must also match — leave blank to match on the label alone.'),
                    ]),
                Section::make('PDF identity')
                    ->description('How this charge appears on the carrier\'s PDF invoice.')
                    ->schema([
                        TextInput::make('pdf_label')
                            ->label('PDF Line Description')
                            ->maxLength(255),
                    ]),
                Section::make('Options')
                    ->columns(2)
                    ->schema([
                        TextInput::make('priority')->label('Priority')->numeric()->default(100)
                            ->helperText('Higher wins when two rows could match.'),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ]),
            ]);
    }
}
