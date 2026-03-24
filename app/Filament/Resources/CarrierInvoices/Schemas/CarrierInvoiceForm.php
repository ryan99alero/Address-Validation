<?php

namespace App\Filament\Resources\CarrierInvoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CarrierInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('carrier_id')
                    ->relationship('carrier', 'name')
                    ->required(),
                TextInput::make('filename')
                    ->required(),
                TextInput::make('original_path'),
                TextInput::make('archived_path'),
                TextInput::make('file_hash')
                    ->required(),
                TextInput::make('invoice_number'),
                DatePicker::make('invoice_date'),
                TextInput::make('account_number'),
                TextInput::make('total_records')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('correction_records')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('new_corrections')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('duplicate_corrections')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_correction_charges')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ])
                    ->default('pending')
                    ->required(),
                Textarea::make('error_message')
                    ->columnSpanFull(),
                DateTimePicker::make('processed_at'),
            ]);
    }
}
