<?php

use App\Filament\Pages\CarrierComparison;
use App\Filament\Pages\CarrierFeeSummary;
use App\Filament\Resources\Addresses\Pages\ListAddresses;
use App\Filament\Resources\CarrierCharges\Pages\ListCarrierCharges;
use App\Filament\Resources\CarrierInvoices\Pages\ListCarrierInvoices;
use App\Filament\Resources\Carriers\Pages\ListCarriers;
use App\Filament\Resources\CarrierShipmentSummaries\Pages\ListCarrierShipmentSummaries;
use App\Filament\Resources\ChargeCategories\Pages\ListChargeCategories;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(fn () => actingAs(User::factory()->create(['is_admin' => true])));

it('renders diverse existing grids with the global CSV actions', function () {
    $pages = [
        ListCarriers::class,
        ListAddresses::class,
        ListCarrierCharges::class,
        ListCarrierInvoices::class,
        ListCarrierShipmentSummaries::class,
        ListChargeCategories::class,
    ];
    foreach ($pages as $page) {
        Livewire::test($page)->assertOk();
    }
})->group('broadrender');

it('renders collection-backed report pages (CSV actions correctly hidden)', function () {
    Livewire::test(CarrierFeeSummary::class)->assertOk();
    Livewire::test(CarrierComparison::class)->assertOk();
})->group('broadrender');
