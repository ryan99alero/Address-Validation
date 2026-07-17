<?php

use App\Filament\Pages\CompanySetup;
use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\ListCarrierAccounts;
use App\Filament\Resources\Plants\Pages\ListPlants;
use App\Filament\Resources\ShipViaCodes\Pages\CreateShipViaCode;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::query()->delete();
    CompanySetting::create(['company_name' => 'RAND Graphics']);
    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    CarrierAccount::create(['carrier_id' => $carrier->id, 'account_number' => '111', 'nickname' => 'Plant002 Ground']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('renders the new admin pages without config errors', function () {
    Livewire::test(ListCarrierAccounts::class)->assertOk();
    Livewire::test(CreateCarrierAccount::class)->assertOk();
    Livewire::test(ListPlants::class)->assertOk();
    Livewire::test(CreateShipViaCode::class)->assertOk(); // exercises the account/plant dropdowns
    Livewire::test(CompanySetup::class)->assertOk();       // exercises the Carrier Accounts summary + link
});
