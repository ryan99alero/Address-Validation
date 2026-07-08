<?php

use App\Filament\Resources\CarrierCharges\Pages\ListCarrierCharges;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the Adjustments list renders with its tabs and catalog action', function () {
    $carrier = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $catId = DB::table('charge_categories')->insertGetId(['name' => 'Fuel Surcharge', 'abbreviation' => 'FUEL', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $invoiceId = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_charges')->insert([
        ['carrier_id' => $carrier->id, 'carrier_invoice_id' => $invoiceId, 'raw_charge_description' => 'Fuel Surcharge', 'charge_category_id' => $catId, 'amount' => 3.29, 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => $carrier->id, 'carrier_invoice_id' => $invoiceId, 'raw_charge_description' => 'Some Unmapped Fee', 'charge_category_id' => null, 'amount' => 5.00, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(ListCarrierCharges::class)
        ->assertOk()                 // loads the real Filament Tab class — catches the namespace 500
        ->assertSee('Fuel Surcharge')
        ->assertSee('Uncategorized'); // the tab label renders
});
