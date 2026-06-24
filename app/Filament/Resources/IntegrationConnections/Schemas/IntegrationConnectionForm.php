<?php

namespace App\Filament\Resources\IntegrationConnections\Schemas;

use App\Models\Carrier;
use App\Models\IntegrationConnection;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class IntegrationConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Connection')
                        ->icon('heroicon-o-link')
                        ->schema([
                            Section::make('Basic Information')
                                ->description('Identify this integration connection')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('name')
                                        ->required()
                                        ->maxLength(100)
                                        ->helperText('Friendly name, e.g. "Pace Production"'),
                                    Select::make('driver')
                                        ->label('Integration Type')
                                        ->required()
                                        ->live()
                                        ->default(IntegrationConnection::DRIVER_PACE)
                                        ->options([
                                            IntegrationConnection::DRIVER_PACE => 'Pace / ePace ERP (API)',
                                            IntegrationConnection::DRIVER_GENERIC_REST => 'Generic REST API',
                                        ])
                                        ->helperText('Selects the system and how the form behaves'),
                                    Toggle::make('is_active')
                                        ->label('Active')
                                        ->default(true)
                                        ->helperText('Inactive connections will not sync or accept webhooks'),
                                ]),
                            Section::make('API Endpoint')
                                ->description('Configure the API endpoint URL')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('base_url')
                                        ->label('API Base URL')
                                        ->required()
                                        ->url()
                                        ->columnSpanFull()
                                        ->helperText('e.g. http://epace.yourhost.com/rpc/rest/services'),
                                    TextInput::make('api_version')
                                        ->label('API Version')
                                        ->maxLength(20)
                                        ->placeholder('v1'),
                                ]),
                            Section::make('Connection Status')
                                ->hiddenOn('create')
                                ->columns(2)
                                ->schema([
                                    Placeholder::make('last_connected_at_display')
                                        ->label('Last Successful Connection')
                                        ->content(fn (?IntegrationConnection $record): string => $record?->last_connected_at?->diffForHumans() ?? 'Never'),
                                    Placeholder::make('last_error_message_display')
                                        ->label('Last Error')
                                        ->content(fn (?IntegrationConnection $record): string => $record?->last_error_message ?? 'None'),
                                ]),
                        ]),

                    Tab::make('Authentication')
                        ->icon('heroicon-o-key')
                        ->schema([
                            Section::make('Authentication Method')
                                ->description('Credentials are encrypted at rest')
                                ->schema([
                                    Select::make('auth_type')
                                        ->label('Authentication Type')
                                        ->required()
                                        ->live()
                                        ->default('basic')
                                        ->columnSpanFull()
                                        ->options([
                                            'basic' => 'Basic Auth (Username & Password)',
                                            'bearer' => 'Bearer Token',
                                            'api_key' => 'API Key',
                                        ]),
                                ]),
                            Section::make('Basic Authentication')
                                ->columns(2)
                                ->visible(fn (Get $get): bool => $get('auth_type') === 'basic')
                                ->schema([
                                    TextInput::make('credentials.username')->label('Username'),
                                    TextInput::make('credentials.password')->label('Password')->password()->revealable(),
                                ]),
                            Section::make('API Key')
                                ->columns(2)
                                ->visible(fn (Get $get): bool => $get('auth_type') === 'api_key')
                                ->schema([
                                    TextInput::make('credentials.api_key')->label('API Key')->password()->revealable()->columnSpanFull(),
                                    Select::make('credentials.api_key_location')
                                        ->label('Key Location')
                                        ->options(['header' => 'Header', 'query' => 'Query Parameter'])
                                        ->default('header'),
                                    TextInput::make('credentials.api_key_name')->label('Header / Param Name')->default('Authorization'),
                                ]),
                            Section::make('Bearer Token')
                                ->visible(fn (Get $get): bool => $get('auth_type') === 'bearer')
                                ->schema([
                                    TextInput::make('credentials.bearer_token')->label('Bearer Token')->password()->revealable(),
                                ]),
                        ]),

                    Tab::make('Settings')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            Section::make('Request')
                                ->columns(3)
                                ->schema([
                                    TextInput::make('timeout_seconds')->label('Timeout (seconds)')->numeric()->default(30)->minValue(5)->maxValue(300),
                                    TextInput::make('retry_attempts')->label('Retry Attempts')->numeric()->default(3)->minValue(0)->maxValue(10),
                                    TextInput::make('rate_limit_per_minute')->label('Rate Limit (per min)')->numeric()->minValue(1)->helperText('Blank = unlimited'),
                                ]),
                            Section::make('Address Validation')
                                ->description('Validators used for address correction, in priority order. The first that returns a result wins; the rest are fallbacks.')
                                ->schema([
                                    Select::make('validation_carriers')
                                        ->label('Validators (drag to set priority)')
                                        ->multiple()
                                        ->reorderable()
                                        ->options(fn (): array => Carrier::query()->orderBy('name')->pluck('name', 'slug')->all())
                                        ->helperText('e.g. Smarty → UPS → FedEx. Leave empty to use all active carriers (Smarty preferred).'),
                                    Toggle::make('dry_run')
                                        ->label('Shadow / dry-run mode (no write-back)')
                                        ->helperText('When ON: the engine validates and logs exactly what it WOULD change, but does NOT push anything back to Pace. Use to observe corrections for a while before going live.'),
                                ]),
                            Section::make('Sync')
                                ->schema([
                                    TextInput::make('sync_interval_minutes')
                                        ->label('Sync Interval (minutes)')
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->helperText('0 = push / manual only (no polling). Greater than 0 = polled every N minutes.'),
                                ]),
                            Section::make('Webhook (Push Trigger)')
                                ->description('Point Pace Connect (or any external trigger) at this URL. Use the "Regenerate Webhook URL" toolbar button to rotate the token.')
                                ->hiddenOn('create')
                                ->schema([
                                    TextInput::make('webhook_url_display')
                                        ->label('Webhook Trigger URL')
                                        ->readOnly()
                                        ->dehydrated(false)
                                        ->columnSpanFull()
                                        ->placeholder('No token yet — use the Regenerate Webhook URL button.')
                                        ->helperText('Read-only. Select to copy, or rotate it from the toolbar.'),
                                ]),
                        ]),
                ]),
        ]);
    }
}
