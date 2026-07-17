<?php

namespace App\Filament\Resources\ShipViaCodes\Schemas;

use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Plant;
use App\Models\ShipViaCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShipViaCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Code Mapping')
                    ->description('Map codes to carrier services. All codes below will resolve to this service.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Your Code')
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->placeholder('e.g., 5137')
                            ->helperText('Your internal shipping code (optional)'),
                        TextInput::make('carrier_code')
                            ->label('Primary Carrier Code')
                            ->maxLength(20)
                            ->placeholder('e.g., FDG, GND, 03')
                            ->helperText('Standard carrier abbreviation'),
                        TagsInput::make('alternate_codes')
                            ->label('Alternate Codes')
                            ->placeholder('Add codes and press Enter')
                            ->helperText('Other codes that should match this service (e.g., FDXG, GROUND)')
                            ->columnSpanFull(),
                        TextInput::make('service_name')
                            ->label('Display Name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('e.g., FedEx Ground')
                            ->helperText('Human-readable service name'),
                        TextInput::make('description')
                            ->label('Description')
                            ->maxLength(255)
                            ->placeholder('Optional notes'),
                    ]),

                Section::make('Carrier Service')
                    ->description('Link to a carrier for transit time lookups.')
                    ->columns(2)
                    ->schema([
                        Select::make('carrier_id')
                            ->label('Carrier')
                            ->options(Carrier::where('is_active', true)->pluck('name', 'id'))
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('service_type', null))
                            ->helperText('Select the carrier for transit time lookups'),
                        Select::make('service_type')
                            ->label('Service Type')
                            ->options(function (callable $get) {
                                $carrierId = $get('carrier_id');
                                if (! $carrierId) {
                                    return [];
                                }

                                $carrier = Carrier::find($carrierId);
                                if (! $carrier) {
                                    return [];
                                }

                                return ShipViaCode::getServiceTypesForCarrier($carrier->slug);
                            })
                            ->searchable()
                            ->helperText('API service type for transit time lookup'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive codes will not be used for lookups')
                            ->columnSpanFull(),
                    ]),

                Section::make('BestWay Configuration')
                    ->description('Configure plant, payment type, and account for BestWay service matching.')
                    ->columns(3)
                    ->schema([
                        Select::make('plant_id')
                            ->label('Plant')
                            ->options(fn (): array => Plant::options())
                            ->searchable()
                            ->native(false)
                            ->helperText('Plant that ships this code.'),
                        Select::make('payment_type')
                            ->label('Payment Type')
                            ->options(ShipViaCode::getPaymentTypeOptions())
                            ->live()
                            ->helperText('Who pays for shipping'),
                        Select::make('carrier_account_id')
                            ->label('Billing Account')
                            ->options(fn (callable $get): array => self::accountOptions($get('carrier_id')))
                            ->searchable()
                            ->native(false)
                            ->visible(fn (callable $get) => $get('payment_type') === ShipViaCode::PAYMENT_SENDER)
                            ->helperText('The account billed (normally ours). BestWay pools by this account\'s owner. Manage accounts under Carrier Accounts.'),
                        Select::make('third_party_account_id')
                            ->label('3rd Party Account')
                            ->options(fn (callable $get): array => self::accountOptions($get('carrier_id')))
                            ->searchable()
                            ->native(false)
                            ->visible(fn (callable $get) => $get('payment_type') === ShipViaCode::PAYMENT_THIRD_PARTY)
                            ->helperText('The existing account billed on third-party shipments (normally the customer\'s own account).'),
                    ]),
            ]);
    }

    /**
     * Active carrier accounts for the chosen carrier, as id => "Nickname — #number (Owner)".
     *
     * @return array<int, string>
     */
    protected static function accountOptions(mixed $carrierId): array
    {
        return CarrierAccount::query()
            ->when($carrierId, fn ($q, $id) => $q->where('carrier_id', $id))
            ->where('is_active', true)
            ->with('owner')
            ->orderBy('nickname')
            ->get()
            ->mapWithKeys(fn (CarrierAccount $a): array => [
                $a->id => $a->nickname.' — #'.$a->account_number.' ('.(optional($a->owner)->name ?? 'needs owner').')',
            ])
            ->all();
    }
}
