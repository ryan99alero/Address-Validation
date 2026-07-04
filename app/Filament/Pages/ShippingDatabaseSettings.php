<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\ShippingDatabaseSetting;
use App\Services\ShippingDatabaseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ShippingDatabaseSettings extends Page implements HasSchemas
{
    use AdminOnly;
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Shipping Database';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 110;

    protected static ?string $title = 'Shipping Database Connection';

    protected string $view = 'filament.pages.shipping-database-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $s = ShippingDatabaseSetting::instance();

        $this->form->fill([
            'enabled' => $s->enabled,
            'driver' => $s->driver ?: 'sqlsrv',
            'host' => $s->host,
            'port' => $s->port ?: '1433',
            'database' => $s->database,
            'username' => $s->username,
            // Never send the stored password to the browser; blank = keep current on save.
            'password' => null,
            'table_name' => $s->table_name ?: 'xCarrierShipping',
            'tracking_column' => $s->tracking_column ?: 'trackingno',
            'encrypt' => $s->encrypt,
            'trust_server_certificate' => $s->trust_server_certificate,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->description('External SQL Server used to back-fill FedEx original recipient addresses by tracking number. Requires the PHP sqlsrv/pdo_sqlsrv driver on the server.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enabled')
                            ->helperText('When off, address back-fill is skipped.')
                            ->columnSpanFull(),
                        Select::make('driver')
                            ->label('Driver')
                            ->options(['sqlsrv' => 'SQL Server (sqlsrv)'])
                            ->default('sqlsrv')
                            ->required(),
                        TextInput::make('host')
                            ->label('Host / IP')
                            ->maxLength(255),
                        TextInput::make('port')
                            ->label('Port')
                            ->default('1433')
                            ->maxLength(10),
                        TextInput::make('database')
                            ->label('Database')
                            ->maxLength(255),
                        TextInput::make('username')
                            ->label('Username')
                            ->maxLength(255),
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Stored encrypted. Leave blank to keep the current password.')
                            ->maxLength(255),
                    ]),
                Section::make('Source Table')
                    ->columns(2)
                    ->schema([
                        TextInput::make('table_name')
                            ->label('Table / View')
                            ->default('xCarrierShipping')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('tracking_column')
                            ->label('Tracking-number Column')
                            ->default('trackingno')
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('TLS')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Toggle::make('encrypt')
                            ->label('Encrypt connection'),
                        Toggle::make('trust_server_certificate')
                            ->label('Trust server certificate')
                            ->default(true),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Test Connection')
                ->icon(Heroicon::OutlinedBolt)
                ->color('gray')
                ->action(function (): void {
                    // Persist first so the test uses exactly what's on screen.
                    $this->save(false);

                    $result = app(ShippingDatabaseService::class)->testConnectionDetailed();

                    ShippingDatabaseSetting::instance()->update([
                        'last_tested_at' => now(),
                        'last_test_status' => $result['ok'] ? 'ok' : 'failed',
                    ]);

                    Notification::make()
                        ->title($result['ok'] ? 'Connection successful' : 'Connection failed')
                        ->body($result['message'])
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    public function save(bool $notify = true): void
    {
        $data = $this->form->getState();

        // Keep the existing password when the field was left blank.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        ShippingDatabaseSetting::instance()->update($data);

        if ($notify) {
            Notification::make()
                ->title('Settings Saved')
                ->body('Shipping database connection updated.')
                ->success()
                ->send();
        }
    }
}
