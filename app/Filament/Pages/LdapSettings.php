<?php

namespace App\Filament\Pages;

use App\Models\LdapSetting;
use App\Services\LdapService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class LdapSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'LDAP Settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected string $view = 'filament.pages.ldap-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = LdapSetting::instance();

        $this->form->fill([
            'enabled' => $settings->enabled,
            'host' => $settings->host,
            'port' => $settings->port,
            'use_ssl' => $settings->use_ssl,
            'use_tls' => $settings->use_tls,
            'base_dn' => $settings->base_dn,
            'bind_username' => $settings->bind_username,
            'bind_password' => '', // Don't show existing password
            'user_ou' => $settings->user_ou,
            'login_attribute' => $settings->login_attribute,
            'email_attribute' => $settings->email_attribute,
            'name_attribute' => $settings->name_attribute,
            'admin_group' => $settings->admin_group,
            'user_filter' => $settings->user_filter,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('LDAP Connection')
                    ->description('Configure your Active Directory / LDAP server connection')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enable LDAP Authentication')
                            ->helperText('When enabled, users can authenticate using their AD credentials')
                            ->columnSpanFull(),

                        Fieldset::make('Server Settings')
                            ->schema([
                                TextInput::make('host')
                                    ->label('LDAP Host')
                                    ->placeholder('dc01.company.local')
                                    ->helperText('Domain controller hostname or IP address')
                                    ->required(),

                                TextInput::make('port')
                                    ->label('Port')
                                    ->numeric()
                                    ->default(389)
                                    ->helperText('389 for LDAP, 636 for LDAPS')
                                    ->required(),

                                Toggle::make('use_ssl')
                                    ->label('Use SSL (LDAPS)')
                                    ->helperText('Use port 636 with SSL'),

                                Toggle::make('use_tls')
                                    ->label('Use STARTTLS')
                                    ->helperText('Upgrade connection to TLS'),

                                TextInput::make('base_dn')
                                    ->label('Base DN')
                                    ->placeholder('DC=company,DC=local')
                                    ->helperText('The base distinguished name for your domain')
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Fieldset::make('Bind Credentials')
                            ->schema([
                                TextInput::make('bind_username')
                                    ->label('Bind Username')
                                    ->placeholder('CN=ldap_service,OU=Service Accounts,DC=company,DC=local')
                                    ->helperText('Service account DN for LDAP queries')
                                    ->columnSpanFull(),

                                TextInput::make('bind_password')
                                    ->label('Bind Password')
                                    ->password()
                                    ->helperText('Leave blank to keep existing password')
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('User Settings')
                    ->description('Configure how users are found and mapped')
                    ->schema([
                        TextInput::make('user_ou')
                            ->label('User OU')
                            ->placeholder('OU=Users')
                            ->helperText('Optional: OU to search for users (relative to Base DN)'),

                        Select::make('login_attribute')
                            ->label('Login Attribute')
                            ->options([
                                'sAMAccountName' => 'sAMAccountName (username)',
                                'userPrincipalName' => 'userPrincipalName (email)',
                                'mail' => 'mail (email address)',
                            ])
                            ->default('sAMAccountName')
                            ->helperText('Which AD attribute to use for login'),

                        TextInput::make('email_attribute')
                            ->label('Email Attribute')
                            ->default('mail')
                            ->helperText('AD attribute containing user email'),

                        TextInput::make('name_attribute')
                            ->label('Display Name Attribute')
                            ->default('cn')
                            ->helperText('AD attribute containing user display name'),

                        TextInput::make('user_filter')
                            ->label('Additional User Filter')
                            ->placeholder('(objectClass=person)')
                            ->helperText('Optional LDAP filter to restrict which users can authenticate')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Admin Group')
                    ->description('Users in this AD group will be granted admin access')
                    ->schema([
                        TextInput::make('admin_group')
                            ->label('Admin Group Name')
                            ->placeholder('AddressValidation-Admins')
                            ->helperText('AD group name (CN) that grants admin access in this application'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Test Connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    $this->saveSettings(showNotification: false);

                    $ldapService = new LdapService;
                    $result = $ldapService->testConnection();

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Connection Successful')
                            ->body($result['message'])
                            ->send();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Connection Failed')
                            ->body($result['message'])
                            ->persistent()
                            ->send();
                    }
                }),

            Action::make('save')
                ->label('Save Settings')
                ->icon(Heroicon::OutlinedCheck)
                ->action(fn () => $this->saveSettings()),
        ];
    }

    public function saveSettings(bool $showNotification = true): void
    {
        $data = $this->form->getState();

        \Log::info('LDAP Save - Form data', [
            'has_password' => ! empty($data['bind_password']),
            'password_length' => strlen($data['bind_password'] ?? ''),
            'all_keys' => array_keys($data),
        ]);

        $settings = LdapSetting::instance();

        $updateData = [
            'enabled' => $data['enabled'],
            'host' => $data['host'],
            'port' => $data['port'],
            'use_ssl' => $data['use_ssl'],
            'use_tls' => $data['use_tls'],
            'base_dn' => $data['base_dn'],
            'bind_username' => $data['bind_username'],
            'user_ou' => $data['user_ou'],
            'login_attribute' => $data['login_attribute'],
            'email_attribute' => $data['email_attribute'],
            'name_attribute' => $data['name_attribute'],
            'admin_group' => $data['admin_group'],
            'user_filter' => $data['user_filter'],
        ];

        // Update all fields
        $settings->fill($updateData);

        // Only update password if a new one was provided
        if (! empty($data['bind_password'])) {
            $settings->bind_password = $data['bind_password'];
        }

        $settings->save();

        if ($showNotification) {
            Notification::make()
                ->success()
                ->title('Settings Saved')
                ->body('LDAP settings have been updated')
                ->send();
        }
    }
}
