<?php

use App\Filament\Pages\CarrierChargeCatalog;
use App\Filament\Resources\CarrierChargeTypes\Pages\CreateCarrierChargeType;
use App\Filament\Resources\CarrierChargeTypes\Pages\ListCarrierChargeTypes;
use App\Jobs\RecategorizeChargesJob;
use App\Models\Carrier;
use App\Models\CarrierChargeType;
use App\Models\ChargeCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $this->fuel = ChargeCategory::create(['name' => 'Fuel Surcharge', 'abbreviation' => 'FUEL']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

test('the crosswalk list renders with its carrier tabs', function () {
    CarrierChargeType::create(['carrier_id' => $this->ups->id, 'display_name' => 'Ground Fuel', 'csv_label' => 'Ground Fuel', 'charge_category_id' => $this->fuel->id]);

    Livewire::test(ListCarrierChargeTypes::class)
        ->assertOk()
        ->assertSee('Ground Fuel')
        ->assertSee('UPS')
        ->assertSee('Generic');
});

test('an operator can create a crosswalk row and it re-applies to existing charges', function () {
    Queue::fake();

    Livewire::test(CreateCarrierChargeType::class)
        ->fillForm([
            'carrier_id' => $this->ups->id,
            'display_name' => 'Residential Surcharge',
            'csv_label' => 'Residential Surcharge',
            'charge_category_id' => $this->fuel->id,
            'match_style' => CarrierChargeType::MATCH_EXACT,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CarrierChargeType::where('display_name', 'Residential Surcharge')->exists())->toBeTrue();
    Queue::assertPushed(RecategorizeChargesJob::class);
});

test('the catalog surfaces a Map action next to charges', function () {
    $invoiceId = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $this->ups->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_charges')->insert([
        'carrier_id' => $this->ups->id, 'carrier_invoice_id' => $invoiceId,
        'raw_charge_description' => 'Girth Charge', 'source_type' => 'pdf',
        'charge_category_id' => null, 'amount' => 9.00, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(CarrierChargeCatalog::class)
        ->assertOk()
        ->assertSee('Girth Charge')
        ->assertTableActionExists('map');
});

test('mapping a pdf charge creates a pdf-scoped crosswalk row and re-applies it', function () {
    Queue::fake();

    $type = CarrierChargeType::mapCharge($this->ups->id, 'Girth Charge', isPdf: true, displayName: 'Girth Charge', categoryId: $this->fuel->id);

    expect($type->pdf_label)->toBe('Girth Charge')
        ->and($type->csv_label)->toBeNull()
        ->and($type->charge_category_id)->toBe($this->fuel->id);
    Queue::assertPushed(RecategorizeChargesJob::class);
});

test('remapping the same charge updates the existing row rather than duplicating', function () {
    $first = CarrierChargeType::mapCharge($this->ups->id, 'Girth Charge', isPdf: true, displayName: 'Girth Charge', categoryId: null);
    $second = CarrierChargeType::mapCharge($this->ups->id, 'Girth Charge', isPdf: true, displayName: 'Girth Charge', categoryId: $this->fuel->id);

    expect($second->id)->toBe($first->id)
        ->and(CarrierChargeType::where('pdf_label', 'Girth Charge')->count())->toBe(1)
        ->and($second->charge_category_id)->toBe($this->fuel->id);
});
