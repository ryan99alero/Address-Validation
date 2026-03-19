<?php

namespace App\Filament\Pages;

use App\Models\CompanySetting;
use App\Services\DynamicFieldService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CompanySetup extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Company Setup';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'Company Setup';

    protected string $view = 'filament.pages.company-setup';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = CompanySetting::instance();
        $dynamicFieldService = app(DynamicFieldService::class);

        $this->form->fill([
            'company_name' => $settings->company_name,
            'contact_name' => $settings->contact_name,
            'phone' => $settings->phone,
            'email' => $settings->email,
            'support_email' => $settings->support_email,
            'address_line_1' => $settings->address_line_1,
            'address_line_2' => $settings->address_line_2,
            'city' => $settings->city,
            'state' => $settings->state,
            'postal_code' => $settings->postal_code,
            'country_code' => $settings->country_code ?? 'US',
            'ups_account_number' => $settings->ups_account_number,
            'fedex_account_number' => $settings->fedex_account_number,
            'extra_field_count' => $settings->extra_field_count ?? 20,
            'current_extra_field_count' => $dynamicFieldService->getCurrentExtraFieldCount(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company Information')
                    ->description('Basic company details.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company Name')
                            ->maxLength(255),
                        TextInput::make('contact_name')
                            ->label('Contact Name')
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Phone Number')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('support_email')
                            ->label('Support Email')
                            ->email()
                            ->maxLength(255),
                    ]),

                Section::make('Ship-From Address')
                    ->description('Default origin address used for transit time calculations and shipping quotes.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address_line_1')
                            ->label('Address Line 1')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('address_line_2')
                            ->label('Address Line 2')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('city')
                            ->label('City')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('state')
                            ->label('State/Province')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('postal_code')
                            ->label('Postal/ZIP Code')
                            ->required()
                            ->maxLength(20),
                        Select::make('country_code')
                            ->label('Country')
                            ->options([
                                'US' => 'United States',
                                'CA' => 'Canada',
                            ])
                            ->default('US')
                            ->required(),
                    ]),

                Section::make('Carrier Account Numbers')
                    ->description('Optional: Your carrier account numbers for shipping integrations.')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('ups_account_number')
                            ->label('UPS Account Number')
                            ->maxLength(50)
                            ->helperText('6-character shipper number'),
                        TextInput::make('fedex_account_number')
                            ->label('FedEx Account Number')
                            ->maxLength(50)
                            ->helperText('9-digit account number'),
                    ]),

                Section::make('Import/Export Settings')
                    ->description('Configure how batch imports and exports work.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('extra_field_count')
                            ->label('Extra Field Count')
                            ->numeric()
                            ->minValue(20)
                            ->maxValue(100)
                            ->required()
                            ->helperText('Number of extra pass-through fields available for imports. Increase if your files have more columns than available fields.'),
                        TextInput::make('current_extra_field_count')
                            ->label('Current DB Fields')
                            ->disabled()
                            ->helperText('Number of extra fields currently in the database.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $dynamicFieldService = app(DynamicFieldService::class);

        // Check if extra field count is being increased
        $newExtraFieldCount = (int) ($data['extra_field_count'] ?? 20);
        $currentDbCount = $dynamicFieldService->getCurrentExtraFieldCount();

        // Remove the display-only field before saving
        unset($data['current_extra_field_count']);

        $settings = CompanySetting::instance();
        $settings->update($data);

        // Expand database columns if needed
        $fieldsAdded = 0;
        if ($newExtraFieldCount > $currentDbCount) {
            $result = $dynamicFieldService->expandExtraFields($newExtraFieldCount);
            $fieldsAdded = $result['added'];
        }

        if ($fieldsAdded > 0) {
            Notification::make()
                ->title('Settings Saved')
                ->body("Company settings updated. Added {$fieldsAdded} new extra fields (extra_".($currentDbCount + 1)." through extra_{$newExtraFieldCount}).")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Settings Saved')
                ->body('Company settings have been updated successfully.')
                ->success()
                ->send();
        }

        // Refresh form to show updated current count
        $this->form->fill([
            ...$data,
            'current_extra_field_count' => $dynamicFieldService->getCurrentExtraFieldCount(),
        ]);
    }
}
