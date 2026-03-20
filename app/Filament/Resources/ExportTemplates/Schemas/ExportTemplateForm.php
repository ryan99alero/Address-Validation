<?php

namespace App\Filament\Resources\ExportTemplates\Schemas;

use App\Models\CompanySetting;
use App\Models\ExportTemplate;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExportTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        $availableFields = self::getAllAvailableFields();

        return $schema
            ->components([
                Section::make('Template Details')
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        Textarea::make('description'),
                    ]),

                Section::make('File Settings')
                    ->columns(3)
                    ->schema([
                        Select::make('file_format')
                            ->label('File Format')
                            ->options(ExportTemplate::getFileFormats())
                            ->default(ExportTemplate::FORMAT_CSV)
                            ->required(),
                        TextInput::make('delimiter')
                            ->label('Delimiter')
                            ->default(',')
                            ->maxLength(1)
                            ->helperText('For CSV files'),
                        Toggle::make('include_header')
                            ->label('Include Header Row')
                            ->default(true),
                    ]),

                Section::make('Field Layout')
                    ->description('Define which fields to export and their column headers')
                    ->schema([
                        Repeater::make('field_layout')
                            ->label('')
                            ->schema([
                                Select::make('field')
                                    ->label('System Field')
                                    ->options($availableFields)
                                    ->required()
                                    ->searchable(),
                                TextInput::make('header')
                                    ->label('Column Header')
                                    ->placeholder('Custom header name')
                                    ->helperText('Leave blank to use field name'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add Field')
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(0)
                            ->itemLabel(function (array $state) use ($availableFields): ?string {
                                if (! isset($state['field'])) {
                                    return null;
                                }

                                $fieldLabel = $availableFields[$state['field']] ?? $state['field'];
                                $headerSuffix = ! empty($state['header']) ? " → {$state['header']}" : '';

                                return $fieldLabel.$headerSuffix;
                            }),
                    ]),

                Section::make('Sharing')
                    ->schema([
                        Toggle::make('is_shared')
                            ->label('Share with all users')
                            ->helperText('Allow other users to use this template'),
                    ]),
            ]);
    }

    /**
     * Get all available fields including extra fields.
     *
     * @return array<string, string>
     */
    public static function getAllAvailableFields(): array
    {
        // Start with standard export fields
        $fields = ExportTemplate::getAvailableFields();

        // Add extra fields based on company settings
        $extraFieldCount = CompanySetting::instance()->getExtraFieldCount();
        for ($i = 1; $i <= $extraFieldCount; $i++) {
            $fields["extra_{$i}"] = "Extra Field {$i}";
        }

        return $fields;
    }
}
