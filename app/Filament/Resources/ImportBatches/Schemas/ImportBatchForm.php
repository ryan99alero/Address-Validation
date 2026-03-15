<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('original_filename')
                    ->required(),
                TextInput::make('file_path')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('total_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('processed_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('successful_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('failed_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('mapping_template_id')
                    ->relationship('mappingTemplate', 'name'),
                Select::make('carrier_id')
                    ->relationship('carrier', 'name'),
                TextInput::make('error_file_path'),
                TextInput::make('imported_by')
                    ->numeric(),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('completed_at'),
            ]);
    }
}
