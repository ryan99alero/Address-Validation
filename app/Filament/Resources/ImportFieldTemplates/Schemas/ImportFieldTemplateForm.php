<?php

namespace App\Filament\Resources\ImportFieldTemplates\Schemas;

use App\Services\ImportService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportFieldTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        $systemFields = app(ImportService::class)->getSystemFields();

        return $schema
            ->components([
                Section::make('Template Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->columnSpan(1),
                        Toggle::make('is_default')
                            ->label('Default Template')
                            ->helperText('Use this template by default for new imports'),
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),

                Section::make('Field Mappings')
                    ->description('Map source file columns to system fields')
                    ->schema([
                        Repeater::make('field_mappings')
                            ->label('')
                            ->schema([
                                TextInput::make('source')
                                    ->label('Source Column')
                                    ->placeholder('e.g., ShipToName')
                                    ->required(),
                                Select::make('target')
                                    ->label('Maps To')
                                    ->options($systemFields)
                                    ->placeholder('-- Skip --'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add Mapping')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => isset($state['source'], $state['target'])
                                ? "{$state['source']} → ".($systemFields[$state['target']] ?? 'Skip')
                                : null
                            ),
                    ]),
            ]);
    }
}
