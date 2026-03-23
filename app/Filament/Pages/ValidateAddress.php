<?php

namespace App\Filament\Pages;

use App\Models\Address;
use App\Models\Carrier;
use App\Models\CompanySetting;
use App\Models\ShipViaCode;
use App\Models\TransitTime;
use App\Services\AddressValidationService;
use App\Services\FedExServiceAvailabilityService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ValidateAddress extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Validate Address';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.validate-address';

    public ?array $data = [];

    public ?Address $result = null;

    public bool $showCandidatesModal = false;

    public int $selectedCandidateIndex = 0;

    /**
     * @var Collection<int, TransitTime>|null
     */
    public ?Collection $transitTimes = null;

    public bool $isLoadingTransitTimes = false;

    public ?int $selectedShipViaCodeId = null;

    public function mount(): void
    {
        $this->form->fill([
            'input_country' => 'US',
            'carrier_id' => Carrier::where('is_active', true)->first()?->id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Address Information')
                    ->columns(2)
                    ->schema([
                        TextInput::make('input_name')
                            ->label('Recipient Name')
                            ->maxLength(255),
                        TextInput::make('input_company')
                            ->label('Company')
                            ->maxLength(255),
                        TextInput::make('input_address_1')
                            ->label('Address Line 1')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('input_address_2')
                            ->label('Address Line 2')
                            ->maxLength(255),
                        TextInput::make('input_city')
                            ->label('City')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('input_state')
                            ->label('State/Province')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('input_postal')
                            ->label('Postal/ZIP Code')
                            ->required()
                            ->maxLength(20),
                        Select::make('input_country')
                            ->label('Country')
                            ->options([
                                'US' => 'United States',
                                'CA' => 'Canada',
                            ])
                            ->default('US')
                            ->required(),
                    ]),

                Section::make('Validation Options')
                    ->columns(2)
                    ->schema([
                        Select::make('carrier_id')
                            ->label('Validation API')
                            ->options(Carrier::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->helperText('Select the API to validate the address with'),
                        TextInput::make('external_reference')
                            ->label('External Reference (Optional)')
                            ->maxLength(255)
                            ->helperText('Your internal reference number'),
                    ]),

                Section::make('Transit Time Options')
                    ->description('Optionally fetch shipping service options and delivery estimates.')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Checkbox::make('include_transit_times')
                            ->label('Include Time in Transit (FedEx)')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $company = CompanySetting::instance();
                                    if ($company->postal_code) {
                                        $set('origin_postal_code', $company->postal_code);
                                    }
                                }
                            })
                            ->helperText('Fetch available shipping services and estimated delivery dates'),
                        TextInput::make('origin_postal_code')
                            ->label('Ship From ZIP Code')
                            ->placeholder('e.g., 38017')
                            ->maxLength(10)
                            ->visible(fn ($get) => $get('include_transit_times'))
                            ->required(fn ($get) => $get('include_transit_times'))
                            ->helperText(fn () => CompanySetting::instance()->hasAddress()
                                ? 'Default: '.CompanySetting::instance()->city.', '.CompanySetting::instance()->state
                                : 'Configure default in Settings > Company Setup'),
                        Select::make('ship_via_code_id')
                            ->label('Ship Method (for specific transit time)')
                            ->options(fn () => ShipViaCode::where('is_active', true)
                                ->whereNotNull('service_type')
                                ->get()
                                ->mapWithKeys(fn ($code) => [$code->id => $code->service_name.' ('.$code->code.')']))
                            ->searchable()
                            ->placeholder('Select to show specific transit time')
                            ->visible(fn ($get) => $get('include_transit_times'))
                            ->helperText('Optional: Select a ship method to highlight its transit time')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function validate_address(): void
    {
        $data = $this->form->getState();

        $carrier = Carrier::findOrFail($data['carrier_id']);

        // Create address record
        $address = Address::create([
            'external_reference' => $data['external_reference'] ?? null,
            'input_name' => $data['input_name'] ?? null,
            'input_company' => $data['input_company'] ?? null,
            'input_address_1' => $data['input_address_1'],
            'input_address_2' => $data['input_address_2'] ?? null,
            'input_city' => $data['input_city'],
            'input_state' => $data['input_state'],
            'input_postal' => $data['input_postal'],
            'input_country' => $data['input_country'],
            'source' => 'manual',
            'created_by' => auth()->id(),
        ]);

        try {
            $service = app(AddressValidationService::class);
            $validatedAddress = $service->validateAddress($address, $carrier->slug);

            $this->result = $validatedAddress;
            $this->transitTimes = null;

            if ($this->result->validation_status === 'valid') {
                Notification::make()
                    ->title('Address Validated')
                    ->body('The address has been validated successfully.')
                    ->success()
                    ->send();

                // Fetch transit times if enabled and address is valid
                $includeTransitTimes = $data['include_transit_times'] ?? false;
                $originPostalCode = $data['origin_postal_code'] ?? null;

                if ($includeTransitTimes && $originPostalCode) {
                    $this->fetchTransitTimes($this->result, $originPostalCode);
                }
            } elseif ($this->result->validation_status === 'ambiguous') {
                Notification::make()
                    ->title('Address Ambiguous')
                    ->body('Multiple possible addresses found. Please review.')
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title('Address Invalid')
                    ->body('The address could not be validated.')
                    ->danger()
                    ->send();
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('Validation Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Fetch transit times for an address.
     */
    public function fetchTransitTimes(Address $address, string $originPostalCode): void
    {
        $this->isLoadingTransitTimes = true;

        try {
            $fedexCarrier = Carrier::where('slug', 'fedex')->where('is_active', true)->first();

            if (! $fedexCarrier) {
                Notification::make()
                    ->title('Transit Times Unavailable')
                    ->body('FedEx carrier is not configured or inactive.')
                    ->warning()
                    ->send();

                return;
            }

            $transitService = new FedExServiceAvailabilityService($fedexCarrier);
            $this->transitTimes = $transitService->getTransitTimes($address, $originPostalCode);

            if ($this->transitTimes->isNotEmpty()) {
                Notification::make()
                    ->title('Transit Times Retrieved')
                    ->body('Found '.$this->transitTimes->count().' shipping options.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('No Transit Times')
                    ->body('No shipping options available for this route.')
                    ->warning()
                    ->send();
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('Transit Times Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isLoadingTransitTimes = false;
        }
    }

    /**
     * Manually refresh transit times.
     */
    public function refreshTransitTimes(): void
    {
        if (! $this->result) {
            return;
        }

        $originPostalCode = $this->data['origin_postal_code'] ?? null;

        if (! $originPostalCode) {
            Notification::make()
                ->title('Origin Required')
                ->body('Please enter an origin ZIP code.')
                ->warning()
                ->send();

            return;
        }

        $this->fetchTransitTimes($this->result, $originPostalCode);
    }

    public function clearResult(): void
    {
        $this->result = null;
        $this->showCandidatesModal = false;
        $this->selectedCandidateIndex = 0;
        $this->transitTimes = null;
        $this->isLoadingTransitTimes = false;
    }

    public function openCandidatesModal(): void
    {
        $this->showCandidatesModal = true;
    }

    public function closeCandidatesModal(): void
    {
        $this->showCandidatesModal = false;
    }

    public function selectCandidate(int $index): void
    {
        $this->selectedCandidateIndex = $index;
        $this->showCandidatesModal = false;

        // Note: Candidate selection is a future feature
        // With the denormalized schema, we'd need to store candidates differently
        Notification::make()
            ->title('Candidate Selected')
            ->body('Candidate '.($index + 1).' selected.')
            ->success()
            ->send();
    }

    public function getAllCandidates(): array
    {
        // TODO: Implement candidate storage if needed
        return [];
    }

    /**
     * Get the transit time for the selected ship method.
     */
    public function getSelectedTransitTime(): ?TransitTime
    {
        $shipViaCodeId = $this->data['ship_via_code_id'] ?? null;

        if (! $shipViaCodeId || ! $this->transitTimes || $this->transitTimes->isEmpty()) {
            return null;
        }

        $shipViaCode = ShipViaCode::find($shipViaCodeId);

        if (! $shipViaCode || ! $shipViaCode->service_type) {
            return null;
        }

        return $this->transitTimes->firstWhere('service_type', $shipViaCode->service_type);
    }

    /**
     * Get available ship methods with their transit times.
     *
     * @return array<int, array{ship_via_code: ShipViaCode, transit_time: ?TransitTime}>
     */
    public function getShipMethodsWithTransitTimes(): array
    {
        if (! $this->transitTimes || $this->transitTimes->isEmpty()) {
            return [];
        }

        $shipViaCodes = ShipViaCode::where('is_active', true)
            ->whereNotNull('service_type')
            ->with('carrier')
            ->get();

        $result = [];

        foreach ($shipViaCodes as $code) {
            $transitTime = $this->transitTimes->firstWhere('service_type', $code->service_type);

            if ($transitTime) {
                $result[] = [
                    'ship_via_code' => $code,
                    'transit_time' => $transitTime,
                ];
            }
        }

        return $result;
    }

    /**
     * Check if the result address is valid.
     */
    public function isResultValid(): bool
    {
        return $this->result?->validation_status === 'valid';
    }

    /**
     * Check if the result address is ambiguous.
     */
    public function isResultAmbiguous(): bool
    {
        return $this->result?->validation_status === 'ambiguous';
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('validate')
                ->label('Validate Address')
                ->action('validate_address')
                ->icon('heroicon-o-check-badge')
                ->color('primary'),
            Action::make('clear')
                ->label('Clear')
                ->action('clearResult')
                ->icon('heroicon-o-x-mark')
                ->color('gray'),
        ];
    }
}
