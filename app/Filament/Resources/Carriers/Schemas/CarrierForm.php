<?php

namespace App\Filament\Resources\Carriers\Schemas;

use App\Models\Carrier;
use App\Services\AddressValidationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CarrierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Integration Information')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Display name for this API integration'),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(50)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique identifier (lowercase, no spaces)'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Enable or disable this integration'),
                        Select::make('environment')
                            ->options([
                                'sandbox' => 'Sandbox (Testing)',
                                'production' => 'Production',
                            ])
                            ->required()
                            ->default('sandbox'),
                    ]),

                Section::make('API Configuration')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sandbox_url')
                            ->label('Sandbox URL')
                            ->url()
                            ->placeholder(fn (?Carrier $record) => $record?->getDefaultSandboxUrl() ?? 'Default sandbox URL')
                            ->helperText('Leave blank to use default sandbox URL'),
                        TextInput::make('production_url')
                            ->label('Production URL')
                            ->url()
                            ->placeholder(fn (?Carrier $record) => $record?->getDefaultProductionUrl() ?? 'Default production URL')
                            ->helperText('Leave blank to use default production URL'),
                        TextInput::make('timeout_seconds')
                            ->label('Timeout (seconds)')
                            ->required()
                            ->numeric()
                            ->default(30)
                            ->minValue(5)
                            ->maxValue(120),
                    ]),

                Section::make('Batch Processing')
                    ->description('Configure how addresses are processed in bulk')
                    ->columns(2)
                    ->schema([
                        TextInput::make('chunk_size')
                            ->label('Chunk Size')
                            ->required()
                            ->numeric()
                            ->default(100)
                            ->minValue(1)
                            ->maxValue(1000)
                            ->helperText('Number of addresses to process per batch'),
                        TextInput::make('concurrent_requests')
                            ->label('Concurrent Requests')
                            ->required()
                            ->numeric()
                            ->default(10)
                            ->minValue(1)
                            ->maxValue(50)
                            ->helperText('Max parallel HTTP requests within a chunk'),
                        TextInput::make('rate_limit_per_minute')
                            ->label('Rate Limit (per minute)')
                            ->numeric()
                            ->nullable()
                            ->minValue(1)
                            ->helperText('Optional: Max requests per minute (leave blank for unlimited)'),
                        Toggle::make('supports_native_batch')
                            ->label('Supports Native Batch API')
                            ->helperText('Enable if the API supports batch requests natively')
                            ->live(),
                        TextInput::make('native_batch_size')
                            ->label('Native Batch Size')
                            ->numeric()
                            ->nullable()
                            ->minValue(1)
                            ->maxValue(500)
                            ->visible(fn (callable $get) => $get('supports_native_batch'))
                            ->helperText('Max addresses per native batch API call'),
                    ]),

                Section::make('Authentication')
                    ->description('Configure API authentication. Credentials are encrypted.')
                    ->columns(2)
                    ->schema([
                        Select::make('auth_type')
                            ->label('Authentication Type')
                            ->options([
                                'oauth2' => 'OAuth 2.0 (Client ID & Secret)',
                                'api_key' => 'API Key (Auth ID & Token)',
                                'basic' => 'Basic Auth (Username & Password)',
                            ])
                            ->default('oauth2')
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        // OAuth2 fields
                        TextInput::make('client_id')
                            ->label('Client ID')
                            ->password()
                            ->revealable()
                            ->visible(fn (callable $get) => $get('auth_type') === 'oauth2')
                            ->afterStateHydrated(function (TextInput $component, ?Carrier $record) {
                                if ($record) {
                                    $component->state($record->getCredential('client_id'));
                                }
                            }),
                        TextInput::make('client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->revealable()
                            ->visible(fn (callable $get) => $get('auth_type') === 'oauth2')
                            ->afterStateHydrated(function (TextInput $component, ?Carrier $record) {
                                if ($record) {
                                    $component->state($record->getCredential('client_secret'));
                                }
                            }),

                        // API Key fields (for Smarty, etc.)
                        TextInput::make('auth_id')
                            ->label('Auth ID')
                            ->password()
                            ->revealable()
                            ->visible(fn (callable $get) => $get('auth_type') === 'api_key')
                            ->afterStateHydrated(function (TextInput $component, ?Carrier $record) {
                                if ($record) {
                                    $component->state($record->getCredential('auth_id'));
                                }
                            }),
                        TextInput::make('auth_token')
                            ->label('Auth Token')
                            ->password()
                            ->revealable()
                            ->visible(fn (callable $get) => $get('auth_type') === 'api_key')
                            ->afterStateHydrated(function (TextInput $component, ?Carrier $record) {
                                if ($record) {
                                    $component->state($record->getCredential('auth_token'));
                                }
                            }),

                        // Basic Auth fields
                        TextInput::make('username')
                            ->label('Username')
                            ->visible(fn (callable $get) => $get('auth_type') === 'basic')
                            ->afterStateHydrated(function (TextInput $component, ?Carrier $record) {
                                if ($record) {
                                    $component->state($record->getCredential('username'));
                                }
                            }),
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->visible(fn (callable $get) => $get('auth_type') === 'basic')
                            ->afterStateHydrated(function (TextInput $component, ?Carrier $record) {
                                if ($record) {
                                    $component->state($record->getCredential('password'));
                                }
                            }),
                    ]),

                Section::make('Connection Status')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('last_connected_at')
                            ->label('Last Connected')
                            ->state(fn (?Carrier $record) => $record?->last_connected_at?->diffForHumans() ?? 'Never'),
                        TextEntry::make('last_error_at')
                            ->label('Last Error')
                            ->state(fn (?Carrier $record) => $record?->last_error_at?->diffForHumans() ?? 'None'),
                        TextEntry::make('last_error_message')
                            ->label('Error Message')
                            ->state(fn (?Carrier $record) => $record?->last_error_message ?? 'N/A')
                            ->columnSpanFull(),
                    ])
                    ->hiddenOn('create'),

                Actions::make([
                    Action::make('testConnection')
                        ->label('Test Connection')
                        ->icon('heroicon-o-signal')
                        ->action(function (Carrier $record) {
                            $service = app(AddressValidationService::class);
                            try {
                                $result = $service->testConnection($record->slug);
                                if ($result) {
                                    Notification::make()
                                        ->title('Connection Successful')
                                        ->body('Successfully connected to '.$record->name.' API')
                                        ->success()
                                        ->send();
                                } else {
                                    // Show the last error message if available
                                    $record->refresh();
                                    $errorMsg = $record->last_error_message ?? 'Could not connect';
                                    Notification::make()
                                        ->title('Connection Failed')
                                        ->body($errorMsg)
                                        ->danger()
                                        ->send();
                                }
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Connection Error')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ])->hiddenOn('create'),
            ]);
    }
}
