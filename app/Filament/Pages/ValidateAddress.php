<?php

namespace App\Filament\Pages;

use App\Models\Address;
use App\Models\AddressCorrection;
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

    public ?AddressCorrection $result = null;

    public ?Address $savedAddress = null;

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
            'country_code' => 'US',
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
                        TextInput::make('name')
                            ->label('Recipient Name')
                            ->maxLength(255),
                        TextInput::make('company')
                            ->label('Company')
                            ->maxLength(255),
                        TextInput::make('address_line_1')
                            ->label('Address Line 1')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('address_line_2')
                            ->label('Address Line 2')
                            ->maxLength(255),
                        TextInput::make('city')
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
            'name' => $data['name'] ?? null,
            'company' => $data['company'] ?? null,
            'address_line_1' => $data['address_line_1'],
            'address_line_2' => $data['address_line_2'] ?? null,
            'city' => $data['city'],
            'state' => $data['state'],
            'postal_code' => $data['postal_code'],
            'country_code' => $data['country_code'],
            'source' => 'manual',
            'created_by' => auth()->id(),
        ]);

        try {
            $service = app(AddressValidationService::class);
            $correction = $service->validateAddress($address, $carrier->slug);

            $this->savedAddress = $address;
            $this->result = $correction;
            $this->transitTimes = null;

            if ($correction->isValid()) {
                Notification::make()
                    ->title('Address Validated')
                    ->body('The address has been validated successfully.')
                    ->success()
                    ->send();

                // Fetch transit times if enabled and address is valid
                $includeTransitTimes = $data['include_transit_times'] ?? false;
                $originPostalCode = $data['origin_postal_code'] ?? null;

                if ($includeTransitTimes && $originPostalCode) {
                    $this->fetchTransitTimes($address, $originPostalCode);
                }
            } elseif ($correction->isAmbiguous()) {
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
        if (! $this->savedAddress) {
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

        $this->fetchTransitTimes($this->savedAddress, $originPostalCode);
    }

    public function clearResult(): void
    {
        $this->result = null;
        $this->savedAddress = null;
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

        // Update the displayed result with the selected candidate
        $candidates = $this->result->getAllCandidates();
        if (isset($candidates[$index])) {
            $candidate = $candidates[$index];

            // Update correction fields with selected candidate
            $this->result->corrected_address_line_1 = $candidate['address_line_1'];
            $this->result->corrected_address_line_2 = $candidate['address_line_2'];
            $this->result->corrected_city = $candidate['city'];
            $this->result->corrected_state = $candidate['state'];
            $this->result->corrected_postal_code = $candidate['postal_code'];
            $this->result->corrected_postal_code_ext = $candidate['postal_code_ext'];
            $this->result->corrected_country_code = $candidate['country_code'];
            $this->result->classification = $candidate['classification'];
            $this->result->confidence_score = $candidate['confidence'];

            // Persist the changes
            $this->result->save();

            Notification::make()
                ->title('Candidate Selected')
                ->body('Updated to candidate '.($index + 1))
                ->success()
                ->send();
        }
    }

    public function getAllCandidates(): array
    {
        return $this->result?->getAllCandidates() ?? [];
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
