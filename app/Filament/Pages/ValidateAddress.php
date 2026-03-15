<?php

namespace App\Filament\Pages;

use App\Models\Address;
use App\Models\AddressCorrection;
use App\Models\Carrier;
use App\Services\AddressValidationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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

            if ($correction->isValid()) {
                Notification::make()
                    ->title('Address Validated')
                    ->body('The address has been validated successfully.')
                    ->success()
                    ->send();
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

    public function clearResult(): void
    {
        $this->result = null;
        $this->savedAddress = null;
        $this->showCandidatesModal = false;
        $this->selectedCandidateIndex = 0;
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
